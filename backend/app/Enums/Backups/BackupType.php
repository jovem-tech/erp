<?php

namespace App\Enums\Backups;

enum BackupType: string
{
    case Completo = 'completo';
    case PreRestauracao = 'pre_restauracao';
    case Descoberto = 'descoberto';

    public function label(): string
    {
        return match ($this) {
            self::Completo => 'Completo',
            self::PreRestauracao => 'Pré-restauração',
            self::Descoberto => 'Descoberto',
        };
    }
}
