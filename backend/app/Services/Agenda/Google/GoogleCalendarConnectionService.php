<?php

namespace App\Services\Agenda\Google;

use App\Models\AgendaCompromisso;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Fluxo de consentimento OAuth e criacao do calendario dedicado.
 *
 * O redirect URI e fixo e derivado da URL publica da API porque o Google exige
 * correspondencia exata com o que esta cadastrado no Cloud Console.
 *
 * ATENCAO ao ambiente de bancada: o Google recusa IP privado como redirect URI,
 * entao `https://192.168.1.100:8443` nao pode ser cadastrado la. Em dev,
 * conecte pela producao ou use o campo "colar refresh token" da tela de
 * integracoes. Ver documentacao/07-novas-implementacoes.
 */
class GoogleCalendarConnectionService
{
    private const STATE_CACHE_PREFIX = 'agenda:google:oauth_state:';
    private const STATE_TTL_SECONDS = 900;

    public function __construct(
        private readonly GoogleCalendarClient $client,
        private readonly GoogleCalendarSettingsService $settings,
        private readonly GoogleCalendarPushService $push
    ) {}

    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/agenda/google/callback';
    }

    /** @return array{url: string, state: string} */
    public function authorizationUrl(): array
    {
        if (! $this->settings->hasCredentials()) {
            throw new RuntimeException('Preencha o Client ID e o Client Secret do Google antes de conectar.');
        }

        // State de uso unico: sem ele o callback aceitaria um `code` entregue
        // por qualquer origem (CSRF no fluxo OAuth).
        $state = Str::random(40);
        Cache::put(self::STATE_CACHE_PREFIX.$state, true, self::STATE_TTL_SECONDS);

        return [
            'url' => $this->client->authorizationUrl($this->redirectUri(), $state),
            'state' => $state,
        ];
    }

    /**
     * Troca o code pelo refresh token e garante o calendario dedicado.
     *
     * @return array<string, mixed>
     */
    public function completeAuthorization(string $code, string $state): array
    {
        $cacheKey = self::STATE_CACHE_PREFIX.$state;

        if ($state === '' || ! Cache::pull($cacheKey)) {
            throw new RuntimeException('Autorização inválida ou expirada. Refaça a conexão pelo painel.');
        }

        $tokens = $this->client->exchangeCode($code, $this->redirectUri());
        $refreshToken = trim((string) ($tokens['refresh_token'] ?? ''));

        if ($refreshToken === '') {
            // Acontece quando a conta ja autorizou este client antes: sem
            // prompt=consent o Google reaproveita a concessao e devolve so o
            // access token. O client sempre envia prompt=consent, entao chegar
            // aqui significa concessao parcial - melhor falhar visivelmente do
            // que gravar uma conexao que morre em uma hora.
            throw new RuntimeException(
                'O Google não devolveu um token de longa duração. Remova o acesso do app em myaccount.google.com/permissions e conecte novamente.'
            );
        }

        $this->settings->put('agenda_google_refresh_token', $refreshToken);
        $this->client->forgetAccessToken();

        $accessToken = trim((string) ($tokens['access_token'] ?? ''));
        if ($accessToken !== '') {
            $this->storeAccountEmail($this->client->accountEmail($accessToken));
        }

        $this->ensureCalendar();
        $this->settings->put('agenda_google_conectado_em', now()->toDateTimeString());

        // Tudo que foi criado enquanto a conexao estava desligada sobe agora.
        $this->push->pushPending();

        return $this->settings->payload();
    }

    /**
     * E-mail da conta Google conectada, buscando uma unica vez quando faltar.
     *
     * A captura na hora de conectar pode falhar em silencio - erro transitorio
     * de rede, ou consentimento em que o usuario desmarcou o escopo de e-mail.
     * Sem esta recuperacao, a tela mostraria "-" para sempre e a unica saida
     * seria desconectar e refazer todo o consentimento so para descobrir de
     * qual conta se trata.
     *
     * Nunca lanca: saber o e-mail e conveniencia, nao pode derrubar a tela de
     * integracoes nem a agenda.
     */
    public function resolveAccountEmail(): string
    {
        $email = $this->settings->get('agenda_google_conta_email');

        if ($email !== '' || ! $this->settings->isConnected()) {
            return $email;
        }

        try {
            return $this->storeAccountEmail($this->client->accountEmail($this->client->accessToken()));
        } catch (Throwable $exception) {
            report($exception);

            return '';
        }
    }

    private function storeAccountEmail(string $email): string
    {
        $email = trim($email);

        // Nao grava vazio por cima de um e-mail ja conhecido: uma falha
        // pontual apagaria a informacao boa que ja estava la.
        if ($email !== '') {
            $this->settings->put('agenda_google_conta_email', $email);
        }

        return $email;
    }

    /**
     * Cria o calendario dedicado do ERP se ainda nao houver um.
     *
     * Este e o mecanismo que cumpre a regra "o ERP nunca le a agenda pessoal":
     * o app so tem acesso a calendarios que ele mesmo criou, e este e o unico.
     */
    public function ensureCalendar(): string
    {
        $existing = $this->settings->get('agenda_google_calendar_id');

        if ($existing !== '') {
            return $existing;
        }

        $calendar = $this->client->createCalendar(
            GoogleCalendarSettingsService::CALENDAR_SUMMARY,
            (string) config('app.timezone', 'America/Sao_Paulo')
        );

        $calendarId = trim((string) ($calendar['id'] ?? ''));

        if ($calendarId === '') {
            throw new RuntimeException('O Google não devolveu o identificador do calendário criado.');
        }

        $this->settings->put('agenda_google_calendar_id', $calendarId);
        // Calendario novo comeca sem historico de sincronizacao.
        $this->settings->put('agenda_google_sync_token', '');

        return $calendarId;
    }

    /**
     * Saida de emergencia para o ambiente de bancada, onde o Google recusa o IP
     * privado como redirect URI: o refresh token obtido por fora e colado a mao.
     *
     * @return array<string, mixed>
     */
    public function connectWithRefreshToken(string $refreshToken): array
    {
        $refreshToken = trim($refreshToken);

        if ($refreshToken === '') {
            throw new RuntimeException('Informe o refresh token.');
        }

        if (! $this->settings->hasCredentials()) {
            throw new RuntimeException('Preencha o Client ID e o Client Secret do Google antes de conectar.');
        }

        $this->settings->put('agenda_google_refresh_token', $refreshToken);
        $this->client->forgetAccessToken();

        // Falha cedo se o token colado nao servir, em vez de deixar a tela
        // dizendo "conectado" e o sync morrer silenciosamente.
        $accessToken = $this->client->accessToken();
        $this->storeAccountEmail($this->client->accountEmail($accessToken));

        $this->ensureCalendar();
        $this->settings->put('agenda_google_conectado_em', now()->toDateTimeString());
        $this->push->pushPending();

        return $this->settings->payload();
    }

    /**
     * Desconecta no ERP. O calendario e os eventos permanecem na conta Google -
     * apaga-los seria destruir dados do usuario a partir de um clique cujo
     * texto diz apenas "desconectar".
     *
     * @return array<string, mixed>
     */
    public function disconnect(): array
    {
        $this->settings->disconnect();
        $this->client->forgetAccessToken();

        // Reconectar cria um calendario novo, onde os ids de evento antigos nao
        // existem. Limpar agora evita uma onda de 404 no primeiro sync e o
        // risco de dar patch num evento de outro calendario.
        AgendaCompromisso::query()
            ->whereNotNull('google_event_id')
            ->update([
                'google_event_id' => null,
                'google_etag' => null,
                'google_sync_hash' => null,
                'google_sync_estado' => AgendaCompromisso::SYNC_DESLIGADO,
                'google_sincronizado_em' => null,
            ]);

        return $this->settings->payload();
    }
}
