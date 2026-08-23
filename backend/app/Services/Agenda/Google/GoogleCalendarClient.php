<?php

namespace App\Services\Agenda\Google;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente HTTP da Google Calendar API v3.
 *
 * REST direto pela facade Http em vez de google/apiclient: sao seis endpoints,
 * e o SDK oficial traz um grafo grande de dependencias (guzzle, psr, cache,
 * auth) com restricoes de versao proprias que passariam a limitar as
 * atualizacoes do Laravel neste backend.
 */
class GoogleCalendarClient
{
    private const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    private const ACCESS_TOKEN_CACHE_KEY = 'agenda:google:access_token';

    public function __construct(
        private readonly GoogleCalendarSettingsService $settings
    ) {}

    /**
     * URL de consentimento.
     *
     * `access_type=offline` + `prompt=consent` sao obrigatorios: sem os dois o
     * Google devolve apenas um access token de uma hora, e o sync automatico
     * morre no dia seguinte por falta de refresh token.
     */
    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return self::OAUTH_AUTH_URL.'?'.http_build_query([
            'client_id' => $this->settings->get('agenda_google_client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => GoogleCalendarSettingsService::SCOPE.' https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** @return array<string, mixed> */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = Http::asForm()->post(self::OAUTH_TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->settings->get('agenda_google_client_id'),
            'client_secret' => $this->settings->get('agenda_google_client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        return $this->decode($response, 'Falha ao trocar o código de autorização com o Google.');
    }

    public function accessToken(): string
    {
        $cached = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $refreshToken = $this->settings->get('agenda_google_refresh_token');
        if ($refreshToken === '') {
            throw new RuntimeException('Conexão com o Google Agenda não autorizada.');
        }

        $response = Http::asForm()->post(self::OAUTH_TOKEN_URL, [
            'client_id' => $this->settings->get('agenda_google_client_id'),
            'client_secret' => $this->settings->get('agenda_google_client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $data = $this->decode($response, 'Falha ao renovar o acesso ao Google Agenda.');
        $token = trim((string) ($data['access_token'] ?? ''));

        if ($token === '') {
            throw new RuntimeException('O Google não devolveu um token de acesso.');
        }

        // Guarda com folga de 60s para nao usar um token que expira no meio da
        // requisicao seguinte.
        $ttl = max(60, (int) ($data['expires_in'] ?? 3600) - 60);
        Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    public function forgetAccessToken(): void
    {
        Cache::forget(self::ACCESS_TOKEN_CACHE_KEY);
    }

    public function accountEmail(string $accessToken): string
    {
        $response = Http::withToken($accessToken)->get(self::USERINFO_URL);

        if (! $response->successful()) {
            return '';
        }

        return trim((string) ($response->json('email') ?? ''));
    }

    /**
     * Cria o calendario dedicado do ERP.
     *
     * @return array<string, mixed>
     */
    public function createCalendar(string $summary, string $timezone): array
    {
        $response = $this->request()->post(self::API_BASE.'/calendars', [
            'summary' => $summary,
            'description' => 'Compromissos, vencimentos e lembretes sincronizados pelo Sistema ERP. Eventos criados aqui voltam para o ERP.',
            'timeZone' => $timezone,
        ]);

        return $this->decode($response, 'Falha ao criar o calendário dedicado no Google.');
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function insertEvent(string $calendarId, array $event): array
    {
        $response = $this->request()->post(
            self::API_BASE.'/calendars/'.rawurlencode($calendarId).'/events',
            $event
        );

        return $this->decode($response, 'Falha ao criar o evento no Google Agenda.');
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function patchEvent(string $calendarId, string $eventId, array $event): array
    {
        $response = $this->request()->patch(
            self::API_BASE.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId),
            $event
        );

        return $this->decode($response, 'Falha ao atualizar o evento no Google Agenda.');
    }

    /** Silencioso em 404/410: evento ja removido e o estado desejado. */
    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $response = $this->request()->delete(
            self::API_BASE.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId)
        );

        if ($response->successful() || in_array($response->status(), [404, 410], true)) {
            return;
        }

        $this->decode($response, 'Falha ao remover o evento no Google Agenda.');
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listEvents(string $calendarId, array $query): array
    {
        $response = $this->request()->get(
            self::API_BASE.'/calendars/'.rawurlencode($calendarId).'/events',
            $query
        );

        // 410 Gone = syncToken invalidado pelo Google (expirou ou o calendario
        // mudou demais). Quem chama precisa distinguir isso de uma falha real
        // para refazer a carga completa em vez de abortar.
        if ($response->status() === 410) {
            throw new GoogleSyncTokenExpiredException('O token de sincronização do Google expirou.');
        }

        return $this->decode($response, 'Falha ao ler os eventos do Google Agenda.');
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $failureMessage): array
    {
        if (! $response->successful()) {
            // Access token invalidado do outro lado (senha trocada, acesso
            // revogado): descarta o cache para a proxima tentativa renovar.
            if ($response->status() === 401) {
                $this->forgetAccessToken();
            }

            $detail = trim((string) ($response->json('error.message') ?? $response->json('error_description') ?? ''));

            throw new RuntimeException(
                $failureMessage.($detail !== '' ? ' Google: '.$detail : '').' (HTTP '.$response->status().')'
            );
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
