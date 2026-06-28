<?php

namespace App\Services\Integrations;

use App\Mail\IntegrationTestMail;
use App\Models\Configuration;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailIntegrationSettingsService
{
    private const TEST_MAILER_NAME = 'integration_test_smtp';

    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_crypto' => 'auto',
        'smtp_timeout' => '20',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => '',
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $settings = $this->loadSettings();

        return [
            'settings' => $settings,
            'summary' => $this->buildSummary($settings),
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
            $this->upsert((string) $key, (string) $value);
        }

        return $this->payload();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendTest(array $payload): array
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->failureResponse('Informe um e-mail de teste válido.');
        }

        $settings = $this->mergeSettings($payload);
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = (int) ($settings['smtp_port'] ?? 0);

        if ($host === '' || $port <= 0) {
            return $this->failureResponse('Configuração SMTP incompleta (host e porta).');
        }

        $fromEmail = $this->resolveFromEmail($settings);
        $fromName = $this->resolveFromName($settings);
        $crypto = $this->resolveCrypto((string) ($settings['smtp_crypto'] ?? 'auto'), $port);
        $timeout = max(5, (int) ($settings['smtp_timeout'] ?? 20));
        $user = trim((string) ($settings['smtp_user'] ?? ''));
        $pass = trim((string) ($settings['smtp_pass'] ?? ''));

        config(['mail.mailers.' . self::TEST_MAILER_NAME => [
            'transport' => 'smtp',
            // Symfony's EsmtpTransportFactory only exposes "smtps" (implicit TLS) vs "smtp"
            // (opportunistic STARTTLS); there is no scheme for fully disabling encryption.
            'scheme' => $crypto === 'ssl' ? 'smtps' : 'smtp',
            'host' => $host,
            'port' => $port,
            'username' => $user !== '' ? $user : null,
            'password' => $pass !== '' ? $pass : null,
            'timeout' => $timeout,
        ]]);

        Mail::purge(self::TEST_MAILER_NAME);

        try {
            $mailable = (new IntegrationTestMail(
                'Teste de e-mail do ERP',
                $this->buildTestMessageHtml($fromName, $fromEmail, $host, $port, $crypto, $timeout)
            ))->from($fromEmail, $fromName);

            Mail::mailer(self::TEST_MAILER_NAME)->to($email)->send($mailable);

            return [
                'ok' => true,
                'provider' => 'smtp',
                'message' => 'E-mail de teste enviado com sucesso.',
            ];
        } catch (Throwable $throwable) {
            return $this->failureResponse('Falha ao enviar e-mail de teste: ' . $throwable->getMessage(), [
                'provider' => 'smtp',
            ]);
        } finally {
            Mail::purge(self::TEST_MAILER_NAME);
        }
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

            if ($key === 'smtp_port' || $key === 'smtp_timeout') {
                $intValue = is_numeric($value) ? (int) $value : (int) $defaultValue;
                $normalized[$key] = (string) ($key === 'smtp_timeout' ? max(5, min(120, $intValue)) : max(0, $intValue));
                continue;
            }

            if ($key === 'smtp_crypto') {
                $crypto = strtolower(trim((string) $value));
                $normalized[$key] = in_array($crypto, ['auto', 'tls', 'ssl', 'none'], true) ? $crypto : 'auto';
                continue;
            }

            if ($key === 'smtp_from_email') {
                $normalized[$key] = strtolower(trim((string) $value));
                continue;
            }

            $normalized[$key] = is_scalar($value) ? trim((string) $value) : (string) $defaultValue;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildSummary(array $settings): array
    {
        $configured = trim((string) ($settings['smtp_host'] ?? '')) !== '' && (int) ($settings['smtp_port'] ?? 0) > 0;

        return [
            'configured' => $configured,
            'status' => $configured ? 'success' : 'secondary',
            'status_label' => $configured ? 'Configurado' : 'Aguardando configuração',
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveFromEmail(array $settings): string
    {
        $fromEmail = trim((string) ($settings['smtp_from_email'] ?? ''));
        if ($fromEmail !== '') {
            return $fromEmail;
        }

        $user = trim((string) ($settings['smtp_user'] ?? ''));

        return $user !== '' ? $user : 'nao-responda@sistema-erp.com';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveFromName(array $settings): string
    {
        $fromName = trim((string) ($settings['smtp_from_name'] ?? ''));

        return $fromName !== '' ? $fromName : 'Sistema ERP';
    }

    private function resolveCrypto(string $smtpCrypto, int $smtpPort): string
    {
        if (in_array($smtpCrypto, ['tls', 'ssl'], true)) {
            return $smtpCrypto;
        }

        if ($smtpCrypto === 'none') {
            return '';
        }

        if ($smtpPort === 465) {
            return 'ssl';
        }

        if ($smtpPort === 587) {
            return 'tls';
        }

        return '';
    }

    private function buildTestMessageHtml(
        string $fromName,
        string $fromEmail,
        string $host,
        int $port,
        string $crypto,
        int $timeout
    ): string {
        return '
            <div style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6;">
                <h2 style="margin-bottom: 12px;">Teste de e-mail do ERP</h2>
                <p>Este e-mail confirma que o canal de envio do sistema foi configurado e conseguiu processar uma mensagem de teste.</p>
                <p><strong>Remetente:</strong> ' . e($fromName) . ' &lt;' . e($fromEmail) . '&gt;<br>
                   <strong>Host:</strong> ' . e($host) . '<br>
                   <strong>Porta:</strong> ' . e((string) $port) . '<br>
                   <strong>Criptografia:</strong> ' . e($crypto !== '' ? strtoupper($crypto) : 'NENHUMA') . '<br>
                   <strong>Timeout:</strong> ' . e((string) $timeout) . 's</p>
            </div>';
    }

    private function upsert(string $key, string $value): void
    {
        Configuration::query()->updateOrInsert(
            ['chave' => $key],
            [
                'valor' => $value,
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function failureResponse(string $message, array $extra = []): array
    {
        return array_merge([
            'ok' => false,
            'message' => $message,
        ], $extra);
    }
}
