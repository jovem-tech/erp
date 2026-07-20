<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiRequestException;
use App\Models\UserPreference;
use App\Services\ApiClient;
use App\Support\DesktopSession;
use App\Support\SessionSecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureBackendToken
{
    public function __construct(
        private readonly ApiClient $apiClient
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! DesktopSession::hasToken()) {
            DesktopSession::forget();

            return redirect()->route('login');
        }

        if (DesktopSession::isIdleTimedOut()) {
            DesktopSession::forget();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Sessão encerrada por inatividade.');
        }

        DesktopSession::touchActivity();

        // Ajusta os cookies desta resposta conforme "Manter-me conectado".
        // Tanto o cookie de sessão (StartSession) quanto o XSRF-TOKEN
        // (VerifyCsrfToken) montam seu Set-Cookie na saída da requisição —
        // depois deste middleware — então config() aqui vale para ambos.
        //   - expire_on_close: false = sobrevive ao fechar o navegador.
        //   - session.lifetime: usado como validade (Max-Age) dos cookies.
        // Sem "lembrar-me", encurtamos o lifetime para o timeout de
        // inatividade — assim o XSRF-TOKEN deixa de nascer com 30 dias e passa
        // a ser um cookie curto, coerente com uma sessão que morre ao fechar.
        // (O teto de storage/GC alto continua vindo do AppServiceProvider,
        // então sessões "lembradas" não são coletadas cedo demais.)
        if (DesktopSession::rememberMe()) {
            config(['session.expire_on_close' => false]);
        } else {
            config([
                'session.expire_on_close' => true,
                'session.lifetime' => SessionSecuritySettings::idleTimeoutMinutes(),
            ]);
        }

        if (DesktopSession::shouldSyncProfile()) {
            try {
                $payload = $this->apiClient->me();
                DesktopSession::refreshUser($payload['data'] ?? []);
            } catch (ApiRequestException $exception) {
                Log::warning('desktop_profile_sync_unavailable', [
                    'status_code' => $exception->statusCode(),
                    'has_authorization_snapshot' => DesktopSession::hasAuthorizationSnapshot(),
                ]);

                // O backend continua sendo a autoridade final para cada chamada.
                // Um snapshot local existente pode manter apenas a navegacao viva
                // durante uma falha transitoria, sem conceder acesso na API.
                if (! DesktopSession::hasAuthorizationSnapshot()) {
                    DesktopSession::forget();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()
                        ->route('login')
                        ->with('error', 'Nao foi possivel validar sua sessao. Tente entrar novamente em instantes.');
                }
            }
        }

        // Carrega preferência de tema persistida na primeira request autenticada da sessão
        if (! session()->has('desktop_theme')) {
            $userId = (int) (DesktopSession::user()['id'] ?? 0);
            if ($userId > 0) {
                $theme = UserPreference::where('api_user_id', $userId)->value('desktop_theme');
                session()->put('desktop_theme', $theme ?? 'default');
            }
        }

        return $next($request);
    }
}
