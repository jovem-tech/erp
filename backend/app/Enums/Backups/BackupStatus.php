<?php

namespace App\Enums\Backups;

enum BackupStatus: string
{
    case Pendente = 'pendente';
    case Executando = 'executando';
    case Concluido = 'concluido';
    case ConcluidoComAvisos = 'concluido_com_avisos';
    case Falhou = 'falhou';
    case Expirado = 'expirado';
    case Ausente = 'ausente';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pendente, self::Executando], true);
    }

    public function isSuccessful(): bool
    {
        return in_array($this, [self::Concluido, self::ConcluidoComAvisos], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Na fila',
            self::Executando => 'Executando',
            self::Concluido => 'Concluído',
            self::ConcluidoComAvisos => 'Concluído com avisos',
            self::Falhou => 'Falhou',
            self::Expirado => 'Expirado',
            self::Ausente => 'Ausente do disco',
        };
    }
}
