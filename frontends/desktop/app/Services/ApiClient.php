<?php

namespace App\Services;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiConnectionException;
use App\Exceptions\ApiRequestException;
use App\Support\DesktopSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class ApiClient
{
    /**
     * Duas tentativas, nao tres: a segunda cobre o soluco de rede pontual, e a
     * terceira so' prolongava a ocupacao do worker e triplicava a carga sobre um
     * backend que ja estava em dificuldade.
     */
    private const RETRY_MAX_ATTEMPTS = 2;

    private const RETRY_BASE_DELAY_MS = 250;

    private const RETRY_MAX_DELAY_MS = 1000;

    public function login(string $email, string $password, string $deviceName): array
    {
        $response = $this->guestRequest('post', '/auth/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => $deviceName,
        ]);

        return $this->parseResponse($response, false);
    }

    public function requestPasswordResetLink(string $email): array
    {
        $response = $this->guestRequest('post', '/auth/password/forgot', [
            'email' => $email,
        ]);

        return $this->parseResponse($response, false);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function resetPassword(array $payload): array
    {
        $response = $this->guestRequest('post', '/auth/password/reset', $payload);

        return $this->parseResponse($response, false);
    }

    public function logout(): array
    {
        $response = $this->authenticatedRequest('post', '/auth/logout');

        return $this->parseResponse($response);
    }

    /**
     * Renovar o token de acesso
     * 
     * Chamado quando o token está próximo de expirar ou quando uma requisição retorna 401
     */
    public function refreshToken(): array
    {
        // allowRefresh: false quebra a recursão: se o token já está
        // realmente inválido/revogado, /auth/refresh também responde 401
        // (exige um Bearer Sanctum válido) — sem essa guarda,
        // authenticatedRequest() chamaria refreshToken() de novo, que
        // chamaria authenticatedRequest('/auth/refresh') de novo, ad
        // infinitum, até o throttle:60,1 da rota cortar o loop.
        $response = $this->authenticatedRequest('post', '/auth/refresh', allowRefresh: false);

        $payload = $response->json();

        if ($response->successful() && isset($payload['data']['access_token'])) {
            DesktopSession::storeToken($payload['data']['access_token']);
            DesktopSession::storeExpiresAt($payload['data']['expires_at'] ?? null);

            return $this->parseResponse($response);
        }

        // Se refresh falhar, limpar sessão
        DesktopSession::forget();

        throw new ApiAuthenticationException('Nao foi possivel renovar o token. Faca login novamente.');
    }

    public function me(): array
    {
        $response = $this->authenticatedRequest('get', '/auth/me');

        return $this->parseResponse($response);
    }

    public function get(string $uri, array $query = []): array
    {
        return $this->parseResponse(
            $this->retryRequest(fn() => $this->authenticatedRequest('get', $uri, [], $query))
        );
    }

    /**
     * Leitura publica (sem token). NAO retenta por padrao.
     *
     * Os dois usos sao a marca da empresa — resolvida em TODA renderizacao de
     * view, com fallback proprio quando falha — e a pagina publica de
     * assinatura. Retentar a marca sairia caro justamente no cenario ruim: com
     * a API degradada, cada pagina do sistema pagaria duas chamadas perdidas
     * mais o backoff, para no fim exibir o mesmo fallback. Falhar rapido e cair
     * no padrao e' melhor do que insistir por um dado decorativo.
     *
     * @param array<string, mixed> $query
     */
    public function guestGet(string $uri, array $query = [], bool $retry = false): array
    {
        $send = fn (): Response => $this->guestRequest('get', $uri, [], $query);

        return $this->parseResponse(
            $retry ? $this->retryRequest($send) : $send(),
            false
        );
    }

    /** @param array<string, mixed> $payload */
    public function guestPost(string $uri, array $payload = []): array
    {
        return $this->parseResponse(
            $this->guestRequest('post', $uri, $payload),
            false
        );
    }

    public function post(string $uri, array $payload = []): array
    {
        return $this->parseResponse(
            $this->authenticatedRequest('post', $uri, $payload)
        );
    }

    /**
     * POST sem repetição automática para comandos mutáveis que não possuem
     * chave de idempotência. Evita executar a mesma ação mais de uma vez quando
     * a API conclui o comando, mas a resposta falha ou chega como HTTP 5xx.
     */
    public function postOnce(string $uri, array $payload = []): array
    {
        return $this->parseResponse(
            $this->authenticatedRequest('post', $uri, $payload)
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, array<int, UploadedFile>> $files
     */
    public function postMultipart(string $uri, array $payload = [], array $files = []): array
    {
        return $this->parseResponse(
            $this->authenticatedMultipartRequest($uri, $payload, $files)
        );
    }

    public function put(string $uri, array $payload = []): array
    {
        return $this->parseResponse(
            $this->authenticatedRequest('put', $uri, $payload)
        );
    }

    public function patch(string $uri, array $payload = []): array
    {
        return $this->parseResponse(
            $this->authenticatedRequest('patch', $uri, $payload)
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function delete(string $uri, array $payload = []): array
    {
        return $this->parseResponse(
            $this->authenticatedRequest('delete', $uri, $payload)
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function download(string $uri, array $query = []): array
    {
        $response = $this->authenticatedRequest('get', $uri, [], $query);

        if ($response->failed()) {
            $this->parseResponse($response);
        }

        return [
            'body' => $response->body(),
            'headers' => $this->downloadHeaders($response),
            'status' => $response->status(),
        ];
    }

    /**
     * Download via POST (ex.: prévia de PDF do motor de templates, que envia
     * o schema no corpo e recebe bytes de PDF).
     *
     * @param array<string, mixed> $payload
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function postDownload(string $uri, array $payload = []): array
    {
        $response = $this->authenticatedRequest('post', $uri, $payload);

        if ($response->failed()) {
            $this->parseResponse($response);
        }

        return [
            'body' => $response->body(),
            'headers' => $this->downloadHeaders($response),
            'status' => $response->status(),
        ];
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function guestDownload(string $uri): array
    {
        $response = $this->guestRequest('get', $uri);

        if ($response->failed()) {
            $this->parseResponse($response, false);
        }

        return [
            'body' => $response->body(),
            'headers' => $this->downloadHeaders($response),
            'status' => $response->status(),
        ];
    }

    /**
     * Repassa tambem Cache-Control/Last-Modified do backend — sem isso, toda
     * imagem de marca (logo, fundo do login) chega ao navegador marcada
     * "no-cache, private" (padrao do Symfony ao ver sessao ativa) e e
     * rebaixada em toda navegacao dentro do sistema.
     *
     * @return array<string, string>
     */
    private function downloadHeaders(Response $response): array
    {
        $headers = [
            'Content-Type' => (string) $response->header('Content-Type', 'application/octet-stream'),
            'Content-Disposition' => (string) $response->header('Content-Disposition', 'inline'),
        ];

        foreach (['Cache-Control', 'Last-Modified', 'ETag'] as $header) {
            $value = $response->header($header);
            if ($value !== null && $value !== '') {
                $headers[$header] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $query
     * @param bool $allowRefresh Falso apenas na chamada interna de
     *     refreshToken() para POST /auth/refresh — impede que um 401 NESSA
     *     chamada (token já invalido/revogado, ja que /auth/refresh tambem
     *     exige um Bearer Sanctum valido) dispare outra tentativa de
     *     refresh, que chamaria authenticatedRequest('/auth/refresh') de
     *     novo, recursivamente, sem caso base.
     */
    private function authenticatedRequest(string $method, string $uri, array $payload = [], array $query = [], bool $allowRefresh = true): Response
    {
        $token = DesktopSession::token();

        if ($token === null) {
            throw new ApiAuthenticationException('Sua sessao expirou. Faca login novamente.');
        }

        try {
            $response = $this->baseRequest()
                ->withToken($token)
                ->send(strtoupper($method), $this->url($uri), [
                    'json' => $payload,
                    'query' => $query,
                ]);

            // Se receber 401 (Unauthorized), tentar fazer refresh do token
            if ($response->status() === 401 && $allowRefresh) {
                try {
                    $this->refreshToken();

                    // Retry com novo token
                    $newToken = DesktopSession::token();
                    if ($newToken !== null) {
                        return $this->baseRequest()
                            ->withToken($newToken)
                            ->send(strtoupper($method), $this->url($uri), [
                                'json' => $payload,
                                'query' => $query,
                            ]);
                    }
                } catch (ApiAuthenticationException) {
                    // Se refresh falhar, deixar o 401 passar para o parseResponse
                }
            }

            return $response;
        } catch (ConnectionException $exception) {
            throw new ApiConnectionException(
                'Nao foi possivel conectar ao backend central.',
                previous: $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function guestRequest(string $method, string $uri, array $payload = [], array $query = []): Response
    {
        try {
            return $this->baseRequest()
                ->send(strtoupper($method), $this->url($uri), [
                    'json' => $payload,
                    'query' => $query,
                ]);
        } catch (ConnectionException $exception) {
            throw new ApiConnectionException(
                'Nao foi possivel conectar ao backend central.',
                previous: $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, array<int, UploadedFile>> $files
     */
    private function authenticatedMultipartRequest(string $uri, array $payload = [], array $files = []): Response
    {
        $token = DesktopSession::token();

        if ($token === null) {
            throw new ApiAuthenticationException('Sua sessao expirou. Faca login novamente.');
        }

        try {
            $request = $this->baseMultipartRequest()->withToken($token);

            foreach ($files as $field => $items) {
                foreach ($items as $file) {
                    if (! $file instanceof UploadedFile) {
                        continue;
                    }

                    $request = $request->attach(
                        $field,
                        file_get_contents($file->getRealPath()) ?: '',
                        $file->getClientOriginalName(),
                        ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream']
                    );
                }
            }

            $response = $request->post($this->url($uri), $payload);

            if ($response->status() === 401) {
                try {
                    $this->refreshToken();

                    $newToken = DesktopSession::token();
                    if ($newToken !== null) {
                        $retryRequest = $this->baseMultipartRequest()->withToken($newToken);

                        foreach ($files as $field => $items) {
                            foreach ($items as $file) {
                                if (! $file instanceof UploadedFile) {
                                    continue;
                                }

                                $retryRequest = $retryRequest->attach(
                                    $field,
                                    file_get_contents($file->getRealPath()) ?: '',
                                    $file->getClientOriginalName(),
                                    ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream']
                                );
                            }
                        }

                        return $retryRequest->post($this->url($uri), $payload);
                    }
                } catch (ApiAuthenticationException) {
                    // deixa o parseResponse tratar o 401 final
                }
            }

            return $response;
        } catch (ConnectionException $exception) {
            throw new ApiConnectionException(
                'Nao foi possivel conectar ao backend central.',
                previous: $exception
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponse(Response $response, bool $clearAuthOnUnauthorized = true): array
    {
        $payload = $response->json();
        $message = $payload['error']['message'] ?? $payload['message'] ?? 'Falha ao comunicar com o backend central.';
        $details = $payload['error']['details'] ?? null;

        if ($response->status() === 401 && $clearAuthOnUnauthorized) {
            DesktopSession::forget();

            throw new ApiAuthenticationException((string) $message);
        }

        if ($response->status() === 403) {
            throw new ApiAuthorizationException((string) $message);
        }

        if ($response->failed()) {
            throw new ApiRequestException((string) $message, $response->status(), is_array($details) ? $details : null);
        }

        return is_array($payload) ? $payload : [];
    }

    private function baseRequest(): PendingRequest
    {
        $timeout = (int) config('services.erp_api.timeout', 15);
        $connectTimeout = min($timeout, 5);

        // Os limites usam apenas as opcoes nativas do Guzzle (timeout/connect_timeout),
        // que a propria lib traduz para CURLOPT_TIMEOUT_MS/CURLOPT_CONNECTTIMEOUT_MS.
        // Passar CURLOPT_* cru em withOptions(['curl' => ...]) esta deprecado no
        // Guzzle 7.11 e sera rejeitado no Guzzle 8.
        return Http::acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout($connectTimeout);
    }

    private function baseMultipartRequest(): PendingRequest
    {
        $timeout = (int) config('services.erp_api.timeout', 15);
        $connectTimeout = min($timeout, 5);

        // Multipart nao pode herdar asJson(), senão o corpo sai com boundary de form-data
        // mas o Content-Type continua application/json, o que quebra o parse do backend.
        return Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withOptions([
                'curl' => [
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                ],
            ]);
    }

    /**
     * Reexecuta a requisicao apenas quando reexecutar e' seguro.
     *
     * O criterio nao e' "deu erro", e' "repetir isto pode causar dano?":
     *
     * - Falha de transporte (ApiConnectionException) e' retentada, porque nesse
     *   caso nao ha garantia de que o backend chegou a receber o comando... mas
     *   so' para leituras. Antes este ramo era CODIGO MORTO: authenticatedRequest()
     *   e irmas ja convertiam ConnectionException em ApiRequestException, que o
     *   laco nao capturava — entao a unica retentativa que existia de fato era a
     *   de 5xx, exatamente a que nao deveria existir para comandos mutaveis.
     * - HTTP 5xx e' retentado so' em leitura. Num POST/PUT/DELETE o backend pode
     *   ter concluido o efeito e falhado depois (ao serializar a resposta, ao
     *   enviar notificacao), e repetir duplicaria o efeito.
     * - 401/403/422 nunca sao retentados: a resposta nao muda por insistencia.
     *
     * O backoff e' curto e com jitter de proposito. Ele existe para absorver um
     * soluco de rede, nao para esperar o backend terminar um trabalho longo —
     * cada usleep() aqui congela um worker do PHP-FPM, e o pool do desktop e'
     * pequeno (pm.max_children). Sem jitter, varios workers que falharam no
     * mesmo instante voltariam juntos e bateriam no backend em rajada.
     */
    private function retryRequest(callable $request, int $maxAttempts = self::RETRY_MAX_ATTEMPTS): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $request();

                if ($attempt >= $maxAttempts || ! $response->serverError()) {
                    return $response;
                }
            } catch (ApiConnectionException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }
            }

            $this->sleepBeforeRetry($attempt);
        }
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        $delayMs = min(
            self::RETRY_BASE_DELAY_MS * (2 ** ($attempt - 1)),
            self::RETRY_MAX_DELAY_MS
        );

        usleep(($delayMs + random_int(0, self::RETRY_BASE_DELAY_MS)) * 1000);
    }

    private function url(string $uri): string
    {
        return rtrim((string) config('services.erp_api.base_url'), '/') . '/' . ltrim($uri, '/');
    }
}
