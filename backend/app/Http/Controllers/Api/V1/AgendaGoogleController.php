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
            return $this->closingPage(
                'Autorização cancelada no Google ('.e($error).'). Feche esta aba e tente novamente pelo painel.',
                false
            );
        }

        try {
            $this->connection->completeAuthorization(
                (string) $request->query('code', ''),
                (string) $request->query('state', '')
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->closingPage(
                e($exception->getMessage()).' Feche esta aba e tente novamente pelo painel.',
                false
            );
        }

        return $this->closingPage(
            'Google Agenda conectado. Feche esta aba e recarregue a tela de Integrações no painel para ver a conexão ativa.',
            true
        );
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
     * Pagina de retorno do consentimento, exibida na aba que o painel abriu.
     *
     * Sem auto-close de proposito: `window.close()` so funciona em janela que
     * o proprio script abriu. Esta aba nasce de um link com target="_blank",
     * entao o navegador recusaria o fechamento e ainda registraria um aviso no
     * console. Melhor pedir a acao do que prometer o que nao acontece.
     */
    private function closingPage(string $message, bool $success): Response
    {
        $color = $success ? '#1f9d55' : '#c53030';
        $icon = $success ? '&#10003;' : '&#33;';

        $html = <<<HTML
            <!doctype html>
            <html lang="pt-BR"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Google Agenda</title>
            <style>
              body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f7fb;margin:0;
                   display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
              .card{background:#fff;border-radius:14px;padding:32px;max-width:460px;text-align:center;
                    box-shadow:0 10px 40px rgba(15,23,42,.12)}
              .mark{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;
                    border-radius:999px;margin-bottom:14px;font-size:22px;color:#fff;background:{$color}}
              h1{font-size:18px;margin:0 0 10px;color:#0f172a}
              p{margin:0;color:#475569;line-height:1.55;font-size:14px}
            </style></head>
            <body><div class="card">
              <span class="mark">{$icon}</span>
              <h1>Google Agenda</h1>
              <p>{$message}</p>
            </div></body></html>
            HTML;

        return response($html, $success ? 200 : 400)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
