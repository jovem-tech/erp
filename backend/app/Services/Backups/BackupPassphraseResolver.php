<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class BackupPassphraseResolver
{
    public function __construct(private readonly BackupSettingsService $settings) {}

    public function isConfigured(): bool
    {
        return trim($this->settings->get('backup_passphrase_hash')) !== '';
    }

    public function define(string $passphrase): void
    {
        $passphrase = trim($passphrase);

        if (mb_strlen($passphrase) < 12) {
            throw new RuntimeException('A frase secreta precisa ter ao menos 12 caracteres.');
        }

        $this->settings->put('backup_passphrase_hash', Hash::make($passphrase));
        $this->settings->put('backup_passphrase_fingerprint', $this->fingerprint($passphrase));

        // Guardada cifrada com o APP_KEY para o backup diario rodar sozinho.
        // Compromisso assumido e documentado: quem le o .env E o banco recupera
        // a frase - mas essa pessoa ja tem tudo. O papel real da frase e
        // proteger as copias que SAEM do servidor (nuvem, HD, download).
        if ($this->settings->get('backup_passphrase_modo') === 'armazenada') {
            $this->settings->put('backup_passphrase_cifrada', Crypt::encryptString($passphrase));
        } else {
            $this->settings->put('backup_passphrase_cifrada', '');
        }
    }

    public function verify(string $passphrase): bool
    {
        $hash = $this->settings->get('backup_passphrase_hash');

        return $hash !== '' && Hash::check(trim($passphrase), $hash);
    }

    /**
     * Identidade curta e nao reversivel da frase, gravada em todo manifesto.
     * E o que permite a interface dizer "este backup foi gerado com outra
     * frase secreta" em vez de falhar so na hora de decifrar.
     */
    public function fingerprint(string $passphrase): string
    {
        return substr(hash_hmac('sha256', 'erp-backup', trim($passphrase)), 0, 16);
    }

    public function storedFingerprint(): string
    {
        return $this->settings->get('backup_passphrase_fingerprint');
    }

    /** Frase para uma execucao nao assistida (agendada). */
    public function resolveForUnattended(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Nenhuma frase secreta configurada. Defina uma em Configurações → Backup antes de gerar backups.'
            );
        }

        if ($this->settings->get('backup_passphrase_modo') !== 'armazenada') {
            throw new RuntimeException(
                'A frase secreta está em modo manual: backups agendados ficam desabilitados. '
                .'Gere o backup pelo painel informando a frase, ou mude o modo para "armazenada".'
            );
        }

        $encrypted = $this->settings->get('backup_passphrase_cifrada');

        if ($encrypted === '') {
            throw new RuntimeException(
                'A frase secreta armazenada não foi encontrada. Redefina-a em Configurações → Backup.'
            );
        }

        return Crypt::decryptString($encrypted);
    }
}
