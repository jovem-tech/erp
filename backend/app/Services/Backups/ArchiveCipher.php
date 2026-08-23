<?php

namespace App\Services\Backups;

class ArchiveCipher
{
    /**
     * Nome da variavel de ambiente que carrega a frase secreta ate o openssl.
     *
     * Passar por env e deliberado: `-k` e `-pass pass:` colocam a frase em
     * argv, que qualquer usuario da maquina le com `ps aux`. /proc/<pid>/environ
     * so e legivel pelo mesmo uid.
     */
    public const ENV_KEY = 'ERP_BACKUP_PASSPHRASE';

    /** Fragmento de shell que CIFRA o que vier da entrada padrao. */
    public function encryptFilter(): string
    {
        return $this->filter(encrypt: true);
    }

    /** Fragmento de shell que DECIFRA o que vier da entrada padrao. */
    public function decryptFilter(): string
    {
        return $this->filter(encrypt: false);
    }

    /** @return array<string, string> */
    public function env(string $passphrase): array
    {
        return [self::ENV_KEY => $passphrase];
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'algoritmo' => (string) config('backup.cipher.algorithm'),
            'digest' => (string) config('backup.cipher.digest'),
            'kdf' => 'pbkdf2',
            'iteracoes' => (int) config('backup.cipher.iterations'),
        ];
    }

    /**
     * Linha de comando que o operador roda para abrir o pacote SEM o sistema.
     * Vai impressa dentro de todo pacote, em LEIA-ME-RESTAURACAO.txt.
     */
    public function manualDecryptCommand(string $member): string
    {
        return sprintf(
            'openssl enc -d -%s -pbkdf2 -iter %d -md %s -in %s -pass file:frase.txt',
            (string) config('backup.cipher.algorithm'),
            (int) config('backup.cipher.iterations'),
            (string) config('backup.cipher.digest'),
            escapeshellarg($member),
        );
    }

    private function filter(bool $encrypt): string
    {
        return sprintf(
            'openssl enc %s-%s -pbkdf2 -iter %d -md %s -salt -pass env:%s',
            $encrypt ? '' : '-d ',
            (string) config('backup.cipher.algorithm'),
            (int) config('backup.cipher.iterations'),
            (string) config('backup.cipher.digest'),
            self::ENV_KEY,
        );
    }
}
