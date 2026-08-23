<?php

namespace App\Services\Agenda\Google;

use RuntimeException;

/**
 * O syncToken guardado nao vale mais (HTTP 410 da Calendar API). Nao e falha:
 * e o sinal de que a proxima leitura precisa ser completa, sem token.
 */
class GoogleSyncTokenExpiredException extends RuntimeException
{
}
