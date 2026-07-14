<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Models\UserPreference;
use App\Services\CompanyProfileService;
use App\Services\ConfigurationService;
use App\Services\DocumentationService;
use App\Services\GroupService;
use App\Services\UserService;
use App\Support\DesktopSession;
use App\Support\SessionSecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ConfigurationController extends DesktopController
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly CompanyProfileService $companyProfileService,
        private readonly DocumentationService $documentationService,
        private readonly UserService $userService,
        private readonly GroupService $groupService
    ) {
    }

    public function integrations(): View
    {
        return view('configurations.integrations', [
            'pageTitle' => 'Configurações',
            'integration' => $this->configurationService->integrations(),
        ]);
    }

    public function system(Request $request): View
    {
        $company = [];

        try {
            $company = $this->companyProfileService->find();
        } catch (Throwable) {
            $company = [];
        }

        $documentationTree = [];
        $documentationDoc = null;

        try {
            $documentationTree = $this->documentationService->tree();

            $requestedDoc = trim((string) $request->query('doc', ''));

            if ($requestedDoc === '' && $documentationTree !== []) {
                $requestedDoc = 'README.md';
            }

            if ($requestedDoc !== '') {
                $documentationDoc = $this->documentationService->read($requestedDoc);
            }
        } catch (Throwable $exception) {
            report($exception);
            $documentationTree = [];
            $documentationDoc = null;
        }

        $users = [];
        $userGroups = [];
        $userPagination = [];
        $userFilters = [];

        if (DesktopSession::can('usuarios', 'visualizar')) {
            $userFilters = [
                'search' => trim((string) $request->query('search', '')),
                'active' => trim((string) $request->query('active', '')),
                'page' => (int) $request->query('page', 1),
                'per_page' => (int) $request->query('per_page', 15),
            ];

            $result = $this->userService->paginate(array_filter(
                $userFilters,
                static fn ($value) => $value !== '' && $value !== 0
            ));

            $users = $result['items'];
            $userPagination = $result['pagination'];
            $userGroups = $this->groupService->all();
        }

        return view('configurations.system', [
            'pageTitle' => 'Configurações do Sistema',
            'company' => $company,
            'documentationTree' => $documentationTree,
            'documentationDoc' => $documentationDoc,
            'users' => $users,
            'groups' => $userGroups,
            'pagination' => $userPagination,
            'filters' => $userFilters,
            'sessionSecuritySettings' => SessionSecuritySettings::current(),
        ]);
    }

    public function updateSessionSecurity(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'idle_timeout_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'remember_me_lifetime_days' => ['required', 'integer', 'min:1', 'max:90'],
        ], [], [
            'idle_timeout_minutes' => 'tempo de inatividade',
            'remember_me_lifetime_days' => 'duração do "Manter-me conectado"',
        ]);

        SessionSecuritySettings::update([
            'idle_timeout_minutes' => (int) $validated['idle_timeout_minutes'],
            'remember_me_enabled' => $request->boolean('remember_me_enabled'),
            'remember_me_lifetime_days' => (int) $validated['remember_me_lifetime_days'],
            'warn_on_close' => $request->boolean('warn_on_close'),
        ]);

        return redirect()
            ->route('configurations.system.index', ['tab' => 'sessao'])
            ->with('success', 'Configurações de sessão e segurança salvas com sucesso.');
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        $payload = [
            'sistema_nome' => trim((string) $request->input('sistema_nome', '')),
            'empresa_razao_social' => trim((string) $request->input('empresa_razao_social', '')),
            'empresa_nome_fantasia' => trim((string) $request->input('empresa_nome_fantasia', '')),
            'empresa_cnpj' => trim((string) $request->input('empresa_cnpj', '')),
            'empresa_inscricao_estadual' => trim((string) $request->input('empresa_inscricao_estadual', '')),
            'empresa_telefone' => trim((string) $request->input('empresa_telefone', '')),
            'empresa_email' => trim((string) $request->input('empresa_email', '')),
            'empresa_endereco' => trim((string) $request->input('empresa_endereco', '')),
        ];

        $logo = $request->file('empresa_logo');
        $loginBackground = $request->file('login_background_image');

        try {
            $this->companyProfileService->update(
                $payload,
                $logo instanceof UploadedFile ? $logo : null,
                $loginBackground instanceof UploadedFile ? $loginBackground : null
            );
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()
                ->route('configurations.system.index', ['tab' => 'empresa'])
                ->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível salvar os dados da empresa agora. Tente novamente.');
        }

        return redirect()
            ->route('configurations.system.index', ['tab' => 'empresa'])
            ->with('success', 'Dados da empresa salvos com sucesso.');
    }

    public function companyLogo(): \Illuminate\Http\Response
    {
        try {
            $download = $this->companyProfileService->downloadLogo();
        } catch (ApiAuthenticationException $exception) {
            abort(401, $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (ApiRequestException $exception) {
            abort($exception->statusCode() > 0 ? $exception->statusCode() : 404, $exception->getMessage());
        }

        return response($download['body'], $download['status'], $download['headers']);
    }

    public function publicCompanyLogo(): \Illuminate\Http\Response
    {
        try {
            $download = $this->companyProfileService->downloadPublicLogo();
        } catch (ApiRequestException $exception) {
            abort($exception->statusCode() > 0 ? $exception->statusCode() : 404, $exception->getMessage());
        }

        return response($download['body'], $download['status'], $download['headers']);
    }

    public function publicLoginBackground(): \Illuminate\Http\Response
    {
        try {
            $download = $this->companyProfileService->downloadPublicLoginBackground();
        } catch (ApiRequestException $exception) {
            abort($exception->statusCode() > 0 ? $exception->statusCode() : 404, $exception->getMessage());
        }

        return response($download['body'], $download['status'], $download['headers']);
    }

    public function updateAppearance(Request $request): RedirectResponse
    {
        $allowed = ['default', 'jovem-tech', 'dark'];
        $theme = $request->input('theme', 'default');

        if (! in_array($theme, $allowed, true)) {
            return back()->with('error', 'Tema inválido.');
        }

        // Persiste na sessão (sempre explícito para servir de sentinel ao middleware)
        $request->session()->put('desktop_theme', $theme);

        // Persiste no banco vinculado ao usuário para sobreviver ao logout/login
        $userId = (int) (DesktopSession::user()['id'] ?? 0);
        if ($userId > 0) {
            UserPreference::updateOrCreate(
                ['api_user_id' => $userId],
                ['desktop_theme' => $theme]
            );
        }

        return redirect()
            ->route('configurations.system.index', ['tab' => 'aparencia'])
            ->with('success', 'Tema alterado com sucesso.');
    }

    public function help(): View
    {
        return view('configurations.help', [
            'pageTitle' => 'Ajuda das integrações',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $payload = $this->integrationPayload($request)
            + $this->paymentPayload($request)
            + $this->emailPayload($request)
            + $this->googlePayload($request);

        try {
            $this->configurationService->updateIntegrations($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()
                ->route('configurations.integrations.index')
                ->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos das integrações.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível salvar as integrações agora. Tente novamente.');
        }

        return redirect()
            ->route('configurations.integrations.index')
            ->with('success', 'Integrações salvas com sucesso.');
    }

    public function testConnection(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->testConnection($this->integrationPayload($request));
        });
    }

    public function sendTestMessage(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->sendTestMessage($this->integrationPayload($request) + [
                'mensagem' => trim((string) $request->input('mensagem', '')),
                'telefone' => trim((string) $request->input('telefone', '')),
            ]);
        });
    }

    public function selfCheckInbound(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->selfCheckInbound($this->integrationPayload($request));
        });
    }

    public function testPaymentConnection(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->testPaymentConnection($this->paymentPayload($request) + [
                'provider' => trim((string) $request->input('provider', '')),
            ]);
        });
    }

    public function sendEmailTest(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->sendEmailTest($this->emailPayload($request) + [
                'email' => trim((string) $request->input('email', '')),
            ]);
        });
    }

    public function gatewayStatus(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->gatewayStatus($this->integrationPayload($request) + [
                'provider' => (string) $request->input('provider', ''),
            ]);
        });
    }

    public function gatewayQr(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->gatewayQr($this->integrationPayload($request) + [
                'provider' => (string) $request->input('provider', ''),
            ]);
        });
    }

    public function gatewayRestart(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->gatewayRestart($this->integrationPayload($request) + [
                'provider' => (string) $request->input('provider', ''),
                'clean' => $request->boolean('clean'),
            ]);
        });
    }

    public function gatewayLogout(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->gatewayLogout($this->integrationPayload($request) + [
                'provider' => (string) $request->input('provider', ''),
            ]);
        });
    }

    public function gatewayStart(Request $request): JsonResponse
    {
        return $this->jsonAction(function () use ($request): array {
            return $this->configurationService->gatewayStart($this->integrationPayload($request) + [
                'provider' => (string) $request->input('provider', ''),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationPayload(Request $request): array
    {
        return [
            'whatsapp_enabled' => $request->boolean('whatsapp_enabled'),
            'whatsapp_direct_provider' => trim((string) $request->input('whatsapp_direct_provider', 'api_whats_local')),
            'whatsapp_bulk_provider' => trim((string) $request->input('whatsapp_bulk_provider', 'meta_oficial')),
            'whatsapp_test_phone' => trim((string) $request->input('whatsapp_test_phone', '')),
            'whatsapp_menuia_url' => trim((string) $request->input('whatsapp_menuia_url', '')),
            'whatsapp_menuia_appkey' => trim((string) $request->input('whatsapp_menuia_appkey', '')),
            'whatsapp_menuia_authkey' => trim((string) $request->input('whatsapp_menuia_authkey', '')),
            'whatsapp_webhook_token' => trim((string) $request->input('whatsapp_webhook_token', '')),
            'whatsapp_evolution_url' => trim((string) $request->input('whatsapp_evolution_url', '')),
            'whatsapp_evolution_apikey' => trim((string) $request->input('whatsapp_evolution_apikey', '')),
            'whatsapp_evolution_instance' => trim((string) $request->input('whatsapp_evolution_instance', '')),
            'whatsapp_evolution_timeout' => (int) $request->input('whatsapp_evolution_timeout', 20),
            'whatsapp_evolution_sync_avatar' => $request->boolean('whatsapp_evolution_sync_avatar'),
            'whatsapp_local_node_url' => trim((string) $request->input('whatsapp_local_node_url', '')),
            'whatsapp_local_node_token' => trim((string) $request->input('whatsapp_local_node_token', '')),
            'whatsapp_local_node_origin' => trim((string) $request->input('whatsapp_local_node_origin', '')),
            'whatsapp_local_node_timeout' => (int) $request->input('whatsapp_local_node_timeout', 20),
            'whatsapp_linux_node_url' => trim((string) $request->input('whatsapp_linux_node_url', '')),
            'whatsapp_linux_node_token' => trim((string) $request->input('whatsapp_linux_node_token', '')),
            'whatsapp_linux_node_origin' => trim((string) $request->input('whatsapp_linux_node_origin', '')),
            'whatsapp_linux_node_timeout' => (int) $request->input('whatsapp_linux_node_timeout', 20),
            'whatsapp_webhook_url' => trim((string) $request->input('whatsapp_webhook_url', '')),
            'whatsapp_webhook_method' => trim((string) $request->input('whatsapp_webhook_method', 'POST')),
            'whatsapp_webhook_headers' => (string) $request->input('whatsapp_webhook_headers', '{}'),
            'whatsapp_webhook_payload' => (string) $request->input('whatsapp_webhook_payload', '{"to":"{{phone}}","message":"{{message}}"}'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Request $request): array
    {
        return [
            'pagamentos_mercadopago_enabled' => $request->boolean('pagamentos_mercadopago_enabled'),
            'pagamentos_mercadopago_access_token' => trim((string) $request->input('pagamentos_mercadopago_access_token', '')),
            'pagamentos_mercadopago_public_key' => trim((string) $request->input('pagamentos_mercadopago_public_key', '')),
            'pagamentos_asaas_enabled' => $request->boolean('pagamentos_asaas_enabled'),
            'pagamentos_asaas_base_url' => trim((string) $request->input('pagamentos_asaas_base_url', '')),
            'pagamentos_asaas_api_key' => trim((string) $request->input('pagamentos_asaas_api_key', '')),
            'pagamentos_asaas_billing_type_default' => trim((string) $request->input('pagamentos_asaas_billing_type_default', 'PIX')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emailPayload(Request $request): array
    {
        return [
            'smtp_host' => trim((string) $request->input('smtp_host', '')),
            'smtp_port' => (int) $request->input('smtp_port', 587),
            'smtp_crypto' => trim((string) $request->input('smtp_crypto', 'auto')),
            'smtp_timeout' => (int) $request->input('smtp_timeout', 20),
            'smtp_user' => trim((string) $request->input('smtp_user', '')),
            'smtp_pass' => trim((string) $request->input('smtp_pass', '')),
            'smtp_from_email' => trim((string) $request->input('smtp_from_email', '')),
            'smtp_from_name' => trim((string) $request->input('smtp_from_name', '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function googlePayload(Request $request): array
    {
        return [
            'portal_google_client_id' => trim((string) $request->input('portal_google_client_id', '')),
            'portal_google_client_secret' => trim((string) $request->input('portal_google_client_secret', '')),
        ];
    }

    /**
     * @param callable(): array<string, mixed> $callback
     */
    private function jsonAction(callable $callback): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'result' => $callback(),
            ]);
        } catch (ApiAuthenticationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'redirect' => route('login'),
            ], 401);
        } catch (ApiAuthorizationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'redirect' => route('configurations.integrations.index'),
            ], 403);
        } catch (ApiRequestException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'details' => $exception->details() ?? [],
            ], $exception->statusCode() > 0 ? $exception->statusCode() : 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível concluir a ação agora.',
            ], 500);
        }
    }

    /**
     * @param array<string, mixed>|null $details
     * @return array<string, array<int, string>>
     */
    private function formatApiErrors(ApiRequestException $exception): array
    {
        $details = $exception->details();

        if (! is_array($details)) {
            return [];
        }

        $errors = [];

        foreach ($details as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $errors[$field] = array_values(array_filter($messages, 'is_string'));
        }

        return $errors;
    }
}
