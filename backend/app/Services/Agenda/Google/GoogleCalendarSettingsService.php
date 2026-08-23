<?php

namespace App\Services\Agenda\Google;

use App\Models\Configuration;
use App\Support\SecretSettings;

/**
 * Credenciais e estado da conexao com o Google Agenda.
 *
 * Espelha GoogleIntegrationSettingsService (chave/valor em `configuracoes`,
 * mascaramento por SecretSettings), mas com chaves proprias de proposito: as
 * credenciais `portal_google_*` existem para o login do Portal do Cliente -
 * outro consentimento, outro escopo, outro ciclo de vida. Podem vir do mesmo
 * projeto no Google Cloud Console; nao sao a mesma configuracao.
 */
class GoogleCalendarSettingsService
{
    /**
     * Escopo deliberadamente estreito: `calendar.app.created` autoriza o app a
     * enxergar e editar SOMENTE calendarios que ele mesmo criou. E o que garante
     * - pelo proprio Google, nao por disciplina nossa - que o ERP jamais leia a
     * agenda pessoal de quem conectou a conta.
     */
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.app.created';

    public const CALENDAR_SUMMARY = 'Agenda ERP';

    /** @var array<string, string> */
    private const DEFAULTS = [
        'agenda_google_client_id' => '',
        'agenda_google_client_secret' => '',
        'agenda_google_refresh_token' => '',
        'agenda_google_calendar_id' => '',
        'agenda_google_sync_token' => '',
        'agenda_google_conectado_em' => '',
        'agenda_google_conta_email' => '',
        'agenda_google_ultimo_sync_em' => '',
        'agenda_google_ultimo_sync_status' => '',
        'agenda_google_ultimo_sync_erro' => '',
    ];

    /** @var array<int, string> */
    private const SECRET_KEYS = [
        'agenda_google_client_secret',
        'agenda_google_refresh_token',
    ];

    /**
     * Cifradas em repouso com o APP_KEY, via SecretSettings::encrypt/decrypt -
     * a mesma rotina dos demais servicos de integracao, para as duas nao
     * divergirem. O refresh token e uma credencial de longa duracao: quem le a
     * tabela `configuracoes` num dump nao pode sair escrevendo na agenda da
     * empresa.
     *
     * @var array<int, string>
     */
    private const ENCRYPTED_KEYS = [
        'agenda_google_client_secret',
        'agenda_google_refresh_token',
    ];

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $settings = $this->all();

        return [
            'settings' => SecretSettings::blank($settings, self::SECRET_KEYS),
            'secret_status' => SecretSettings::status($settings, self::SECRET_KEYS),
            'summary' => $this->summary($settings),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $current = $this->all();
        $normalized = [];

        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $payload)) {
                $value = $payload[$key];
                $normalized[$key] = is_scalar($value) ? trim((string) $value) : (string) $default;
            }
        }

        // Campo de segredo enviado vazio significa "nao mexi nele", nunca
        // "apague". Sem isso, salvar o formulario com o campo mascarado em
        // branco derrubaria a conexao.
        $normalized = SecretSettings::preserveExisting($normalized, $current, self::SECRET_KEYS);

        foreach ($normalized as $key => $value) {
            $this->put((string) $key, (string) $value);
        }

        return $this->payload();
    }

    public function get(string $key): string
    {
        return $this->all()[$key] ?? '';
    }

    public function put(string $key, string $value): void
    {
        $stored = SecretSettings::encrypt($key, $value, self::ENCRYPTED_KEYS);

        Configuration::query()->updateOrInsert(
            ['chave' => $key],
            [
                'valor' => $stored,
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /** A conexao esta pronta para sincronizar? */
    public function isConnected(): bool
    {
        $settings = $this->all();

        return $settings['agenda_google_client_id'] !== ''
            && $settings['agenda_google_client_secret'] !== ''
            && $settings['agenda_google_refresh_token'] !== ''
            && $settings['agenda_google_calendar_id'] !== '';
    }

    /** Credenciais preenchidas, mas ainda sem consentimento concedido. */
    public function hasCredentials(): bool
    {
        $settings = $this->all();

        return $settings['agenda_google_client_id'] !== ''
            && $settings['agenda_google_client_secret'] !== '';
    }

    public function disconnect(): void
    {
        foreach ([
            'agenda_google_refresh_token',
            'agenda_google_calendar_id',
            'agenda_google_sync_token',
            'agenda_google_conectado_em',
            'agenda_google_conta_email',
        ] as $key) {
            $this->put($key, '');
        }
    }

    public function recordSyncResult(string $status, string $error = ''): void
    {
        $this->put('agenda_google_ultimo_sync_em', now()->toDateTimeString());
        $this->put('agenda_google_ultimo_sync_status', $status);
        $this->put('agenda_google_ultimo_sync_erro', mb_substr($error, 0, 500));
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $stored = Configuration::query()
            ->whereIn('chave', array_keys(self::DEFAULTS))
            ->pluck('valor', 'chave')
            ->all();

        $settings = self::DEFAULTS;

        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            $settings[(string) $key] = SecretSettings::decrypt((string) $key, (string) $value, self::ENCRYPTED_KEYS);
        }

        return $settings;
    }

    /**
     * @param array<string, string> $settings
     * @return array<string, mixed>
     */
    private function summary(array $settings): array
    {
        $connected = $this->isConnected();
        $hasCredentials = $settings['agenda_google_client_id'] !== ''
            && $settings['agenda_google_client_secret'] !== '';

        return [
            'configured' => $hasCredentials,
            'connected' => $connected,
            'calendar_id' => $settings['agenda_google_calendar_id'],
            'conta_email' => $settings['agenda_google_conta_email'],
            'conectado_em' => $settings['agenda_google_conectado_em'],
            'ultimo_sync_em' => $settings['agenda_google_ultimo_sync_em'],
            'ultimo_sync_status' => $settings['agenda_google_ultimo_sync_status'],
            'ultimo_sync_erro' => $settings['agenda_google_ultimo_sync_erro'],
            'scope' => self::SCOPE,
            'status' => match (true) {
                $connected => 'success',
                $hasCredentials => 'warning',
                default => 'secondary',
            },
            'status_label' => match (true) {
                $connected => 'Conectado',
                $hasCredentials => 'Credenciais salvas — falta autorizar',
                default => 'Aguardando configuração',
            },
        ];
    }
}
