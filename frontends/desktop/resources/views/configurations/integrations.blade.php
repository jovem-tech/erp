@extends('layouts.app')

@section('content')
    @php
        $settings = is_array($integration['settings'] ?? null) ? $integration['settings'] : [];
        $secretStatus = is_array($integration['secret_status'] ?? null) ? $integration['secret_status'] : [];
        $summary = is_array($integration['summary'] ?? null) ? $integration['summary'] : [];
        $providerOptions = is_array($integration['provider_options'] ?? null) ? $integration['provider_options'] : [];
        $gateway = is_array($integration['gateway'] ?? null) ? $integration['gateway'] : [];
        $localGateway = is_array($gateway['local'] ?? null) ? $gateway['local'] : [];
        $linuxGateway = is_array($gateway['linux'] ?? null) ? $gateway['linux'] : [];
        $isConfiguredSecret = static fn (array $status, string $key): bool => (bool) data_get($status, $key . '.configured', false);

        $directProvider = old('whatsapp_direct_provider', (string) ($settings['whatsapp_direct_provider'] ?? 'api_whats_local'));
        $bulkProvider = old('whatsapp_bulk_provider', (string) ($settings['whatsapp_bulk_provider'] ?? 'meta_oficial'));
        $enabled = old('whatsapp_enabled', (bool) ($settings['whatsapp_enabled'] ?? false));
        $testPhone = old('whatsapp_test_phone', (string) ($settings['whatsapp_test_phone'] ?? ''));
        $menuiaUrl = old('whatsapp_menuia_url', (string) ($settings['whatsapp_menuia_url'] ?? 'https://chatbot.menuia.com/api'));
        $menuiaAppKey = old('whatsapp_menuia_appkey', '');
        $menuiaAppKeyConfigured = $isConfiguredSecret($secretStatus, 'whatsapp_menuia_appkey');
        $menuiaAuthKey = old('whatsapp_menuia_authkey', '');
        $menuiaAuthKeyConfigured = $isConfiguredSecret($secretStatus, 'whatsapp_menuia_authkey');
        $webhookToken = old('whatsapp_webhook_token', '');
        $webhookTokenConfigured = $isConfiguredSecret($secretStatus, 'whatsapp_webhook_token');
        $evolutionUrl = old('whatsapp_evolution_url', (string) ($settings['whatsapp_evolution_url'] ?? 'http://127.0.0.1:8080'));
        $evolutionApiKey = old('whatsapp_evolution_apikey', '');
        $evolutionApiKeyConfigured = $isConfiguredSecret($secretStatus, 'whatsapp_evolution_apikey');
        $evolutionInstance = old('whatsapp_evolution_instance', (string) ($settings['whatsapp_evolution_instance'] ?? ''));
        $evolutionTimeout = old('whatsapp_evolution_timeout', (string) ($settings['whatsapp_evolution_timeout'] ?? '20'));
        $evolutionSyncAvatar = old('whatsapp_evolution_sync_avatar', (bool) ($settings['whatsapp_evolution_sync_avatar'] ?? true));
        $localNodeUrl = old('whatsapp_local_node_url', (string) ($settings['whatsapp_local_node_url'] ?? 'http://127.0.0.1:3001'));
        $localNodeToken = old('whatsapp_local_node_token', '');
        $localNodeTokenConfigured = $isConfiguredSecret($secretStatus, 'whatsapp_local_node_token');
        $localNodeOrigin = old('whatsapp_local_node_origin', (string) ($settings['whatsapp_local_node_origin'] ?? config('app.url')));
        $localNodeTimeout = old('whatsapp_local_node_timeout', (string) ($settings['whatsapp_local_node_timeout'] ?? '20'));
        $linuxNodeUrl = old('whatsapp_linux_node_url', (string) ($settings['whatsapp_linux_node_url'] ?? 'http://127.0.0.1:3001'));
        $linuxNodeToken = old('whatsapp_linux_node_token', '');
        $linuxNodeTokenConfigured = $isConfiguredSecret($secretStatus, 'whatsapp_linux_node_token');
        $linuxNodeOrigin = old('whatsapp_linux_node_origin', (string) ($settings['whatsapp_linux_node_origin'] ?? config('app.url')));
        $linuxNodeTimeout = old('whatsapp_linux_node_timeout', (string) ($settings['whatsapp_linux_node_timeout'] ?? '20'));
        $webhookUrl = old('whatsapp_webhook_url', (string) ($settings['whatsapp_webhook_url'] ?? ''));
        $webhookMethod = old('whatsapp_webhook_method', (string) ($settings['whatsapp_webhook_method'] ?? 'POST'));
        $webhookHeaders = old('whatsapp_webhook_headers', (string) ($settings['whatsapp_webhook_headers'] ?? '{}'));
        $webhookPayload = old('whatsapp_webhook_payload', (string) ($settings['whatsapp_webhook_payload'] ?? '{"to":"{{phone}}","message":"{{message}}"}'));

        $statusClass = match ((string) ($summary['status'] ?? 'secondary')) {
            'success' => 'text-bg-success',
            'warning' => 'text-bg-warning',
            'danger' => 'text-bg-danger',
            'dark' => 'text-bg-dark',
            default => 'text-bg-secondary',
        };

        $statusLabel = (string) ($summary['status_label'] ?? 'Não validado');
        $statusMessage = (string) ($summary['status_message'] ?? 'Ainda não houve validação da conectividade.');
        $currentProvider = (string) ($summary['provider_label'] ?? 'Integração');
        $bulkProviderLabel = (string) ($summary['bulk_provider_label'] ?? 'Meta Oficial (futuro)');
        $lastCheckAt = (string) ($summary['last_check_at'] ?? '');
        $lastCheckMessage = (string) ($summary['last_check_message'] ?? '');
        $lastCheckProvider = (string) ($summary['last_check_provider'] ?? '');
        $gatewayKey = str_starts_with($directProvider, 'api_whats_linux') ? 'linux' : 'local';
        $gatewayCurrent = is_array($gateway[$gatewayKey] ?? null) ? $gateway[$gatewayKey] : [];
        $gatewayPreview = is_array($gatewayCurrent) ? $gatewayCurrent : [];
        $gatewayResponse = is_array($gatewayPreview['response'] ?? null) ? $gatewayPreview['response'] : [];
        $gatewayData = is_array($gatewayResponse['data'] ?? null) ? $gatewayResponse['data'] : [];
        $gatewayStatus = (string) ($gatewayResponse['status'] ?? ($gatewayPreview['status'] ?? 'disconnected'));
        $gatewayStatusLabel = (string) ($gatewayPreview['message'] ?? 'Aguardando consulta');
        $gatewayAccountName = (string) ($gatewayData['account']['pushname'] ?? '-');
        $gatewayAccountNumber = (string) ($gatewayData['account']['number'] ?? '-');
        $gatewayAccountPlatform = (string) ($gatewayData['account']['platform'] ?? '-');
        $gatewayQr = (string) ($gatewayData['qr'] ?? '');
        $connectedPreview = asset('assets/img/sistema/whatsapp_connected_success.png');

        $payments = is_array($integration['payments'] ?? null) ? $integration['payments'] : [];
        $paymentSettings = is_array($payments['settings'] ?? null) ? $payments['settings'] : [];
        $paymentSecretStatus = is_array($payments['secret_status'] ?? null) ? $payments['secret_status'] : [];
        $paymentSummary = is_array($payments['summary'] ?? null) ? $payments['summary'] : [];

        $mercadoPagoEnabled = old('pagamentos_mercadopago_enabled', (bool) ($paymentSettings['pagamentos_mercadopago_enabled'] ?? false));
        $mercadoPagoAccessToken = old('pagamentos_mercadopago_access_token', '');
        $mercadoPagoAccessTokenConfigured = $isConfiguredSecret($paymentSecretStatus, 'pagamentos_mercadopago_access_token');
        $mercadoPagoPublicKey = old('pagamentos_mercadopago_public_key', (string) ($paymentSettings['pagamentos_mercadopago_public_key'] ?? ''));
        $mercadoPagoReady = (bool) ($paymentSummary['mercado_pago']['ready'] ?? false);
        $mercadoPagoStatusLabel = (string) ($paymentSummary['mercado_pago']['status_label'] ?? 'Aguardando configuração');

        $asaasEnabled = old('pagamentos_asaas_enabled', (bool) ($paymentSettings['pagamentos_asaas_enabled'] ?? false));
        $asaasBaseUrl = old('pagamentos_asaas_base_url', (string) ($paymentSettings['pagamentos_asaas_base_url'] ?? 'https://api-sandbox.asaas.com/v3'));
        $asaasApiKey = old('pagamentos_asaas_api_key', '');
        $asaasApiKeyConfigured = $isConfiguredSecret($paymentSecretStatus, 'pagamentos_asaas_api_key');
        $asaasBillingTypeDefault = old('pagamentos_asaas_billing_type_default', (string) ($paymentSettings['pagamentos_asaas_billing_type_default'] ?? 'PIX'));
        $asaasReady = (bool) ($paymentSummary['asaas']['ready'] ?? false);
        $asaasStatusLabel = (string) ($paymentSummary['asaas']['status_label'] ?? 'Aguardando configuração');

        $emailIntegration = is_array($integration['email'] ?? null) ? $integration['email'] : [];
        $emailSettings = is_array($emailIntegration['settings'] ?? null) ? $emailIntegration['settings'] : [];
        $emailSecretStatus = is_array($emailIntegration['secret_status'] ?? null) ? $emailIntegration['secret_status'] : [];
        $emailConfigured = (bool) ($emailIntegration['summary']['configured'] ?? false);
        $smtpHost = old('smtp_host', (string) ($emailSettings['smtp_host'] ?? ''));
        $smtpPort = old('smtp_port', (string) ($emailSettings['smtp_port'] ?? '587'));
        $smtpCrypto = old('smtp_crypto', (string) ($emailSettings['smtp_crypto'] ?? 'auto'));
        $smtpTimeout = old('smtp_timeout', (string) ($emailSettings['smtp_timeout'] ?? '20'));
        $smtpUser = old('smtp_user', (string) ($emailSettings['smtp_user'] ?? ''));
        $smtpPass = old('smtp_pass', '');
        $smtpPassConfigured = $isConfiguredSecret($emailSecretStatus, 'smtp_pass');
        $smtpFromEmail = old('smtp_from_email', (string) ($emailSettings['smtp_from_email'] ?? ''));
        $smtpFromName = old('smtp_from_name', (string) ($emailSettings['smtp_from_name'] ?? ''));

        $googleIntegration = is_array($integration['google'] ?? null) ? $integration['google'] : [];
        $googleSettings = is_array($googleIntegration['settings'] ?? null) ? $googleIntegration['settings'] : [];
        $googleSecretStatus = is_array($googleIntegration['secret_status'] ?? null) ? $googleIntegration['secret_status'] : [];
        $googleConfigured = (bool) ($googleIntegration['summary']['configured'] ?? false);
        $googleClientId = old('portal_google_client_id', (string) ($googleSettings['portal_google_client_id'] ?? ''));
        $googleClientSecret = old('portal_google_client_secret', '');
        $googleClientSecretConfigured = $isConfiguredSecret($googleSecretStatus, 'portal_google_client_secret');
    @endphp

    <section class="desktop-page-stack">
        <div class="desktop-page-hero">
            <div>
                <h2>Configurações</h2>
                <p>Começamos pelas integrações. Os demais painéis ficam reservados para a próxima etapa do módulo Empresa.</p>
            </div>

            <a href="{{ route('configurations.integrations.help') }}" class="btn btn-outline-info rounded-pill">
                <i class="bi bi-question-circle me-1"></i>Ajuda
            </a>
        </div>

        <div class="surface-card desktop-config-tabs-shell">
            <ul class="nav nav-pills flex-wrap gap-2 desktop-config-tabs" role="tablist" aria-label="Abas de configurações">
                <li class="nav-item" role="presentation">
                    <button class="nav-link" type="button" disabled>
                        <i class="bi bi-palette me-1"></i>Aparência
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" type="button" disabled>
                        <i class="bi bi-building me-1"></i>Dados da Empresa
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" type="button" disabled>
                        <i class="bi bi-shield-lock me-1"></i>Sessão e Segurança
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" type="button" disabled>
                        <i class="bi bi-calculator me-1"></i>Precificação
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link active d-flex align-items-center" type="button" aria-current="page">
                        <i class="bi bi-plug me-1"></i>Integrações
                    </button>
                </li>
            </ul>

            <form
                method="post"
                action="{{ route('configurations.integrations.update') }}"
                data-integration-form
            >
                @csrf

                <div class="config-subtabs" role="tablist" aria-label="Sub-abas de integrações">
                    <button type="button" class="config-subtab is-active" data-config-subtab="whatsapp" aria-pressed="true">
                        <i class="bi bi-whatsapp me-1"></i>WhatsApp
                        <span class="badge rounded-pill text-bg-light border text-secondary ms-1" style="font-size:.65rem;">{{ $statusLabel }}</span>
                    </button>
                    <button type="button" class="config-subtab" data-config-subtab="payments" aria-pressed="false">
                        <i class="bi bi-credit-card-2-front me-1"></i>Pagamentos
                    </button>
                    <button type="button" class="config-subtab" data-config-subtab="email" aria-pressed="false">
                        <i class="bi bi-envelope me-1"></i>E-mail
                    </button>
                    <button type="button" class="config-subtab" data-config-subtab="google" aria-pressed="false">
                        <i class="bi bi-google me-1"></i>Portal do Cliente
                    </button>
                </div>

                <div class="config-subpanel is-active" data-config-subpanel="whatsapp">
                    <div class="desktop-config-hero">
                        <div>
                            <h3 class="surface-title mb-1">Configurações WhatsApp</h3>
                            <p class="surface-subtitle mb-0">Canais direto e em massa, gateway local, Evolution e webhook compartilhando o mesmo banco do ERP.</p>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge rounded-pill {{ $statusClass }}">
                                <i class="bi bi-whatsapp me-1"></i>{{ $statusLabel }}
                            </span>
                            <span class="badge rounded-pill text-bg-light border text-secondary" data-integration-current-chip>
                                {{ $currentProvider }}
                            </span>
                            <span class="badge rounded-pill text-bg-light border text-secondary" data-integration-bulk-chip>
                                Massa: {{ $bulkProviderLabel }}
                            </span>
                        </div>
                    </div>

                    <div class="desktop-grid desktop-grid-two desktop-config-grid">
                        <div class="desktop-form-card desktop-config-form">
                            <div class="surface-card-header">
                                <div>
                                    <h4 class="surface-title">Dados de integração</h4>
                                    <p class="surface-subtitle">Preencha apenas os campos que o seu provedor atual utiliza.</p>
                                </div>
                            </div>

                            <div class="desktop-config-summary-row">
                                <div class="desktop-config-summary-item">
                                    <span class="desktop-config-summary-label">Status atual</span>
                                    <strong>{{ $statusLabel }}</strong>
                                    <small>{{ $statusMessage }}</small>
                                </div>
                                <div class="desktop-config-summary-item">
                                    <span class="desktop-config-summary-label">Última validação</span>
                                    <strong>{{ $lastCheckAt !== '' ? $lastCheckAt : 'Nunca' }}</strong>
                                    <small>{{ $lastCheckMessage !== '' ? $lastCheckMessage : 'Sem validação registrada.' }}</small>
                                </div>
                            </div>

                            <div class="desktop-grid desktop-grid-two">
                                <div>
                                    <label for="whatsappEnabled">Envio habilitado</label>
                                    <select class="form-select" id="whatsappEnabled" name="whatsapp_enabled">
                                        <option value="1" @selected((bool) $enabled)>Sim</option>
                                        <option value="0" @selected(! (bool) $enabled)>Não</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="whatsappTestPhone">Telefone de teste</label>
                                    <input
                                        type="text"
                                        id="whatsappTestPhone"
                                        name="whatsapp_test_phone"
                                        class="form-control"
                                        value="{{ $testPhone }}"
                                        placeholder="(22) 99999-9999"
                                    >
                                </div>

                                <div>
                                    <label for="whatsappDirectProvider">Canal direto</label>
                                    <select class="form-select" id="whatsappDirectProvider" name="whatsapp_direct_provider" data-integration-provider>
                                        @foreach (($providerOptions['direct'] ?? []) as $option)
                                            <option value="{{ $option['value'] ?? '' }}" @selected(($option['value'] ?? '') === $directProvider)>
                                                {{ $option['label'] ?? $option['value'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label for="whatsappBulkProvider">Canal massa</label>
                                    <select class="form-select" id="whatsappBulkProvider" name="whatsapp_bulk_provider">
                                        @foreach (($providerOptions['bulk'] ?? []) as $option)
                                            <option value="{{ $option['value'] ?? '' }}" @selected(($option['value'] ?? '') === $bulkProvider)>
                                                {{ $option['label'] ?? $option['value'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="desktop-config-section">
                                <div class="surface-card-header mb-3">
                                    <div>
                                        <h4 class="surface-title">Evolution API</h4>
                                        <p class="surface-subtitle">Ideal para o canal oficial e para operações que precisam de estabilidade em produção.</p>
                                    </div>
                                </div>

                                <div class="desktop-grid desktop-grid-two">
                                    <div>
                                        <label for="whatsappEvolutionUrl">URL Evolution</label>
                                        <input type="text" id="whatsappEvolutionUrl" name="whatsapp_evolution_url" class="form-control" value="{{ $evolutionUrl }}" placeholder="http://127.0.0.1:8080">
                                    </div>

                                    <div>
                                        <label for="whatsappEvolutionTimeout">Timeout Evolution (s)</label>
                                        <input type="number" id="whatsappEvolutionTimeout" name="whatsapp_evolution_timeout" class="form-control" min="5" max="120" step="1" value="{{ $evolutionTimeout }}">
                                    </div>

                                    <div>
                                        <label for="whatsappEvolutionApiKey">API Key (Evolution)</label>
                                        <input type="password" id="whatsappEvolutionApiKey" name="whatsapp_evolution_apikey" class="form-control" value="{{ $evolutionApiKey }}" autocomplete="new-password" placeholder="{{ $evolutionApiKeyConfigured ? 'Credencial salva. Preencha apenas para trocar.' : '' }}">
                                        @if ($evolutionApiKeyConfigured)
                                            <small class="text-muted">Uma API key ja esta salva no backend. Deixe em branco para manter.</small>
                                        @endif
                                    </div>

                                    <div>
                                        <label for="whatsappEvolutionInstance">Instância Evolution</label>
                                        <input type="text" id="whatsappEvolutionInstance" name="whatsapp_evolution_instance" class="form-control" value="{{ $evolutionInstance }}">
                                    </div>
                                </div>

                                <div class="form-check form-switch mt-3">
                                    <input class="form-check-input" type="checkbox" id="whatsappEvolutionSyncAvatar" name="whatsapp_evolution_sync_avatar" value="1" @checked((bool) $evolutionSyncAvatar)>
                                    <label class="form-check-label" for="whatsappEvolutionSyncAvatar">
                                        Sincronismo da Central
                                    </label>
                                </div>
                                <p class="surface-subtitle mt-2 mb-0">
                                    Quando ativo, o ERP sincroniza nome de perfil, remote JID e avatar para manter a central de mensagens consistente.
                                </p>
                            </div>

                            <div class="desktop-config-section">
                                <div class="surface-card-header mb-3">
                                    <div>
                                        <h4 class="surface-title">Gateway local</h4>
                                        <p class="surface-subtitle">Use a API do Node local para Windows de desenvolvimento ou para o serviço equivalente em VPS.</p>
                                    </div>
                                </div>

                                <div class="desktop-grid desktop-grid-two">
                                    <div>
                                        <label for="whatsappLocalNodeUrl">URL local</label>
                                        <input type="text" id="whatsappLocalNodeUrl" name="whatsapp_local_node_url" class="form-control" value="{{ $localNodeUrl }}">
                                    </div>

                                    <div>
                                        <label for="whatsappLocalNodeTimeout">Timeout local (s)</label>
                                        <input type="number" id="whatsappLocalNodeTimeout" name="whatsapp_local_node_timeout" class="form-control" min="5" max="120" step="1" value="{{ $localNodeTimeout }}">
                                    </div>

                                    <div>
                                        <label for="whatsappLocalNodeToken">Token local</label>
                                        <input type="password" id="whatsappLocalNodeToken" name="whatsapp_local_node_token" class="form-control" value="{{ $localNodeToken }}" autocomplete="new-password" placeholder="{{ $localNodeTokenConfigured ? 'Credencial salva. Preencha apenas para trocar.' : '' }}">
                                        @if ($localNodeTokenConfigured)
                                            <small class="text-muted">O token atual permanece salvo se este campo continuar vazio.</small>
                                        @endif
                                    </div>

                                    <div>
                                        <label for="whatsappLocalNodeOrigin">Origem local</label>
                                        <input type="text" id="whatsappLocalNodeOrigin" name="whatsapp_local_node_origin" class="form-control" value="{{ $localNodeOrigin }}">
                                    </div>
                                </div>
                            </div>

                            <div class="desktop-config-section">
                                <div class="surface-card-header mb-3">
                                    <div>
                                        <h4 class="surface-title">Gateway Linux / VPS</h4>
                                        <p class="surface-subtitle">Mesmo contrato do Node local, mas com caminho e processo compatíveis com produção.</p>
                                    </div>
                                </div>

                                <div class="desktop-grid desktop-grid-two">
                                    <div>
                                        <label for="whatsappLinuxNodeUrl">URL Linux</label>
                                        <input type="text" id="whatsappLinuxNodeUrl" name="whatsapp_linux_node_url" class="form-control" value="{{ $linuxNodeUrl }}">
                                    </div>

                                    <div>
                                        <label for="whatsappLinuxNodeTimeout">Timeout Linux (s)</label>
                                        <input type="number" id="whatsappLinuxNodeTimeout" name="whatsapp_linux_node_timeout" class="form-control" min="5" max="120" step="1" value="{{ $linuxNodeTimeout }}">
                                    </div>

                                    <div>
                                        <label for="whatsappLinuxNodeToken">Token Linux</label>
                                        <input type="password" id="whatsappLinuxNodeToken" name="whatsapp_linux_node_token" class="form-control" value="{{ $linuxNodeToken }}" autocomplete="new-password" placeholder="{{ $linuxNodeTokenConfigured ? 'Credencial salva. Preencha apenas para trocar.' : '' }}">
                                        @if ($linuxNodeTokenConfigured)
                                            <small class="text-muted">O token atual permanece salvo se este campo continuar vazio.</small>
                                        @endif
                                    </div>

                                    <div>
                                        <label for="whatsappLinuxNodeOrigin">Origem Linux</label>
                                        <input type="text" id="whatsappLinuxNodeOrigin" name="whatsapp_linux_node_origin" class="form-control" value="{{ $linuxNodeOrigin }}">
                                    </div>
                                </div>
                            </div>

                            <div class="desktop-config-section">
                                <div class="surface-card-header mb-3">
                                    <div>
                                        <h4 class="surface-title">Webhook e fallback</h4>
                                        <p class="surface-subtitle">Configuração útil quando a integração direta precisa ser repassada por um endpoint externo.</p>
                                    </div>
                                </div>

                                <div class="desktop-grid desktop-grid-two">
                                    <div>
                                        <label for="whatsappMenuiaUrl">URL Menuia</label>
                                        <input type="text" id="whatsappMenuiaUrl" name="whatsapp_menuia_url" class="form-control" value="{{ $menuiaUrl }}">
                                    </div>

                                    <div>
                                        <label for="whatsappWebhookToken">Webhook Token (inbound)</label>
                                        <input type="password" id="whatsappWebhookToken" name="whatsapp_webhook_token" class="form-control" value="{{ $webhookToken }}" autocomplete="new-password" placeholder="{{ $webhookTokenConfigured ? 'Token salvo. Preencha apenas para trocar.' : 'Obrigatorio para aceitar eventos inbound.' }}">
                                        <small class="text-muted">Obrigatorio para liberar o webhook inbound. Deixe em branco para manter o token atual.</small>
                                    </div>

                                    <div>
                                        <label for="whatsappMenuiaAppKey">App Key Menuia</label>
                                        <input type="password" id="whatsappMenuiaAppKey" name="whatsapp_menuia_appkey" class="form-control" value="{{ $menuiaAppKey }}" autocomplete="new-password" placeholder="{{ $menuiaAppKeyConfigured ? 'Credencial salva. Preencha apenas para trocar.' : '' }}">
                                        @if ($menuiaAppKeyConfigured)
                                            <small class="text-muted">A App Key atual permanece salva se este campo continuar vazio.</small>
                                        @endif
                                    </div>

                                    <div>
                                        <label for="whatsappMenuiaAuthKey">Auth Key Menuia</label>
                                        <input type="password" id="whatsappMenuiaAuthKey" name="whatsapp_menuia_authkey" class="form-control" value="{{ $menuiaAuthKey }}" autocomplete="new-password" placeholder="{{ $menuiaAuthKeyConfigured ? 'Credencial salva. Preencha apenas para trocar.' : '' }}">
                                        @if ($menuiaAuthKeyConfigured)
                                            <small class="text-muted">A Auth Key atual permanece salva se este campo continuar vazio.</small>
                                        @endif
                                    </div>
                                </div>

                                <div class="desktop-grid desktop-grid-two mt-3">
                                    <div>
                                        <label for="whatsappWebhookUrl">URL Webhook</label>
                                        <input type="text" id="whatsappWebhookUrl" name="whatsapp_webhook_url" class="form-control" value="{{ $webhookUrl }}" placeholder="https://seu-webhook.exemplo/endpoint">
                                    </div>

                                    <div>
                                        <label for="whatsappWebhookMethod">Método Webhook</label>
                                        <select class="form-select" id="whatsappWebhookMethod" name="whatsapp_webhook_method">
                                            @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method)
                                                <option value="{{ $method }}" @selected($method === $webhookMethod)>{{ $method }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="desktop-grid-span-2">
                                        <label for="whatsappWebhookHeaders">Headers Webhook (JSON)</label>
                                        <textarea id="whatsappWebhookHeaders" name="whatsapp_webhook_headers" class="form-control" rows="4" spellcheck="false">{{ $webhookHeaders }}</textarea>
                                    </div>

                                    <div class="desktop-grid-span-2">
                                        <label for="whatsappWebhookPayload">Payload Webhook (JSON)</label>
                                        <textarea id="whatsappWebhookPayload" name="whatsapp_webhook_payload" class="form-control" rows="5" spellcheck="false">{{ $webhookPayload }}</textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="text-muted small mt-3">
                                Os campos sensíveis são guardados apenas no backend central. O desktop só consome a API.
                            </div>
                        </div>

                        <aside
                            class="desktop-form-card desktop-config-preview"
                            data-integration-panel
                            data-route-test-connection="{{ route('configurations.integrations.test-connection') }}"
                            data-route-send-test="{{ route('configurations.integrations.send-test') }}"
                            data-route-self-check="{{ route('configurations.integrations.self-check') }}"
                            data-route-status="{{ route('configurations.integrations.gateway-status') }}"
                            data-route-qr="{{ route('configurations.integrations.gateway-qr') }}"
                            data-route-restart="{{ route('configurations.integrations.gateway-restart') }}"
                            data-route-logout="{{ route('configurations.integrations.gateway-logout') }}"
                            data-route-start="{{ route('configurations.integrations.gateway-start') }}"
                            data-connected-image="{{ $connectedPreview }}"
                        >
                            <div class="surface-card-header">
                                <div>
                                    <h4 class="surface-title">Resumo operacional</h4>
                                    <p class="surface-subtitle">Diagnóstico rápido do provider selecionado e do gateway disponível.</p>
                                </div>
                            </div>

                            <div class="desktop-config-preview-badge-row">
                                <span class="badge rounded-pill {{ $statusClass }}" data-integration-status-badge>
                                    {{ $statusLabel }}
                                </span>
                                <span class="badge rounded-pill text-bg-light border text-secondary" data-integration-provider-label>
                                    {{ $currentProvider }}
                                </span>
                            </div>

                            <div class="desktop-config-preview-box">
                                <img
                                    src="{{ $connectedPreview }}"
                                    alt="Pré-visualização do gateway"
                                    class="desktop-config-preview-image {{ $statusClass === 'text-bg-success' ? '' : 'd-none' }}"
                                    data-integration-preview-image
                                >
                                <div class="desktop-config-preview-placeholder {{ $statusClass === 'text-bg-success' ? 'd-none' : '' }}" data-integration-preview-placeholder>
                                    <i class="bi bi-whatsapp"></i>
                                    <strong>Estado do gateway</strong>
                                    <span>Use os botões abaixo para consultar status, QR Code ou iniciar o processo.</span>
                                </div>
                            </div>

                            <div class="desktop-config-preview-list">
                                <div>
                                    <span>Provider atual</span>
                                    <strong data-integration-preview-provider>{{ $currentProvider }}</strong>
                                </div>
                                <div>
                                    <span>Canal em massa</span>
                                    <strong data-integration-preview-bulk>{{ $bulkProviderLabel }}</strong>
                                </div>
                                <div>
                                    <span>Status do gateway</span>
                                    <strong data-integration-preview-status>{{ $gatewayStatusLabel }}</strong>
                                </div>
                                <div>
                                    <span>Última checagem</span>
                                    <strong data-integration-preview-last-check>{{ $lastCheckAt !== '' ? $lastCheckAt : 'Nunca' }}</strong>
                                </div>
                                <div>
                                    <span>Nome da conta</span>
                                    <strong data-integration-preview-account-name>{{ $gatewayAccountName }}</strong>
                                </div>
                                <div>
                                    <span>Número</span>
                                    <strong data-integration-preview-account-number>{{ $gatewayAccountNumber }}</strong>
                                </div>
                                <div>
                                    <span>Plataforma</span>
                                    <strong data-integration-preview-account-platform>{{ $gatewayAccountPlatform }}</strong>
                                </div>
                                <div>
                                    <span>Webhook check</span>
                                    <strong data-integration-preview-webhook>{{ $lastCheckProvider !== '' ? $lastCheckProvider : 'Nenhum' }}</strong>
                                </div>
                            </div>

                            <div class="desktop-config-preview-status" data-integration-preview-message>
                                {{ $statusMessage }}
                            </div>

                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary rounded-pill" data-config-action="status">
                                    <i class="bi bi-arrow-repeat me-1"></i>Consultar status
                                </button>
                                <button type="button" class="btn btn-outline-info rounded-pill" data-config-action="qr">
                                    <i class="bi bi-qr-code me-1"></i>Ver QR Code
                                </button>
                                <button type="button" class="btn btn-outline-secondary rounded-pill" data-config-action="restart">
                                    <i class="bi bi-bootstrap-reboot me-1"></i>Reiniciar gateway
                                </button>
                                <button type="button" class="btn btn-outline-warning rounded-pill" data-config-action="logout">
                                    <i class="bi bi-person-x me-1"></i>Desconectar
                                </button>
                                <button type="button" class="btn btn-outline-success rounded-pill" data-config-action="start">
                                    <i class="bi bi-play-fill me-1"></i>Iniciar servidor
                                </button>
                            </div>

                            <hr class="my-3">

                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-soft rounded-pill" data-config-action="test-connection">
                                    <i class="bi bi-plug me-1"></i>Testar conexão
                                </button>
                                <button type="button" class="btn btn-soft rounded-pill" data-config-action="send-test">
                                    <i class="bi bi-send me-1"></i>Enviar mensagem de teste
                                </button>
                                <button type="button" class="btn btn-soft rounded-pill" data-config-action="self-check">
                                    <i class="bi bi-shield-check me-1"></i>Self-check inbound
                                </button>
                            </div>
                        </aside>
                    </div>
                </div>

                <div class="config-subpanel" data-config-subpanel="payments">
                    <div class="desktop-config-hero">
                        <div>
                            <h3 class="surface-title mb-1">Pagamentos online</h3>
                            <p class="surface-subtitle mb-0">Credenciais do Mercado Pago e do Asaas usadas para validar cobranças online.</p>
                        </div>
                    </div>

                    <div
                        class="desktop-grid desktop-grid-two"
                        data-payments-panel
                        data-route-test-payment="{{ route('configurations.integrations.payments.test-connection') }}"
                    >
                        <div class="desktop-form-card">
                            <div class="surface-card-header">
                                <div>
                                    <h4 class="surface-title">Mercado Pago</h4>
                                    <p class="surface-subtitle">Checkout com Pix, boleto e cartões.</p>
                                </div>
                                <span class="badge rounded-pill {{ $mercadoPagoReady ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $mercadoPagoStatusLabel }}
                                </span>
                            </div>

                            <div class="desktop-grid desktop-grid-two">
                                <div>
                                    <label for="mercadoPagoEnabled">Canal habilitado</label>
                                    <select class="form-select" id="mercadoPagoEnabled" name="pagamentos_mercadopago_enabled">
                                        <option value="1" @selected((bool) $mercadoPagoEnabled)>Sim</option>
                                        <option value="0" @selected(! (bool) $mercadoPagoEnabled)>Não</option>
                                    </select>
                                </div>
                                <div></div>
                                <div class="desktop-grid-span-2">
                                    <label for="mercadoPagoPublicKey">Public Key</label>
                                    <input type="text" id="mercadoPagoPublicKey" name="pagamentos_mercadopago_public_key" class="form-control" value="{{ $mercadoPagoPublicKey }}" placeholder="APP_USR-...">
                                </div>
                                <div class="desktop-grid-span-2">
                                    <label for="mercadoPagoAccessToken">Access Token</label>
                                    <input type="password" id="mercadoPagoAccessToken" name="pagamentos_mercadopago_access_token" class="form-control" value="{{ $mercadoPagoAccessToken }}" autocomplete="new-password" placeholder="{{ $mercadoPagoAccessTokenConfigured ? 'Credencial salva. Preencha apenas para trocar.' : 'APP_USR-...' }}">
                                    @if ($mercadoPagoAccessTokenConfigured)
                                        <small class="text-muted">O Access Token atual permanece salvo se este campo continuar vazio.</small>
                                    @endif
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                                <button type="button" class="btn btn-outline-primary rounded-pill" data-payment-test="mercado_pago">
                                    <i class="bi bi-shield-check me-1"></i>Testar Mercado Pago
                                </button>
                                <small class="text-muted">Valida o Access Token consultando a conta autenticada.</small>
                            </div>
                        </div>

                        <div class="desktop-form-card">
                            <div class="surface-card-header">
                                <div>
                                    <h4 class="surface-title">Asaas</h4>
                                    <p class="surface-subtitle">Pix com QR Code, boleto e cobrança hospedada.</p>
                                </div>
                                <span class="badge rounded-pill {{ $asaasReady ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $asaasStatusLabel }}
                                </span>
                            </div>

                            <div class="desktop-grid desktop-grid-two">
                                <div>
                                    <label for="asaasEnabled">Canal habilitado</label>
                                    <select class="form-select" id="asaasEnabled" name="pagamentos_asaas_enabled">
                                        <option value="1" @selected((bool) $asaasEnabled)>Sim</option>
                                        <option value="0" @selected(! (bool) $asaasEnabled)>Não</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="asaasBillingTypeDefault">Modo padrão</label>
                                    <select class="form-select" id="asaasBillingTypeDefault" name="pagamentos_asaas_billing_type_default">
                                        <option value="PIX" @selected($asaasBillingTypeDefault === 'PIX')>PIX</option>
                                        <option value="BOLETO" @selected($asaasBillingTypeDefault === 'BOLETO')>Boleto</option>
                                        <option value="CREDIT_CARD" @selected($asaasBillingTypeDefault === 'CREDIT_CARD')>Cartão</option>
                                    </select>
                                </div>
                                <div class="desktop-grid-span-2">
                                    <label for="asaasBaseUrl">Base URL</label>
                                    <input type="text" id="asaasBaseUrl" name="pagamentos_asaas_base_url" class="form-control" value="{{ $asaasBaseUrl }}" placeholder="https://api.asaas.com/v3">
                                </div>
                                <div class="desktop-grid-span-2">
                                    <label for="asaasApiKey">API Key</label>
                                    <input type="password" id="asaasApiKey" name="pagamentos_asaas_api_key" class="form-control" value="{{ $asaasApiKey }}" autocomplete="new-password" placeholder="{{ $asaasApiKeyConfigured ? 'Credencial salva. Preencha apenas para trocar.' : '$aact_...' }}">
                                    @if ($asaasApiKeyConfigured)
                                        <small class="text-muted">A API Key atual permanece salva se este campo continuar vazio.</small>
                                    @endif
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                                <button type="button" class="btn btn-outline-primary rounded-pill" data-payment-test="asaas">
                                    <i class="bi bi-shield-check me-1"></i>Testar Asaas
                                </button>
                                <small class="text-muted">Valida a Base URL e a API Key consultando a conta do ambiente informado.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="config-subpanel" data-config-subpanel="email">
                    <div class="desktop-config-hero">
                        <div>
                            <h3 class="surface-title mb-1">E-mail (SMTP)</h3>
                            <p class="surface-subtitle mb-0">Canal usado para envio de orçamentos e recuperação de senha.</p>
                        </div>
                        <span class="badge rounded-pill {{ $emailConfigured ? 'text-bg-success' : 'text-bg-secondary' }}">
                            {{ $emailConfigured ? 'Configurado' : 'Aguardando configuração' }}
                        </span>
                    </div>

                    <div class="desktop-form-card" data-email-panel data-route-send-test="{{ route('configurations.integrations.email.send-test') }}">
                        <div class="desktop-grid desktop-grid-two">
                            <div>
                                <label for="smtpHost">Host SMTP</label>
                                <input type="text" id="smtpHost" name="smtp_host" class="form-control" value="{{ $smtpHost }}" placeholder="smtp.seudominio.com">
                            </div>
                            <div>
                                <label for="smtpPort">Porta</label>
                                <input type="number" id="smtpPort" name="smtp_port" class="form-control" min="1" max="65535" value="{{ $smtpPort }}" placeholder="587">
                            </div>
                            <div>
                                <label for="smtpCrypto">Criptografia</label>
                                <select class="form-select" id="smtpCrypto" name="smtp_crypto">
                                    <option value="auto" @selected($smtpCrypto === 'auto')>Auto (porta)</option>
                                    <option value="tls" @selected($smtpCrypto === 'tls')>TLS / STARTTLS</option>
                                    <option value="ssl" @selected($smtpCrypto === 'ssl')>SSL</option>
                                    <option value="none" @selected($smtpCrypto === 'none')>Nenhuma</option>
                                </select>
                            </div>
                            <div>
                                <label for="smtpTimeout">Timeout (s)</label>
                                <input type="number" id="smtpTimeout" name="smtp_timeout" class="form-control" min="5" max="120" value="{{ $smtpTimeout }}">
                            </div>
                            <div>
                                <label for="smtpUser">Usuário SMTP</label>
                                <input type="text" id="smtpUser" name="smtp_user" class="form-control" value="{{ $smtpUser }}" placeholder="usuario@dominio.com">
                            </div>
                            <div>
                                <label for="smtpPass">Senha SMTP</label>
                                <input type="password" id="smtpPass" name="smtp_pass" class="form-control" value="{{ $smtpPass }}" autocomplete="new-password" placeholder="{{ $smtpPassConfigured ? 'Credencial salva. Preencha apenas para trocar.' : 'Senha ou token do provedor' }}">
                                @if ($smtpPassConfigured)
                                    <small class="text-muted">A senha atual permanece salva se este campo continuar vazio.</small>
                                @endif
                            </div>
                            <div>
                                <label for="smtpFromEmail">E-mail remetente</label>
                                <input type="email" id="smtpFromEmail" name="smtp_from_email" class="form-control" value="{{ $smtpFromEmail }}" placeholder="orcamentos@dominio.com">
                            </div>
                            <div>
                                <label for="smtpFromName">Nome do remetente</label>
                                <input type="text" id="smtpFromName" name="smtp_from_name" class="form-control" value="{{ $smtpFromName }}" placeholder="Assistência Técnica">
                            </div>
                        </div>

                        <div class="desktop-grid desktop-grid-two mt-3">
                            <div>
                                <label for="smtpTestEmail">E-mail para teste</label>
                                <input type="email" id="smtpTestEmail" class="form-control" placeholder="destino@dominio.com">
                            </div>
                            <div class="d-flex align-items-end">
                                <button type="button" class="btn btn-outline-primary rounded-pill" data-email-test-send>
                                    <i class="bi bi-send me-1"></i>Enviar e-mail de teste
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="config-subpanel" data-config-subpanel="google">
                    <div class="desktop-config-hero">
                        <div>
                            <h3 class="surface-title mb-1">Portal do Cliente (Google OAuth)</h3>
                            <p class="surface-subtitle mb-0">Credenciais usadas para o login com Google quando o Portal do Cliente entrar no desktop.</p>
                        </div>
                        <span class="badge rounded-pill {{ $googleConfigured ? 'text-bg-success' : 'text-bg-secondary' }}">
                            {{ $googleConfigured ? 'Configurado' : 'Aguardando configuração' }}
                        </span>
                    </div>

                    <div class="desktop-form-card">
                        <div class="desktop-grid desktop-grid-two">
                            <div>
                                <label for="googleClientId">Google Client ID</label>
                                <input type="text" id="googleClientId" name="portal_google_client_id" class="form-control" value="{{ $googleClientId }}" placeholder="123456789-xxxx.apps.googleusercontent.com">
                            </div>
                            <div>
                                <label for="googleClientSecret">Google Client Secret</label>
                                <input type="password" id="googleClientSecret" name="portal_google_client_secret" class="form-control" value="{{ $googleClientSecret }}" autocomplete="new-password" placeholder="{{ $googleClientSecretConfigured ? 'Credencial salva. Preencha apenas para trocar.' : 'GOCSPX-...' }}">
                                @if ($googleClientSecretConfigured)
                                    <small class="text-muted">O Client Secret atual permanece salvo se este campo continuar vazio.</small>
                                @endif
                            </div>
                        </div>
                        <p class="surface-subtitle mt-3 mb-0">
                            Credenciais obtidas no Google Cloud Console. Ainda não há um fluxo de login consumindo estes valores no desktop.
                        </p>
                    </div>
                </div>

                <div class="desktop-config-footer">
                    <div class="text-muted small">
                        As alterações de todas as sub-abas são salvas juntas.
                    </div>

                    <div class="d-flex flex-wrap gap-2 justify-content-end">
                        <button type="submit" class="btn btn-primary rounded-pill">
                            <i class="bi bi-save2 me-1"></i>Salvar integrações
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <div class="modal fade" id="modalLocalGateway" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-dark text-white border-0">
                    <h5 class="modal-title d-flex align-items-center gap-2">
                        <i class="bi bi-whatsapp"></i>
                        Gerenciar Gateway
                        <span id="gatewayProviderBadge" class="badge text-bg-secondary">API Local (Windows)</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="localGatewayLoading" class="py-4 text-center">
                        <div class="spinner-border text-primary"></div>
                        <p class="mt-3 text-muted mb-0">Consultando status do gateway...</p>
                    </div>

                    <div id="localGatewayPanel" class="d-none">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="small text-muted">Status:</span>
                                <span id="gatewayStatusBadge" class="badge text-bg-secondary">-</span>
                            </div>
                            <small class="text-muted">Última verificação: <span id="gatewayLastCheck">-</span></small>
                        </div>

                        <div class="row g-3 align-items-start">
                            <div class="col-lg-5 text-center">
                                <p class="small text-muted mb-2">QR Code para autenticação</p>
                                <div class="bg-light p-3 rounded border mx-auto" style="width:256px;height:256px;display:flex;align-items:center;justify-content:center;">
                                    <img id="localQrImage" src="" alt="QR" class="img-fluid d-none">
                                    <div id="localQrPlaceholder" class="text-muted small px-2">Aguardando QR Code...</div>
                                </div>
                                <small id="gatewayQrHint" class="text-muted d-block mt-2">Escaneie o QR no WhatsApp para manter sessão ativa.</small>
                            </div>
                            <div class="col-lg-7">
                                <div class="border rounded p-3 bg-light-subtle small">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Conta:</span>
                                        <strong id="gatewayAccountName">-</strong>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <span class="text-muted">Número:</span>
                                        <span id="gatewayAccountNumber">-</span>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <span class="text-muted">Plataforma:</span>
                                        <span id="gatewayAccountPlatform">-</span>
                                    </div>
                                    <hr class="my-2">
                                    <div class="d-flex justify-content-between mt-1">
                                        <span class="text-muted">Último ready:</span>
                                        <span id="gatewayLastReady">-</span>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <span class="text-muted">Último erro:</span>
                                        <span id="gatewayLastError">-</span>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <span class="text-muted">Sessão:</span>
                                        <span id="gatewaySessionPath">-</span>
                                    </div>
                                </div>
                                <div id="localGatewayErrorDetails" class="alert alert-danger mt-3 py-2 px-3 d-none mb-0 small"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-primary" id="btnRefreshLocal">
                        <i class="bi bi-arrow-repeat me-1"></i>Atualizar status
                    </button>
                    <button type="button" class="btn btn-outline-danger d-none" id="btnLogoutLocal">
                        <i class="bi bi-person-x me-1"></i>Desconectar / Trocar Número
                    </button>
                    <button type="button" class="btn btn-success d-none" id="btnStartLocal">
                        <i class="bi bi-play-fill me-1"></i>Iniciar Servidor
                    </button>
                    <button type="button" class="btn btn-outline-warning" id="btnRestartLocal">
                        <i class="bi bi-bootstrap-reboot me-1"></i>Reiniciar Inicialização
                    </button>
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/configurations-integrations.js') }}?v={{ filemtime(public_path('assets/js/configurations-integrations.js')) }}"></script>
@endsection
