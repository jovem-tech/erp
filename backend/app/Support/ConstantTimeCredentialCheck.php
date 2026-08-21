<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * Verificação de senha com custo de CPU constante.
 *
 * Sem isto, `! $user || ! Hash::check(...)` faz curto-circuito: quando o e-mail
 * não existe o bcrypt nunca roda e a resposta volta em milissegundos, enquanto
 * um e-mail válido custa ~250ms (cost 12). Essa diferença é medível de fora e
 * transforma o endpoint num oráculo de "este e-mail existe" — e, no step-up de
 * administrador, de "este e-mail é administrador".
 *
 * A verificação aqui SEMPRE executa um bcrypt: contra o hash real quando ele
 * existe, contra um hash descartável quando não existe.
 */
final class ConstantTimeCredentialCheck
{
    /**
     * Hash descartável gerado uma vez por processo, no mesmo custo configurado
     * em `hashing.bcrypt.rounds` — assim o tempo gasto no caminho "usuário
     * inexistente" acompanha automaticamente o do caminho real, inclusive se o
     * custo for alterado.
     */
    private static ?string $placeholderHash = null;

    /**
     * @param  string|null  $storedHash  hash gravado no banco, ou null/'' quando não há usuário
     */
    public static function matches(?string $plainPassword, ?string $storedHash): bool
    {
        $storedHash = (string) ($storedHash ?? '');
        $hasStoredHash = $storedHash !== '';

        $matches = Hash::check(
            (string) $plainPassword,
            $hasStoredHash ? $storedHash : self::placeholderHash()
        );

        // O resultado do hash descartável nunca autentica ninguém.
        return $hasStoredHash && $matches;
    }

    private static function placeholderHash(): string
    {
        return self::$placeholderHash ??= Hash::make('constant-time-placeholder');
    }
}
