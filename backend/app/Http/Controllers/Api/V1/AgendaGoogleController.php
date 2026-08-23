<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Agenda\Google\GoogleCalendarConnectionService;
use App\Services\Agenda\Google\GoogleCalendarPullService;
use App\Services\Agenda\Google\GoogleCalendarPushService;
use App\Services\Agenda\Google\GoogleCalendarSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

/**
 * Conexao do ERP com o Google Agenda.
 *
 * Tudo aqui exige `configuracoes:editar`, e nao a permissao do modulo agenda:
 * conectar envolve credenciais da empresa inteira e cria um calendario numa
 * conta Google real - e ato de administracao, nao de uso da agenda.
 */
class AgendaGoogleController extends BaseApiController
{
    public function __construct(
        private readonly GoogleCalendarSettingsService $settings,
        private readonly GoogleCalendarConnectionService $connection,
        private readonly GoogleCalendarPushService $push,
        private readonly GoogleCalendarPullService $pull
    ) {}

    public function status(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:visualizar');

        return $this->success(array_merge($this->settings->payload(), [
            'redirect_uri' => $this->connection->redirectUri(),
        ]), request: $request);
    }

    public function saveCredentials(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        $validated = $request->validate([
            'agenda_google_client_id' => ['nullable', 'string', 'max:255'],
            'agenda_google_client_secret' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->success($this->settings->save($validated), request: $request);
    }

    public function connect(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        try {
            $authorization = $this->connection->authorizationUrl();
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'AGENDA_GOOGLE_NOT_CONFIGURED', request: $request);
        }

        return $this->success($authorization, request: $request);
    }

    /**
     * Retorno do consentimento do Google.
     *
     * Sem `auth:sanctum`: quem chega aqui e o navegador do usuario redirecionado
     * pelo Google, sem o Bearer token do desktop. O que autentica a requisicao e
     * o `state` de uso unico gerado em connect() e validado em
     * completeAuthorization() - nao um cookie ou header.
     */
    public function callback(Request $request): Response
    {
        $error = trim((string) $request->query('error', ''));

        if ($error !== '') {
            return $this->closingPage('Autorização cancelada no Google: '.e($error), false);
        }

        try {
            $this->connection->completeAuthorization(
                (string) $request->query('code', ''),
                (string) $request->query('state', '')
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->closingPage(e($exception->getMessage()), false);
        }

        return $this->closingPage('Google Agenda conectado com sucesso. Você já pode fechar esta janela.', true);
    }

    /** Saida de emergencia para ambientes onde o Google recusa o redirect URI. */
    public function connectManual(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        $validated = $request->validate([
            'refresh_token' => ['required', 'string', 'max:512'],
        ]);

        try {
            $payload = $this->connection->connectWithRefreshToken($validated['refresh_token']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'AGENDA_GOOGLE_CONNECT_FAILED', request: $request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                'Não foi possível validar o refresh token informado.',
                422,
                'AGENDA_GOOGLE_CONNECT_FAILED',
                request: $request
            );
        }

        return $this->success($payload, request: $request);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->authorize('configuracoes:editar');

        return $this->success($this->connection->disconnect(), request: $request);
    }

    public function syncNow(Request $request): JsonResponse
    {
        $this->authorize('agenda:visualizar');

        if (! $this->settings->isConnected()) {
            return $this->error('Google Agenda não está conectado.', 422, 'AGENDA_GOOGLE_DISCONNECTED', request: $request);
        }

        try {
            $pushed = $this->push->pushPending();
            $stats = $this->pull->pull();
            $this->settings->recordSyncResult('sucesso');
        } catch (Throwable $exception) {
            $this->settings->recordSyncResult('erro', $exception->getMessage());
            report($exception);

            return $this->error(
                'Falha ao sincronizar com o Google: '.$exception->getMessage(),
                422,
                'AGENDA_GOOGLE_SYNC_FAILED',
                request: $request
            );
        }

        return $this->success(array_merge($stats, ['enviados' => $pushed]), request: $request);
    }

    /**
     * O consentimento acontece numa janela separada; esta pagina fecha sozinha
     * e devolve o foco ao painel.
     */
    private function closingPage(string $message, bool $success): Response
    {
        $color = $success ? '#1f9d55' : '#c53030';

        $html = <<<HTML
            <!doctype html>
            <html lang="pt-BR"><head><meta charset="utf-8">
            <title>Google Agenda</title>
            <style>
              body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f7fb;margin:0;
                   display:flex;align-items:center;justify-content:center;height:100vh;padding:24px}
              .card{background:#fff;border-radius:14px;padding:32px;max-width:460px;text-align:center;
                    box-shadow:0 10px 40px rgba(15,23,42,.12)}
              h1{font-size:18px;margin:0 0 12px;color:{$color}}
              p{margin:0;color:#475569;line-height:1.5;font-size:14px}
            </style></head>
            <body><div class="card"><h1>Google Agenda</h1><p>{$message}</p></div>
            <script>setTimeout(function(){ window.close(); }, 4000);</script>
            </body></html>
            HTML;

        return response($html, $success ? 200 : 400)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
