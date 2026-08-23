<?php

namespace App\Services\Integrations\Inter;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente HTTP do Banco Inter: mTLS + Bearer, sem regra de negocio.
 *
 * Toda chamada ao banco passa por aqui, para que o certificado, o token, os
 * timeouts e o header x-conta-corrente tenham um unico lugar. Quem consome
 * (saldo, extrato, cobranca) so' conhece caminho + escopo.
 *
 * O que este cliente NAO faz de proposito: decidir se um pagamento e' valido.
 * Ele devolve o que o banco disse; a decisao fica no servico de dominio.
 */
class InterClient
{
    public function __construct(
        private readonly InterCredentials $credentials,
        private readonly InterTokenStore $tokens,
    ) {
    }

    /**
     * @param  array<int, string>  $escopos
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $escopos, array $query = []): array
    {
        return $this->request('GET', $path, $escopos, query: $query);
    }

    /**
     * @param  array<int, string>  $escopos
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $escopos, array $payload = []): array
    {
        return $this->request('POST', $path, $escopos, payload: $payload);
    }

    /**
     * @param  array<int, string>  $escopos
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function put(string $path, array $escopos, array $payload = []): array
    {
        return $this->request('PUT', $path, $escopos, payload: $payload);
    }

    /**
     * @param  array<int, string>  $escopos
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws InterException
     */
    private function request(
        string $metodo,
        string $path,
        array $escopos,
        array $query = [],
        array $payload = [],
        bool $jaRenovouToken = false
    ): array {
        $this->credentials->assertUsavel();

        $url = $this->credentials->baseUrl().'/'.ltrim($path, '/');
        $token = $this->tokens->token($escopos);

        try {
            $request = Http::withToken($token)
                ->acceptJson()
                ->withOptions($this->credentials->guzzleTlsOptions())
                ->timeout((int) config('inter.http.timeout', 20))
                ->connectTimeout((int) config('inter.http.connect_timeout', 10))
                ->retry(
                    max(1, (int) config('inter.http.retries', 2)),
                    max(0, (int) config('inter.http.retry_delay_ms', 400)),
                    // So' repete o que e' seguro repetir: falha de rede ou 5xx.
                    // 4xx nao melhora com insistencia, e repetir POST de
                    // cobranca as cegas duplicaria emissao.
                    throw: false
                );

            $conta = $this->credentials->contaCorrente();

            if ($conta !== '') {
                $request = $request->withHeaders(['x-conta-corrente' => $conta]);
            }

            $response = match ($metodo) {
                'GET' => $request->get($url, $query),
                'POST' => $request->post($url, $payload),
                'PUT' => $request->put($url, $payload),
                default => throw new InterException("Metodo HTTP nao suportado: {$metodo}."),
            };
        } catch (InterException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::channel('pagamentos')->error('[INTER] Falha de transporte.', [
                'metodo' => $metodo,
                'path' => $path,
                'erro' => $e->getMessage(),
            ]);

            throw new InterException(
                'Falha de comunicacao com o Banco Inter: '.$e->getMessage(),
                null,
                ['metodo' => $metodo, 'path' => $path],
                $e
            );
        }

        // 401 no meio da validade = token revogado do outro lado. Descarta e
        // tenta UMA vez com token novo; sem o guard viraria laco infinito.
        if ($response->status() === 401 && ! $jaRenovouToken) {
            Log::channel('pagamentos')->warning('[INTER] Token recusado (401); renovando e repetindo uma vez.', [
                'path' => $path,
            ]);

            $this->tokens->esquecer($escopos);

            return $this->request($metodo, $path, $escopos, $query, $payload, jaRenovouToken: true);
        }

        if (! $response->successful()) {
            $this->logFalha($metodo, $path, $response);

            throw new InterException(
                $this->mensagemDeErro($response),
                $response->status(),
                ['metodo' => $metodo, 'path' => $path]
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function logFalha(string $metodo, string $path, Response $response): void
    {
        Log::channel('pagamentos')->error('[INTER] Resposta de erro.', [
            'metodo' => $metodo,
            'path' => $path,
            'status' => $response->status(),
            'corpo' => mb_substr($response->body(), 0, 1000),
        ]);
    }

    private function mensagemDeErro(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            foreach (['detail', 'title', 'message', 'error_description', 'error'] as $campo) {
                $valor = trim((string) ($json[$campo] ?? ''));

                if ($valor !== '') {
                    return 'Banco Inter: '.$valor.' (HTTP '.$response->status().')';
                }
            }
        }

        return 'Banco Inter respondeu HTTP '.$response->status().'.';
    }
}
