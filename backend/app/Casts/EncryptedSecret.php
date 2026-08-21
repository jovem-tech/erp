<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Cifra o atributo em repouso, tolerando valores legados em texto puro.
 *
 * Por que não o cast nativo `encrypted`: ele estoura DecryptException em
 * qualquer valor que não seja um payload válido. Numa base que já tem dados
 * gravados em claro isso significa HTTP 500 ao ler o registro — inclusive na
 * janela entre subir o código e rodar a migration de cifragem, e em qualquer
 * linha que a migration não tenha alcançado (importação, restauração de backup
 * antigo, escrita por fora do Eloquent).
 *
 * Aqui a leitura decifra quando dá e devolve o valor como está quando não dá.
 * A escrita cifra sempre — então cada registro salvo se corrige sozinho.
 */
class EncryptedSecret implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value === '' ? '' : null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            // Valor legado, ainda em texto puro. Devolve como está em vez de
            // derrubar a requisição; será cifrado no próximo save.
            return (string) $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? '' : Crypt::encryptString($value);
    }
}
