<?php

namespace App\Enums\Backups;

enum BackupContent: string
{
    case Completo = 'completo';
    case SomenteBanco = 'somente_banco';

    public function label(): string
    {
        return match ($this) {
            self::Completo => 'Completo',
            self::SomenteBanco => 'Somente banco',
        };
    }
}
