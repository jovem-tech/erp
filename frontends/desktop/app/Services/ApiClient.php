<?php

namespace App\Services;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Support\DesktopSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class ApiClient
{
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
        $response = $this->authenticatedRequest('post', '/auth/refresh');

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
     * @param array<string, mixed> $query
     */
    public function guestGet(string $uri, array $query = []): array
    {
        return $this->parseResponse(
            $this->retryRequest(fn() => $this->guestRequest('get', $uri, [], $query)),
            false
        );
    }

    public function post(string $uri, array $payload = []): array
    {
        return $this->parseResponse(
            $this->retryRequest(fn() => $this->authenticatedRequest('post', $uri, $payload))
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, array<int, UploadedFile>> $files
     */
    public function postMultipart(string $uri, array $payload = [], array $files = []): array
    {
        return $this->parseResponse(
            $this->retryRequest(fn() => $this->authenticatedMultipartRequest($uri, $payload, $files))
        );
    }

    public function put(string $uri, array $payload = []): array
    {
        return $this->parseResponse(
            $this->retryRequest(fn() => $this->authenticatedRequest('put', $uri, $payload))
        );
    }

    public function patch(string $uri, array $payload = []): array
    {
        return $this->parseResponse(
            $this->retryRequest(fn() => $this->authenticatedRequest('patch', $uri, $payload))
        );
    }

    public function delete(string $uri): array
    {
        return $this->parseResponse(
            $this->retryRequest(fn() => $this->authenticatedRequest('delete', $uri))
        );
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function download(string $uri): array
    {
        $response = $this->authenticatedRequest('get', $uri);

        if ($response->failed()) {
            $this->parseResponse($response);
        }

        return [
            'body' => $response->body(),
            'headers' => [
                'Content-Type' => (string) $response->header('Content-Type', 'application/octet-stream'),
                'Content-Disposition' => (string) $response->header('Content-Disposition', 'inline'),
            ],
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
            'headers' => [
                'Content-Type' => (string) $response->header('Content-Type', 'application/octet-stream'),
                'Content-Disposition' => (string) $response->header('Content-Disposition', 'inline'),
            ],
            'status' => $response->status(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $query
     */
    private function authenticatedRequest(string $method, string $uri, array $payload = [], array $query = []): Response
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
            if ($response->status() === 401) {
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
        } catch (ConnectionException) {
            throw new ApiRequestException('Nao foi possivel conectar ao backend central.');
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
        } catch (ConnectionException) {
            throw new ApiRequestException('Nao foi possivel conectar ao backend central.');
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
        } catch (ConnectionException) {
            throw new ApiRequestException('Nao foi possivel conectar ao backend central.');
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
     * Executa uma requisição com retry automático usando exponential backoff
     * 
     * Configuração:
     * - Máximo de tentativas: 3
     * - Delay inicial: 1 segundo
     * - Multiplica o delay por 2 a cada tentativa (1s, 2s, 4s)
     * 
     * Não faz retry em erros de autenticação (401, 403) ou validação (422)
     */
    private function retryRequest(callable $request, int $maxAttempts = 3, int $delayMs = 1000): Response
    {
        $lastException = null;
        $lastResponse = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $request();

                // Não fazer retry em erros de autenticação/autorização/validação
                if ($response->status() === 401 
                    || $response->status() === 403 
                    || $response->status() === 422) {
                    return $response;
                }

                // Se bem-sucedido, retornar
                if ($response->successful()) {
                    return $response;
                }

                // Erros 5xx podem ser retentados
                if ($response->serverError()) {
                    $lastResponse = $response;

                    if ($attempt < $maxAttempts) {
                        // Exponential backoff: 1s, 2s, 4s
                        $waitMs = $delayMs * (2 ** ($attempt - 1));
                        usleep($waitMs * 1000);
                        continue;
                    }
                }

                // Outros erros não são retentados
                return $response;

            } catch (ConnectionException $e) {
                $lastException = $e;

                if ($attempt < $maxAttempts) {
                    // Exponential backoff para ConnectionException
                    $waitMs = $delayMs * (2 ** ($attempt - 1));
                    usleep($waitMs * 1000);
                    continue;
                }
            }
        }

        // Se todas as tentativas falharam, retornar última resposta ou lançar exceção
        if ($lastResponse instanceof Response) {
            return $lastResponse;
        }

        if ($lastException instanceof ConnectionException) {
            throw new ApiRequestException('Nao foi possivel conectar ao backend central apos ' . $maxAttempts . ' tentativas.');
        }

        throw new ApiRequestException('Falha na requisicao apos ' . $maxAttempts . ' tentativas.');
    }

    private function url(string $uri): string
    {
        return rtrim((string) config('services.erp_api.base_url'), '/') . '/' . ltrim($uri, '/');
    }
}
