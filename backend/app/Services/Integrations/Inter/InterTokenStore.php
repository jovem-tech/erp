<?php

namespace App\Services\Integrations\Inter;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Obtem e cacheia o token OAuth2 (client_credentials) do Banco Inter.
 *
 * Duas coisas que este cache resolve, alem de economizar chamada:
 *
 * 1. **Corrida entre workers.** Producao roda 2 processos `queue:work` do
 *    Supervisor mais o efemero do scheduler. Sem lock, tres processos pedem
 *    token ao mesmo tempo no primeiro job apos a expiracao.
 * 2. **Token que expira no meio do voo.** O Inter devolve ~3600s de validade;
 *    guardamos por 3000s de proposito, para nunca usar um token nos ultimos
 *    minutos de vida.
 *
 * A chave inclui ambiente, escopos e um hash do client_id: trocar sandbox por
 * producao, rotacionar credencial ou pedir outro escopo nunca reaproveita
 * token velho.
 */
class InterTokenStore
{
    public function __construct(
        private readonly InterCredentials $credentials,
    ) {
    }

    /**
     * @param  array<int, string>  $escopos
     *
     * @throws InterException
     */
    public function token(array $escopos): string
    {
        $this->credentials->assertUsavel();

        $escopos = $this->normalizarEscopos($escopos);
        $chave = $this->cacheKey($escopos);

        $token = Cache::get($chave);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $lock = Cache::lock($chave.':lock', (int) config('inter.token.lock_segundos', 10));

        try {
            // block(): quem perder a corrida espera o vencedor gravar e le' do
            // cache, em vez de disparar uma segunda chamada ao banco.
            $lock->block((int) config('inter.token.lock_segundos', 10));

            $token = Cache::get($chave);

            if (is_string($token) && $token !== '') {
                return $token;
            }

            $token = $this->solicitarToken($escopos);

            Cache::put($chave, $token, (int) config('inter.token.ttl_segundos', 3000));

            return $token;
        } finally {
            $lock->release();
        }
    }

    /**
     * Descarta o token cacheado. Usado quando o banco responde 401 — pode ser
     * revogacao no meio da validade, e insistir com o mesmo token so' repete o erro.
     *
     * @param  array<int, string>  $escopos
     */
    public function esquecer(array $escopos): void
    {
        Cache::forget($this->cacheKey($this->normalizarEscopos($escopos)));
    }

    /**
     * @param  array<int, string>  $escopos
     *
     * @throws InterException
     */
    private function solicitarToken(array $escopos): string
    {
        $url = $this->credentials->baseUrl().'/oauth/v2/token';

        try {
            $response = Http::asForm()
                ->withOptions($this->credentials->guzzleTlsOptions())
                ->timeout((int) config('inter.http.timeout', 20))
                ->connectTimeout((int) config('inter.http.connect_timeout', 10))
                ->post($url, [
                    'client_id' => $this->credentials->clientId(),
                    'client_secret' => $this->credentials->clientSecret(),
                    'grant_type' => 'client_credentials',
                    'scope' => implode(' ', $escopos),
                ]);
        } catch (Throwable $e) {
            // Handshake mTLS quebrado cai aqui (cert vencido, chave errada,
            // passphrase incorreta). A mensagem do cURL e' o unico sinal util.
            throw new InterException(
                'Falha ao autenticar no Banco Inter: '.$e->getMessage(),
                null,
                ['escopos' => $escopos],
                $e
            );
        }

        if (! $response->successful()) {
            Log::channel('pagamentos')->error('[INTER] Falha ao obter token OAuth2.', [
                'status' => $response->status(),
                'escopos' => $escopos,
                // Corpo do erro do Inter nao carrega segredo, mas truncamos
                // por seguranca e para nao inundar o log.
                'corpo' => mb_substr($response->body(), 0, 500),
            ]);

            throw new InterException(
                'Banco Inter recusou a autenticacao (HTTP '.$response->status().').',
                $response->status(),
                ['escopos' => $escopos]
            );
        }

        $token = trim((string) ($response->json('access_token') ?? ''));

        if ($token === '') {
            throw new InterException(
                'Banco Inter respondeu sem access_token.',
                $response->status(),
                ['escopos' => $escopos]
            );
        }

        Log::channel('pagamentos')->info('[INTER] Token OAuth2 renovado.', [
            'escopos' => $escopos,
            'ambiente' => $this->credentials->ambiente(),
            'expires_in' => $response->json('expires_in'),
        ]);

        return $token;
    }

    /**
     * @param  array<int, string>  $escopos
     * @return array<int, string>
     */
    private function normalizarEscopos(array $escopos): array
    {
        $escopos = array_values(array_unique(array_filter(array_map(
            static fn ($escopo): string => trim((string) $escopo),
            $escopos
        ))));

        sort($escopos);

        return $escopos;
    }

    /**
     * @param  array<int, string>  $escopos
     */
    private function cacheKey(array $escopos): string
    {
        return 'inter:token:'.sha1(implode('|', [
            $this->credentials->ambiente(),
            $this->credentials->baseUrl(),
            $this->credentials->clientId(),
            implode(' ', $escopos),
        ]));
    }
}
