<?php

namespace App\Enums\Backups;

enum DestinationDriver: string
{
    case Local = 'local';
    case Montagem = 'montagem';
    case Remoto = 'remoto';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Armazenamento local',
            self::Montagem => 'HD externo / pasta de rede',
            self::Remoto => 'Nuvem',
        };
    }
}
