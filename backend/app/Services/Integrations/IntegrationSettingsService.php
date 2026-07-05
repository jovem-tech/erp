<?php

namespace App\Services\Integrations;

use App\Models\Configuration;
use App\Support\SecretSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IntegrationSettingsService
{
    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'whatsapp_enabled' => '0',
        'whatsapp_direct_provider' => 'api_whats_local',
        'whatsapp_bulk_provider' => 'meta_oficial',
        'whatsapp_test_phone' => '',
        'whatsapp_menuia_url' => 'https://chatbot.menuia.com/api',
        'whatsapp_menuia_appkey' => '',
        'whatsapp_menuia_authkey' => '',
        'whatsapp_webhook_token' => '',
        'whatsapp_evolution_url' => 'http://127.0.0.1:8080',
        'whatsapp_evolution_apikey' => '',
        'whatsapp_evolution_instance' => '',
        'whatsapp_evolution_timeout' => '20',
        'whatsapp_evolution_sync_avatar' => '1',
        'whatsapp_local_node_url' => 'http://127.0.0.1:3001',
        'whatsapp_local_node_token' => '',
        'whatsapp_local_node_origin' => '',
        'whatsapp_local_node_timeout' => '20',
        'whatsapp_linux_node_url' => 'http://127.0.0.1:3001',
        'whatsapp_linux_node_token' => '',
        'whatsapp_linux_node_origin' => '',
        'whatsapp_linux_node_timeout' => '20',
        'whatsapp_webhook_url' => '',
        'whatsapp_webhook_method' => 'POST',
        'whatsapp_webhook_headers' => '{}',
        'whatsapp_webhook_payload' => '{"to":"{{phone}}","message":"{{message}}"}',
        'whatsapp_last_check_provider' => '',
        'whatsapp_last_check_status' => '',
        'whatsapp_last_check_message' => '',
        'whatsapp_last_check_at' => '',
        'whatsapp_last_check_signature' => '',
    ];

    /**
     * @var array<int, string>
     */
    private const DIRECT_PROVIDERS = [
        'menuia',
        'evolution',
        'api_whats_local',
        'api_whats_linux',
        'webhook',
    ];

    /**
     * @var array<int, string>
     */
    private const BULK_PROVIDERS = [
        'meta_oficial',
        'menuia',
        'evolution',
        'api_whats_local',
        'api_whats_linux',
        'webhook',
    ];

    /**
     * @var array<int, string>
     */
    private const SECRET_KEYS = [
        'whatsapp_menuia_appkey',
        'whatsapp_menuia_authkey',
        'whatsapp_webhook_token',
        'whatsapp_evolution_apikey',
        'whatsapp_local_node_token',
        'whatsapp_linux_node_token',
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $settings = $this->loadSettings();
        $maskedSettings = SecretSettings::blank($settings, self::SECRET_KEYS);

        return [
            'settings' => $maskedSettings,
            'secret_status' => SecretSettings::status($settings, self::SECRET_KEYS),
            'summary' => $this->buildSummary($settings),
            'provider_options' => [
                'direct' => [
                    ['value' => 'api_whats_local', 'label' => 'API local'],
                    ['value' => 'api_whats_linux', 'label' => 'API Linux'],
                    ['value' => 'menuia', 'label' => 'Menuia'],
                    ['value' => 'evolution', 'label' => 'Evolution API'],
                    ['value' => 'webhook', 'label' => 'Webhook'],
                ],
                'bulk' => [
                    ['value' => 'meta_oficial', 'label' => 'Meta Oficial (futuro)'],
                    ['value' => 'menuia', 'label' => 'Menuia'],
                    ['value' => 'evolution', 'label' => 'Evolution API'],
                    ['value' => 'api_whats_local', 'label' => 'API local'],
                    ['value' => 'api_whats_linux', 'label' => 'API Linux'],
                    ['value' => 'webhook', 'label' => 'Webhook'],
                ],
                'webhook_method' => [
                    ['value' => 'GET', 'label' => 'GET'],
                    ['value' => 'POST', 'label' => 'POST'],
                    ['value' => 'PUT', 'label' => 'PUT'],
                    ['value' => 'PATCH', 'label' => 'PATCH'],
                    ['value' => 'DELETE', 'label' => 'DELETE'],
                ],
            ],
            'gateway' => [
                'local' => $this->sanitizeGatewayConfig($this->gatewayConfig($settings, 'api_whats_local')),
                'linux' => $this->sanitizeGatewayConfig($this->gatewayConfig($settings, 'api_whats_linux')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $current = $this->loadSettings();
        $normalized = $this->normalizePayload($payload, $current);

        foreach ($normalized as $key => $value) {
            $this->upsert((string) $key, (string) $value, $this->fieldType((string) $key));
        }

        if ($this->connectionSignature($current) !== $this->connectionSignature(array_merge($current, $normalized))) {
            $this->clearLastCheck();
        }

        return $this->payload();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function testConnection(array $payload = []): array
    {
        $settings = $this->mergeSettings($payload);
        $provider = $this->resolveDirectProvider($settings, (string) ($payload['provider'] ?? ''));
        $phone = trim((string) ($payload['telefone'] ?? $settings['whatsapp_test_phone'] ?? ''));

        if ($provider === 'menuia') {
            return $this->testMenuiaConnection($settings, $phone);
        }

        if ($provider === 'evolution') {
            return $this->testEvolutionConnection($settings, $phone);
        }

        if (in_array($provider, ['api_whats_local', 'api_whats_linux'], true)) {
            return $this->testGatewayConnection($settings, $provider, $phone);
        }

        if ($provider === 'webhook') {
            return $this->testWebhookConnection($settings, $phone);
        }

        return $this->failureResponse('Provider de integração não reconhecido.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendTestMessage(array $payload = []): array
    {
        $settings = $this->mergeSettings($payload);
        $provider = $this->resolveDirectProvider($settings, (string) ($payload['provider'] ?? ''));
        $phone = trim((string) ($payload['telefone'] ?? $settings['whatsapp_test_phone'] ?? ''));
        $message = trim((string) ($payload['mensagem'] ?? ''));

        if ($phone === '') {
            return $this->failureResponse('Informe o telefone de teste.');
        }

        if ($message === '') {
            $message = '[Teste de integração] Mensagem de teste enviada pelo ERP.';
        }

        if ($provider === 'menuia') {
            return $this->sendMenuiaText($settings, $phone, $message);
        }

        if ($provider === 'evolution') {
            return $this->sendEvolutionText($settings, $phone, $message);
        }

        if (in_array($provider, ['api_whats_local', 'api_whats_linux'], true)) {
            return $this->sendGatewayText($settings, $provider, $phone, $message);
        }

        if ($provider === 'webhook') {
            return $this->sendWebhookText($settings, $phone, $message);
        }

        return $this->failureResponse('Provider de integração não reconhecido.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function selfCheckInbound(array $payload = []): array
    {
        $settings = $this->mergeSettings($payload);
        $provider = $this->resolveDirectProvider($settings, (string) ($payload['provider'] ?? ''));

        if (! in_array($provider, ['api_whats_local', 'api_whats_linux'], true)) {
            return $this->failureResponse('O self-check inbound exige API local ou API Linux.');
        }

        $gatewayStatus = $this->gatewayStatus($settings, $provider);
        $gatewayForward = $this->gatewayForwardSelfCheck($settings, $provider);
        $webhookDirect = $this->directWebhookSelfCheck($settings);
        $originAlignment = $this->originAlignment($settings, $provider);

        $ok = (bool) ($gatewayStatus['ok'] ?? false)
            && (bool) ($gatewayForward['ok'] ?? false)
            && (bool) ($webhookDirect['ok'] ?? false)
            && (bool) ($originAlignment['ok'] ?? false);

        return [
            'ok' => $ok,
            'message' => $ok
                ? 'Self-check inbound validado com sucesso.'
                : 'Falha na validação inbound.',
            'checks' => [
                'gateway_status' => $gatewayStatus,
                'gateway_forward' => $gatewayForward,
                'webhook_direct' => $webhookDirect,
                'origin_alignment' => $originAlignment,
            ],
            'meta' => [
                'provider' => $provider,
                'expected_webhook_url' => rtrim((string) config('app.url'), '/') . '/webhooks/whatsapp',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function gatewayStatus(array $payload = [], string $provider = ''): array
    {
        $settings = $this->mergeSettings($payload);
        $gateway = $this->gatewayConfig($settings, $provider);

        return $this->callGateway('GET', '/status', [], $gateway);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function gatewayQr(array $payload = [], string $provider = ''): array
    {
        $settings = $this->mergeSettings($payload);
        $gateway = $this->gatewayConfig($settings, $provider);

        return $this->callGateway('GET', '/qr', [], $gateway);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function gatewayRestart(array $payload = [], string $provider = '', bool $clean = false): array
    {
        $settings = $this->mergeSettings($payload);
        $gateway = $this->gatewayConfig($settings, $provider);

        return $this->callGateway('POST', '/restart', ['clean' => $clean], $gateway);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function gatewayLogout(array $payload = [], string $provider = ''): array
    {
        $settings = $this->mergeSettings($payload);
        $gateway = $this->gatewayConfig($settings, $provider);

        return $this->callGateway('POST', '/logout', [], $gateway);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function gatewayStart(array $payload = [], string $provider = ''): array
    {
        $settings = $this->mergeSettings($payload);
        $gateway = $this->gatewayConfig($settings, $provider);

        $path = trim((string) config('services.whatsapp.gateway_path', base_path('../sistema-hml/whatsapp-api')));
        if ($path === '' || ! is_dir($path)) {
            return $this->failureResponse('Pasta whatsapp-api não encontrada.', [
                'path' => $path,
            ]);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'cd /d ' . escapeshellarg($path) . ' && start /B node server.js > boot.out.log 2> boot.err.log';
            @pclose(@popen($cmd, 'r'));

            return [
                'ok' => true,
                'provider' => $gateway['provider'],
                'message' => 'Comando de inicialização enviado para o Windows. Aguarde alguns segundos.',
                'status_code' => 200,
                'response' => [
                    'path' => $path,
                ],
            ];
        }

        $output = [];
        $status = null;
        exec('pm2 restart whatsapp-gateway 2>&1', $output, $status);

        return [
            'ok' => $status === 0,
            'provider' => $gateway['provider'],
            'message' => $status === 0
                ? 'Comando PM2 executado com sucesso.'
                : 'Falha ao executar PM2.',
            'status_code' => $status === 0 ? 200 : 500,
            'response' => [
                'output' => $output,
                'status' => $status,
            ],
        ];
    }

    public function webhookToken(): string
    {
        return (string) ($this->loadSettings()['whatsapp_webhook_token'] ?? '');
    }

    /**
     * @return array<int, string>
     */
    public function trustedInboundMediaOrigins(): array
    {
        return $this->originsFromSettings($this->loadSettings());
    }

    /**
     * Envia uma mensagem de texto real (nao de teste de conexao) usando o provider direto
     * configurado. Usado pelo canal WhatsApp da Central de Atendimento
     * (App\Services\Channels\Whatsapp\WhatsappChannelDriver) para nao duplicar a
     * integracao HTTP com cada provider.
     *
     * @return array<string, mixed>
     */
    public function sendDirectMessage(string $phone, string $message): array
    {
        $phone = trim($phone);
        $message = trim($message);

        if ($phone === '') {
            return $this->failureResponse('Telefone do destinatário não informado.');
        }

        if ($message === '') {
            return $this->failureResponse('Mensagem vazia.');
        }

        $settings = $this->loadSettings();
        $provider = $this->resolveDirectProvider($settings);

        if ($provider === 'menuia') {
            return $this->sendMenuiaText($settings, $phone, $message);
        }

        if ($provider === 'evolution') {
            return $this->sendEvolutionText($settings, $phone, $message);
        }

        if (in_array($provider, ['api_whats_local', 'api_whats_linux'], true)) {
            return $this->sendGatewayText($settings, $provider, $phone, $message);
        }

        if ($provider === 'webhook') {
            return $this->sendWebhookText($settings, $phone, $message);
        }

        return $this->failureResponse('Provider de integração não reconhecido.');
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDirectMedia(
        string $phone,
        string $absoluteFilePath,
        string $mediaType,
        ?string $caption = null,
        ?string $fileName = null
    ): array {
        $phone = trim($phone);
        $absoluteFilePath = trim($absoluteFilePath);
        $mediaType = trim($mediaType);

        if ($phone === '') {
            return $this->failureResponse('Telefone do destinatário não informado.');
        }

        if ($absoluteFilePath === '' || ! is_file($absoluteFilePath)) {
            return $this->failureResponse('Arquivo de mídia não encontrado para envio.');
        }

        if (! in_array($mediaType, ['image', 'audio', 'video', 'document'], true)) {
            return $this->failureResponse('Tipo de mídia inválido para envio.');
        }

        $settings = $this->loadSettings();
        $provider = $this->resolveDirectProvider($settings);

        if ($provider === 'evolution') {
            return $this->sendEvolutionMedia($settings, $phone, $absoluteFilePath, $mediaType, $caption, $fileName);
        }

        return $this->failureResponse('Envio de mídia disponível apenas via Evolution API nesta fase.', [
            'provider' => $provider,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function mergeSettings(array $payload = []): array
    {
        $current = $this->loadSettings();

        if ($payload === []) {
            return $current;
        }

        return array_merge($current, $this->normalizePayload($payload, $current));
    }

    /**
     * @return array<string, string>
     */
    private function loadSettings(): array
    {
        $stored = Configuration::query()
            ->whereIn('chave', array_keys(self::DEFAULTS))
            ->pluck('valor', 'chave')
            ->all();

        return array_merge(self::DEFAULTS, array_map(
            static fn ($value): string => trim((string) $value),
            is_array($stored) ? $stored : []
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $current
     * @return array<string, string>
     */
    private function normalizePayload(array $payload, array $current): array
    {
        $normalized = [];

        foreach (self::DEFAULTS as $key => $defaultValue) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if ($this->isBooleanKey($key)) {
                $normalized[$key] = $this->normalizeBoolean($value);
                continue;
            }

            if ($this->isIntegerKey($key)) {
                $normalized[$key] = (string) $this->normalizeInteger($value, (int) $defaultValue);
                continue;
            }

            if ($key === 'whatsapp_direct_provider') {
                $normalized[$key] = $this->resolveDirectProvider($current, (string) $value);
                continue;
            }

            if ($key === 'whatsapp_bulk_provider') {
                $normalized[$key] = $this->resolveBulkProvider((string) $value);
                continue;
            }

            if ($key === 'whatsapp_menuia_url') {
                $normalized[$key] = $this->normalizeMenuiaUrl((string) $value);
                continue;
            }

            if ($key === 'whatsapp_evolution_url') {
                $normalized[$key] = $this->normalizeEvolutionUrl((string) $value);
                continue;
            }

            if (in_array($key, ['whatsapp_local_node_url', 'whatsapp_linux_node_url'], true)) {
                $normalized[$key] = $this->normalizeUrl((string) $value, (string) $defaultValue);
                continue;
            }

            if ($key === 'whatsapp_webhook_method') {
                $normalized[$key] = $this->normalizeWebhookMethod((string) $value);
                continue;
            }

            $normalized[$key] = $this->normalizeString($value, (string) $defaultValue);
        }

        $normalized['whatsapp_local_node_origin'] = $this->normalizeOrigin(
            (string) ($payload['whatsapp_local_node_origin'] ?? $current['whatsapp_local_node_origin'] ?? '')
        );
        $normalized['whatsapp_linux_node_origin'] = $this->normalizeOrigin(
            (string) ($payload['whatsapp_linux_node_origin'] ?? $current['whatsapp_linux_node_origin'] ?? '')
        );

        return SecretSettings::preserveExisting($normalized, $current, self::SECRET_KEYS);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildSummary(array $settings): array
    {
        $provider = $this->resolveDirectProvider($settings);
        $enabled = $this->isTruthy((string) ($settings['whatsapp_enabled'] ?? '0'));
        $ready = $this->providerReady($settings, $provider);
        $signature = $this->connectionSignature($settings);
        $lastSignature = trim((string) ($settings['whatsapp_last_check_signature'] ?? ''));
        $matchesCurrent = $signature !== '' && $lastSignature !== '' && hash_equals($signature, $lastSignature);
        $lastStatus = trim((string) ($settings['whatsapp_last_check_status'] ?? ''));
        $lastMessage = trim((string) ($settings['whatsapp_last_check_message'] ?? ''));
        $lastAt = trim((string) ($settings['whatsapp_last_check_at'] ?? ''));

        $badge = [
            'class' => 'secondary',
            'label' => 'Não validado',
            'message' => 'Ainda não houve validação da conectividade para o provider atual.',
        ];

        if (! $enabled) {
            $badge = [
                'class' => 'secondary',
                'label' => 'Envio desabilitado',
                'message' => 'O envio de WhatsApp está desabilitado nas configurações.',
            ];
        } elseif (in_array($provider, ['api_whats_local', 'api_whats_linux'], true)) {
            $badge = [
                'class' => 'dark',
                'label' => 'Provider local',
                'message' => 'Gateway local selecionado.',
            ];
        } elseif ($provider === 'menuia' && $matchesCurrent) {
            $badge = $lastStatus === 'success'
                ? [
                    'class' => 'success',
                    'label' => 'Menuia conectada',
                    'message' => $this->buildLastCheckMessage($lastAt, $lastMessage),
                ]
                : [
                    'class' => 'danger',
                    'label' => 'Erro Menuia',
                    'message' => $this->buildLastCheckMessage($lastAt, $lastMessage),
                ];
        } elseif ($provider === 'menuia' && $ready) {
            $badge = [
                'class' => 'warning',
                'label' => 'Menuia não validada',
                'message' => 'As credenciais foram preenchidas, mas a conexão ainda não foi validada.',
            ];
        } elseif ($provider === 'evolution' && $matchesCurrent) {
            $badge = $lastStatus === 'success'
                ? [
                    'class' => 'success',
                    'label' => 'Evolution conectada',
                    'message' => $this->buildLastCheckMessage($lastAt, $lastMessage),
                ]
                : [
                    'class' => 'danger',
                    'label' => 'Erro Evolution',
                    'message' => $this->buildLastCheckMessage($lastAt, $lastMessage),
                ];
        } elseif ($provider === 'evolution' && $ready) {
            $badge = [
                'class' => 'warning',
                'label' => 'Evolution não validada',
                'message' => 'A URL, API key e instância foram preenchidas, mas a conexão ainda não foi validada.',
            ];
        } elseif ($provider === 'webhook') {
            $badge = [
                'class' => 'dark',
                'label' => 'Provider externo',
                'message' => 'Webhook externo selecionado.',
            ];
        }

        return [
            'enabled' => $enabled,
            'provider' => $provider,
            'provider_label' => $this->providerLabel($provider),
            'bulk_provider' => (string) ($settings['whatsapp_bulk_provider'] ?? 'meta_oficial'),
            'bulk_provider_label' => $this->bulkProviderLabel((string) ($settings['whatsapp_bulk_provider'] ?? 'meta_oficial')),
            'ready' => $ready,
            'status' => $badge['class'],
            'status_label' => $badge['label'],
            'status_message' => $badge['message'],
            'last_check_provider' => trim((string) ($settings['whatsapp_last_check_provider'] ?? '')),
            'last_check_status' => $lastStatus ?: null,
            'last_check_message' => $lastMessage ?: null,
            'last_check_at' => $lastAt ?: null,
            'last_check_matches_current_credentials' => $matchesCurrent,
            'signature' => $signature,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function gatewayConfig(array $settings, string $provider = ''): array
    {
        $selected = $this->resolveGatewayProvider($settings, $provider);
        $isLinux = $selected === 'api_whats_linux';

        return [
            'provider' => $selected,
            'url' => rtrim((string) ($settings[$isLinux ? 'whatsapp_linux_node_url' : 'whatsapp_local_node_url'] ?? ''), '/'),
            'token' => trim((string) ($settings[$isLinux ? 'whatsapp_linux_node_token' : 'whatsapp_local_node_token'] ?? '')),
            'origin' => $this->normalizeOrigin((string) ($settings[$isLinux ? 'whatsapp_linux_node_origin' : 'whatsapp_local_node_origin'] ?? '')),
            'timeout' => max(5, (int) ($settings[$isLinux ? 'whatsapp_linux_node_timeout' : 'whatsapp_local_node_timeout'] ?? 20)),
        ];
    }

    /**
     * @param array<string, mixed> $gateway
     * @return array<string, mixed>
     */
    private function sanitizeGatewayConfig(array $gateway): array
    {
        unset($gateway['token']);

        return $gateway;
    }

    /**
     * @param array<string, mixed> $settings
     * @param string $provider
     * @return array<string, mixed>
     */
    private function testMenuiaConnection(array $settings, string $phone): array
    {
        $baseUrl = trim((string) ($settings['whatsapp_menuia_url'] ?? ''));
        $appKey = trim((string) ($settings['whatsapp_menuia_appkey'] ?? ''));
        $authKey = trim((string) ($settings['whatsapp_menuia_authkey'] ?? ''));

        if ($baseUrl === '' || $appKey === '' || $authKey === '') {
            return $this->failureResponse('Configuração da Menuia incompleta (URL, Appkey ou Authkey).');
        }

        if ($phone !== '') {
            return $this->sendMenuiaText($settings, $phone, '[Teste de integração] Conexão Menuia ativa em ' . now()->format('d/m/Y H:i:s') . '.');
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get($baseUrl);

            return [
                'ok' => $response->status() > 0 && $response->status() < 500,
                'provider' => 'menuia',
                'status_code' => $response->status(),
                'message' => $response->status() > 0 && $response->status() < 500
                    ? 'Endpoint da Menuia acessível.'
                    : 'Endpoint da Menuia indisponível.',
                'response' => $response->json() ?? ['raw' => $response->body()],
            ];
        } catch (Throwable $throwable) {
            return $this->failureResponse('Falha de rede ao conectar na Menuia: ' . $throwable->getMessage(), [
                'provider' => 'menuia',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function testEvolutionConnection(array $settings, string $phone): array
    {
        $baseUrl = trim((string) ($settings['whatsapp_evolution_url'] ?? ''));
        $apiKey = trim((string) ($settings['whatsapp_evolution_apikey'] ?? ''));
        $instance = trim((string) ($settings['whatsapp_evolution_instance'] ?? ''));

        if ($baseUrl === '' || $apiKey === '' || $instance === '') {
            return $this->failureResponse('Configuração da Evolution incompleta (URL, API key ou instância).');
        }

        if ($phone !== '') {
            return $this->sendEvolutionText($settings, $phone, '[Teste de integração] Conexão Evolution ativa em ' . now()->format('d/m/Y H:i:s') . '.');
        }

        $response = $this->httpClient((int) ($settings['whatsapp_evolution_timeout'] ?? 20))
            ->withHeaders(['apikey' => $apiKey])
            ->get(rtrim($baseUrl, '/') . '/instance/connectionState/' . rawurlencode($instance));

        $payload = $response->json();
        $state = strtolower(trim((string) data_get($payload, 'response.instance.state', 'unknown')));
        $ok = in_array($state, ['open', 'connecting'], true);

        return [
            'ok' => $ok,
            'provider' => 'evolution',
            'status_code' => $response->status(),
            'message' => $ok
                ? 'Evolution acessível. Estado atual da instância: ' . $state . '.'
                : 'Evolution acessível, mas a instância está em estado "' . $state . '".',
            'response' => $payload ?? ['raw' => $response->body()],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function testGatewayConnection(array $settings, string $provider, string $phone): array
    {
        $gateway = $this->gatewayConfig($settings, $provider);

        if ($phone !== '') {
            return $this->sendGatewayText($settings, $provider, $phone, '[Teste de integração] Conexão do gateway ativa em ' . now()->format('d/m/Y H:i:s') . '.');
        }

        $response = $this->callGateway('GET', '/status', [], $gateway);

        if (! ($response['ok'] ?? false)) {
            return $response;
        }

        $ready = (bool) data_get($response, 'response.data.ready', false);
        $status = (string) data_get($response, 'response.status', 'unknown');

        $response['ok'] = $ready;
        $response['message'] = $ready
            ? ucfirst($this->providerLabel($provider)) . ' conectada e pronta para envio.'
            : ucfirst($this->providerLabel($provider)) . ' acessível, status atual: ' . $status . '.';

        return $response;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function testWebhookConnection(array $settings, string $phone): array
    {
        $url = trim((string) ($settings['whatsapp_webhook_url'] ?? ''));
        if ($url === '') {
            return $this->failureResponse('URL do Webhook não configurada.', ['provider' => 'webhook']);
        }

        if ($phone !== '') {
            return $this->sendWebhookText($settings, $phone, '[Teste de integração] Conexão via Webhook ativa em ' . now()->format('d/m/Y H:i:s') . '.');
        }

        return [
            'ok' => true,
            'provider' => 'webhook',
            'status_code' => 200,
            'message' => 'Configuração de Webhook pronta.',
            'response' => [
                'url' => $url,
                'method' => $this->normalizeWebhookMethod((string) ($settings['whatsapp_webhook_method'] ?? 'POST')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function sendMenuiaText(array $settings, string $phone, string $message): array
    {
        $baseUrl = trim((string) ($settings['whatsapp_menuia_url'] ?? ''));
        $appKey = trim((string) ($settings['whatsapp_menuia_appkey'] ?? ''));
        $authKey = trim((string) ($settings['whatsapp_menuia_authkey'] ?? ''));

        if ($baseUrl === '' || $appKey === '' || $authKey === '') {
            return $this->failureResponse('Configuração da Menuia incompleta (URL, Appkey ou Authkey).');
        }

        $response = Http::asForm()
            ->timeout(20)
            ->acceptJson()
            ->post(rtrim($baseUrl, '/') . '/create-message', [
                'to' => $this->normalizeMenuiaPhone($phone),
                'appkey' => $appKey,
                'authkey' => $authKey,
                'message' => $message,
                'licence' => 'hugocursos',
                'sandbox' => 'false',
            ]);

        $payload = $response->json();
        $ok = $response->status() >= 200 && $response->status() < 300 && (bool) data_get($payload, 'success', true);
        $apiMessage = (string) data_get($payload, 'message', data_get($payload, 'error', ''));

        return [
            'ok' => $ok,
            'provider' => 'menuia',
            'status_code' => $response->status(),
            'message' => $ok
                ? 'Mensagem enviada com sucesso.'
                : ($apiMessage !== '' ? $apiMessage : 'Falha no envio pela API Menuia.'),
            'response' => $payload ?? ['raw' => $response->body()],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function sendEvolutionText(array $settings, string $phone, string $message): array
    {
        $baseUrl = trim((string) ($settings['whatsapp_evolution_url'] ?? ''));
        $apiKey = trim((string) ($settings['whatsapp_evolution_apikey'] ?? ''));
        $instance = trim((string) ($settings['whatsapp_evolution_instance'] ?? ''));

        if ($baseUrl === '' || $apiKey === '' || $instance === '') {
            return $this->failureResponse('Configuração da Evolution incompleta (URL, API key ou instância).');
        }

        $response = $this->httpClient((int) ($settings['whatsapp_evolution_timeout'] ?? 20))
            ->withHeaders(['apikey' => $apiKey])
            ->post(rtrim($baseUrl, '/') . '/message/sendText/' . rawurlencode($instance), [
                'number' => $this->normalizePhone($phone),
                'text' => trim($message),
            ]);

        $payload = $response->json();
        $ok = $response->status() >= 200 && $response->status() < 300;

        return [
            'ok' => $ok,
            'provider' => 'evolution',
            'status_code' => $response->status(),
            'message' => $ok
                ? 'Operação realizada com sucesso na Evolution API.'
                : $this->extractErrorMessage($payload, $response->status()),
            'response' => $payload ?? ['raw' => $response->body()],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function sendEvolutionMedia(
        array $settings,
        string $phone,
        string $absoluteFilePath,
        string $mediaType,
        ?string $caption = null,
        ?string $fileName = null
    ): array {
        $baseUrl = trim((string) ($settings['whatsapp_evolution_url'] ?? ''));
        $apiKey = trim((string) ($settings['whatsapp_evolution_apikey'] ?? ''));
        $instance = trim((string) ($settings['whatsapp_evolution_instance'] ?? ''));

        if ($baseUrl === '' || $apiKey === '' || $instance === '') {
            return $this->failureResponse('Configuração da Evolution incompleta (URL, API key ou instância).');
        }

        $resolvedFileName = trim((string) ($fileName ?? ''));
        if ($resolvedFileName === '') {
            $resolvedFileName = basename($absoluteFilePath);
        }

        $rawMedia = file_get_contents($absoluteFilePath);
        if ($rawMedia === false) {
            return $this->failureResponse('Não foi possível ler o arquivo de mídia para envio.');
        }

        // A Evolution API espera o arquivo em base64 no corpo JSON; envio multipart retorna erro 500.
        $response = $this->httpClient((int) ($settings['whatsapp_evolution_timeout'] ?? 20))
            ->withHeaders(['apikey' => $apiKey])
            ->post(rtrim($baseUrl, '/') . '/message/sendMedia/' . rawurlencode($instance), array_filter([
                'number' => $this->normalizePhone($phone),
                'mediatype' => $mediaType,
                'mimetype' => $this->detectMimeType($absoluteFilePath),
                'caption' => trim((string) ($caption ?? '')),
                'media' => base64_encode($rawMedia),
                'fileName' => $resolvedFileName,
            ], static fn ($value): bool => $value !== ''));

        $payload = $response->json();
        $ok = $response->status() >= 200 && $response->status() < 300;

        return [
            'ok' => $ok,
            'provider' => 'evolution',
            'status_code' => $response->status(),
            'message' => $ok
                ? 'Operação realizada com sucesso na Evolution API.'
                : $this->extractErrorMessage($payload, $response->status()),
            'response' => $payload ?? ['raw' => $response->body()],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function sendGatewayText(array $settings, string $provider, string $phone, string $message): array
    {
        $gateway = $this->gatewayConfig($settings, $provider);
        $payload = [
            'to' => $this->normalizePhone($phone),
            'number' => $this->normalizePhone($phone),
            'message' => trim($message),
        ];

        return $this->callGateway('POST', '/create-message', $payload, $gateway);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function sendWebhookText(array $settings, string $phone, string $message): array
    {
        $url = trim((string) ($settings['whatsapp_webhook_url'] ?? ''));
        if ($url === '') {
            return $this->failureResponse('URL do Webhook não configurada.', ['provider' => 'webhook']);
        }

        $headers = $this->parseWebhookHeaders((string) ($settings['whatsapp_webhook_headers'] ?? '{}'));
        $payload = $this->renderWebhookPayload((string) ($settings['whatsapp_webhook_payload'] ?? ''), $phone, $message);

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders($headers)
            ->withBody($payload, 'application/json')
            ->send($this->normalizeWebhookMethod((string) ($settings['whatsapp_webhook_method'] ?? 'POST')), $url);

        $json = $response->json();

        return [
            'ok' => $response->status() >= 200 && $response->status() < 300,
            'provider' => 'webhook',
            'status_code' => $response->status(),
            'message' => $response->status() >= 200 && $response->status() < 300
                ? 'Requisição enviada com sucesso.'
                : ('Falha no Webhook: ' . ($json['message'] ?? $response->body())),
            'response' => $json ?? ['raw' => $response->body()],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function gatewayForwardSelfCheck(array $settings, string $provider): array
    {
        $gateway = $this->gatewayConfig($settings, $provider);
        $payload = [
            'self_check' => true,
            'source' => 'erp_direct_self_check',
            'timestamp' => gmdate('c'),
        ];

        return $this->callGateway('POST', '/self-check-inbound', $payload, $gateway);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function directWebhookSelfCheck(array $settings): array
    {
        $webhookUrl = rtrim((string) config('app.url'), '/') . '/webhooks/whatsapp';
        $token = trim((string) ($settings['whatsapp_webhook_token'] ?? ''));

        if ($token === '') {
            return $this->failureResponse('Configure o token do webhook antes de executar o self-check inbound.', [
                'url' => $webhookUrl,
            ]);
        }

        $payload = [
            'self_check' => true,
            'source' => 'erp_direct_self_check',
            'timestamp' => gmdate('c'),
        ];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Webhook-Self-Check' => '1',
        ];

        if ($token !== '') {
            $headers['X-Webhook-Token'] = $token;
        }

        try {
            $response = $this->httpClient(10)
                ->acceptJson()
                ->withHeaders($headers)
                ->post($webhookUrl, $payload);

            return [
                'ok' => $response->status() >= 200 && $response->status() < 300,
                'message' => $response->status() >= 200 && $response->status() < 300
                    ? 'Webhook do ERP respondeu com sucesso.'
                    : 'Webhook do ERP respondeu com falha.',
                'url' => $webhookUrl,
                'status_code' => $response->status(),
                'response' => $response->json() ?? ['raw' => $response->body()],
            ];
        } catch (Throwable $throwable) {
            return $this->failureResponse('Falha ao executar POST direto no webhook: ' . $throwable->getMessage(), [
                'url' => $webhookUrl,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function originAlignment(array $settings, string $provider): array
    {
        $currentOrigin = $this->gatewayConfig($settings, $provider)['origin'] ?? '';
        $expected = rtrim((string) config('app.url'), '/');

        return [
            'ok' => $currentOrigin !== '' && $expected !== '' && hash_equals($expected, rtrim($currentOrigin, '/')),
            'expected' => $expected,
            'configured' => $currentOrigin,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string>|null $gateway
     * @return array<string, mixed>
     */
    private function callGateway(string $method, string $path, array $payload = [], ?array $gateway = null): array
    {
        $gateway = $gateway ?? $this->gatewayConfig($this->loadSettings());
        $url = rtrim((string) ($gateway['url'] ?? ''), '/') . $path;
        $timeout = max(5, (int) ($gateway['timeout'] ?? 20));
        $headers = [
            'Accept' => 'application/json',
        ];

        $client = $this->httpClient($timeout);

        if ($gateway['token'] !== '') {
            $headers['X-Api-Token'] = (string) $gateway['token'];
            $headers['Authorization'] = 'Bearer ' . (string) $gateway['token'];
        }

        if ($gateway['origin'] !== '') {
            $headers['X-ERP-Origin'] = (string) $gateway['origin'];
            $headers['Origin'] = (string) $gateway['origin'];
        }

        try {
            $request = $client->withHeaders($headers);

            if (strtoupper($method) === 'GET') {
                $response = $request->get($url);
            } else {
                $response = $request->post($url, $payload);
            }

            return $this->normalizeGatewayResponse($response, (string) $gateway['provider']);
        } catch (Throwable $throwable) {
            return $this->failureResponse('Servidor do gateway inacessível.', [
                'provider' => (string) $gateway['provider'],
                'error' => $throwable->getMessage(),
                'url' => $url,
            ]);
        }
    }

    private function normalizeGatewayResponse(Response $response, string $provider): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            $decoded = [
                'raw' => $response->body(),
            ];
        }

        $success = $response->status() >= 200 && $response->status() < 300;
        $message = (string) data_get($decoded, 'message', '');

        return [
            'ok' => $success && (bool) data_get($decoded, 'success', true),
            'provider' => $provider,
            'status_code' => $response->status(),
            'message' => $success
                ? ($message !== '' ? $message : 'Operação realizada com sucesso.')
                : ($message !== '' ? $message : 'Falha ao comunicar com o gateway.'),
            'response' => $decoded,
        ];
    }

    private function httpClient(int $timeout = 20)
    {
        return Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout(min(10, $timeout));
    }

    private function resolveDirectProvider(array $settings, string $override = ''): string
    {
        $provider = $this->normalizeProvider($override !== '' ? $override : (string) ($settings['whatsapp_direct_provider'] ?? 'api_whats_local'), self::DIRECT_PROVIDERS, 'api_whats_local');

        return $provider;
    }

    private function resolveBulkProvider(string $provider): string
    {
        return $this->normalizeProvider($provider, self::BULK_PROVIDERS, 'meta_oficial');
    }

    private function resolveGatewayProvider(array $settings, string $provider = ''): string
    {
        $selected = $this->resolveDirectProvider($settings, $provider);

        if ($selected === 'api_whats_linux') {
            return 'api_whats_linux';
        }

        return 'api_whats_local';
    }

    private function providerReady(array $settings, string $provider): bool
    {
        if (! $this->isTruthy((string) ($settings['whatsapp_enabled'] ?? '0'))) {
            return false;
        }

        return match ($provider) {
            'menuia' => trim((string) ($settings['whatsapp_menuia_appkey'] ?? '')) !== '' && trim((string) ($settings['whatsapp_menuia_authkey'] ?? '')) !== '',
            'evolution' => trim((string) ($settings['whatsapp_evolution_url'] ?? '')) !== '' && trim((string) ($settings['whatsapp_evolution_apikey'] ?? '')) !== '' && trim((string) ($settings['whatsapp_evolution_instance'] ?? '')) !== '',
            'api_whats_linux' => trim((string) ($settings['whatsapp_linux_node_url'] ?? '')) !== '' && trim((string) ($settings['whatsapp_linux_node_token'] ?? '')) !== '',
            'api_whats_local' => trim((string) ($settings['whatsapp_local_node_url'] ?? '')) !== '' && trim((string) ($settings['whatsapp_local_node_token'] ?? '')) !== '',
            'webhook' => trim((string) ($settings['whatsapp_webhook_url'] ?? '')) !== '',
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function connectionSignature(array $settings): string
    {
        $provider = $this->resolveDirectProvider($settings);

        $rawSignature = match ($provider) {
            'menuia' => strtolower(trim((string) ($settings['whatsapp_menuia_url'] ?? ''))) . '|' . trim((string) ($settings['whatsapp_menuia_appkey'] ?? '')) . '|' . trim((string) ($settings['whatsapp_menuia_authkey'] ?? '')),
            'evolution' => strtolower(trim((string) ($settings['whatsapp_evolution_url'] ?? ''))) . '|' . trim((string) ($settings['whatsapp_evolution_apikey'] ?? '')) . '|' . trim((string) ($settings['whatsapp_evolution_instance'] ?? '')),
            'api_whats_local' => strtolower(trim((string) ($settings['whatsapp_local_node_url'] ?? ''))) . '|' . trim((string) ($settings['whatsapp_local_node_token'] ?? '')) . '|' . trim((string) ($settings['whatsapp_local_node_origin'] ?? '')),
            'api_whats_linux' => strtolower(trim((string) ($settings['whatsapp_linux_node_url'] ?? ''))) . '|' . trim((string) ($settings['whatsapp_linux_node_token'] ?? '')) . '|' . trim((string) ($settings['whatsapp_linux_node_origin'] ?? '')),
            default => strtolower(trim((string) ($settings['whatsapp_webhook_url'] ?? ''))) . '|' . trim((string) ($settings['whatsapp_webhook_token'] ?? '')),
        };

        return $rawSignature !== '' ? hash('sha256', $rawSignature) : '';
    }

    /**
     * @param array<string, string> $settings
     * @return array<int, string>
     */
    private function originsFromSettings(array $settings): array
    {
        $origins = [];

        $candidates = [
            [
                'url_key' => 'whatsapp_evolution_url',
                'enabled' => trim((string) ($settings['whatsapp_evolution_apikey'] ?? '')) !== ''
                    && trim((string) ($settings['whatsapp_evolution_instance'] ?? '')) !== '',
            ],
            [
                'url_key' => 'whatsapp_menuia_url',
                'enabled' => trim((string) ($settings['whatsapp_menuia_appkey'] ?? '')) !== ''
                    && trim((string) ($settings['whatsapp_menuia_authkey'] ?? '')) !== '',
            ],
            [
                'url_key' => 'whatsapp_local_node_url',
                'enabled' => trim((string) ($settings['whatsapp_local_node_token'] ?? '')) !== '',
            ],
            [
                'url_key' => 'whatsapp_linux_node_url',
                'enabled' => trim((string) ($settings['whatsapp_linux_node_token'] ?? '')) !== '',
            ],
        ];

        foreach ($candidates as $candidate) {
            if (! ($candidate['enabled'] ?? false)) {
                continue;
            }

            $origin = $this->normalizeOriginFromUrl((string) ($settings[$candidate['url_key']] ?? ''));

            if ($origin !== '') {
                $origins[] = $origin;
            }
        }

        sort($origins);

        return array_values(array_unique($origins));
    }

    private function normalizeOriginFromUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($scheme === '' || $host === '') {
            return '';
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    private function clearLastCheck(): void
    {
        foreach ([
            'whatsapp_last_check_provider',
            'whatsapp_last_check_status',
            'whatsapp_last_check_message',
            'whatsapp_last_check_at',
            'whatsapp_last_check_signature',
        ] as $key) {
            $this->upsert($key, '', 'texto');
        }
    }

    private function upsert(string $key, string $value, string $type): void
    {
        Configuration::query()->updateOrInsert(
            ['chave' => $key],
            [
                'valor' => $value,
                'tipo' => $type,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function fieldType(string $key): string
    {
        if ($this->isBooleanKey($key)) {
            return 'booleano';
        }

        if ($this->isIntegerKey($key)) {
            return 'numero';
        }

        return 'texto';
    }

    private function isBooleanKey(string $key): bool
    {
        return in_array($key, [
            'whatsapp_enabled',
            'whatsapp_evolution_sync_avatar',
        ], true);
    }

    private function isIntegerKey(string $key): bool
    {
        return in_array($key, [
            'whatsapp_evolution_timeout',
            'whatsapp_local_node_timeout',
            'whatsapp_linux_node_timeout',
        ], true);
    }

    private function normalizeBoolean(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0';
    }

    private function normalizeInteger(mixed $value, int $default = 0): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return $default;
    }

    private function normalizeString(mixed $value, string $default = ''): string
    {
        if (! is_scalar($value)) {
            return $default;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : $default;
    }

    private function normalizeUrl(string $value, string $default = ''): string
    {
        $normalized = trim(rtrim($value, '/'));

        return $normalized !== '' ? $normalized : $default;
    }

    private function normalizeOrigin(string $value): string
    {
        $fallback = rtrim((string) config('app.url'), '/');
        $normalized = trim(rtrim($value, '/'));

        return $normalized !== '' ? $normalized : $fallback;
    }

    private function normalizeMenuiaUrl(string $url): string
    {
        $normalized = $this->normalizeUrl($url, self::DEFAULTS['whatsapp_menuia_url']);

        $parts = parse_url($normalized);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === 'api.menuia.com') {
            return self::DEFAULTS['whatsapp_menuia_url'];
        }

        if (! str_ends_with(strtolower($normalized), '/api')) {
            $normalized .= '/api';
        }

        return $normalized;
    }

    private function normalizeEvolutionUrl(string $url): string
    {
        return $this->normalizeUrl($url, self::DEFAULTS['whatsapp_evolution_url']);
    }

    private function normalizeWebhookMethod(string $method): string
    {
        $method = strtoupper(trim($method));

        return in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? $method : 'POST';
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return $phone;
        }

        return str_starts_with($digits, '55') ? $digits : ('55' . $digits);
    }

    private function detectMimeType(string $absoluteFilePath): string
    {
        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($absoluteFilePath);
            if (is_string($detected) && trim($detected) !== '') {
                return trim($detected);
            }
        }

        return 'application/octet-stream';
    }

    private function normalizeMenuiaPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return $phone;
        }

        $normalized = str_starts_with($digits, '55') ? $digits : ('55' . $digits);

        return '+' . $normalized;
    }

    private function isTruthy(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'sim', 'on'], true);
    }

    /**
     * @param array<int, mixed> $payload
     */
    private function parseWebhookHeaders(string $payload): array
    {
        $decoded = json_decode($payload, true);
        $headers = [];
        $hasContentType = false;

        if (is_array($decoded)) {
            foreach ($decoded as $name => $value) {
                if (! is_string($name)) {
                    continue;
                }

                $headers[$name] = (string) $value;
                if (mb_strtolower($name) === 'content-type') {
                    $hasContentType = true;
                }
            }
        }

        if (! $hasContentType) {
            $headers['Content-Type'] = 'application/json';
        }

        $headers['Accept'] = 'application/json';

        return $headers;
    }

    private function renderWebhookPayload(string $template, string $phone, string $message): string
    {
        $rendered = trim($template) !== ''
            ? $template
            : self::DEFAULTS['whatsapp_webhook_payload'];

        return str_replace(
            ['{{phone}}', '{{message}}'],
            [$phone, $this->escapeWebhookMessage($message)],
            $rendered
        );
    }

    private function escapeWebhookMessage(string $message): string
    {
        $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? trim($json, '"') : $message;
    }

    private function buildLastCheckMessage(string $lastAt, string $message): string
    {
        return trim('Última validação: ' . $lastAt . ' - ' . $message);
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'menuia' => 'Menuia',
            'evolution' => 'Evolution API',
            'api_whats_local' => 'API local',
            'api_whats_linux' => 'API Linux',
            'webhook' => 'Webhook',
            default => 'Integração',
        };
    }

    private function bulkProviderLabel(string $provider): string
    {
        return match ($provider) {
            'meta_oficial' => 'Meta Oficial (futuro)',
            'menuia' => 'Menuia',
            'evolution' => 'Evolution API',
            'api_whats_local' => 'API local',
            'api_whats_linux' => 'API Linux',
            'webhook' => 'Webhook',
            default => 'Integração',
        };
    }

    private function normalizeProvider(string $provider, array $allowed, string $default): string
    {
        $provider = trim($provider);

        return in_array($provider, $allowed, true) ? $provider : $default;
    }

    private function failureResponse(string $message, array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'status_code' => 422,
            'message' => $message,
            'response' => null,
        ], $extra);
    }

    private function extractErrorMessage(mixed $payload, int $statusCode): string
    {
        if (is_array($payload)) {
            $message = trim((string) data_get($payload, 'message', ''));
            if ($message !== '') {
                return $message;
            }

            $error = trim((string) data_get($payload, 'error', ''));
            if ($error !== '') {
                return $error;
            }
        }

        return 'Falha na resposta do gateway (HTTP ' . $statusCode . ').';
    }
}
