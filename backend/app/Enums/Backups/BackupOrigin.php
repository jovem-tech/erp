<?php

namespace App\Enums\Backups;

enum BackupOrigin: string
{
    case Painel = 'painel';
    case Agendado = 'agendado';
    case CronLegado = 'cron_legado';
    case PreDeploy = 'pre_deploy';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Painel => 'Painel',
            self::Agendado => 'Agendado',
            self::CronLegado => 'Cron 02:00',
            self::PreDeploy => 'Pré-deploy',
            self::Manual => 'Manual',
        };
    }

    /** Backups que o painel nao criou nao podem ser excluidos por ele. */
    public function isManagedByPanel(): bool
    {
        return in_array($this, [self::Painel, self::Agendado, self::Manual], true);
    }
}
