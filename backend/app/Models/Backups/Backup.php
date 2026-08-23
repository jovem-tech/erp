<?php

namespace App\Models\Backups;

use App\Enums\Backups\BackupContent;
use App\Enums\Backups\BackupOrigin;
use App\Enums\Backups\BackupStatus;
use App\Enums\Backups\BackupType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Backup extends Model
{
    protected $table = 'backups';

    protected $guarded = [];

    /**
     * arquivo_caminho e um caminho ABSOLUTO no servidor e nunca deve ser
     * serializado para o navegador - o BackupController expoe apenas o uuid.
     *
     * @var array<int, string>
     */
    protected $hidden = ['arquivo_caminho'];

    protected $casts = [
        'tipo' => BackupType::class,
        'origem' => BackupOrigin::class,
        'conteudo' => BackupContent::class,
        'status' => BackupStatus::class,
        'gerenciado' => 'boolean',
        'protegido' => 'boolean',
        'progresso_percentual' => 'integer',
        'tamanho_bytes' => 'integer',
        'formato_versao' => 'integer',
        'kdf_iteracoes' => 'integer',
        'total_arquivos' => 'integer',
        'total_bytes_arquivos' => 'integer',
        'duracao_segundos' => 'integer',
        'solicitado_por' => 'integer',
        'manifesto_json' => 'array',
        'bancos_incluidos' => 'array',
        'raizes_incluidas' => 'array',
        'avisos_json' => 'array',
        'arquivo_modificado_em' => 'immutable_datetime',
        'iniciado_em' => 'immutable_datetime',
        'heartbeat_em' => 'immutable_datetime',
        'concluido_em' => 'immutable_datetime',
        'retido_ate' => 'immutable_datetime',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(BackupDelivery::class, 'backup_id');
    }

    public function restores(): HasMany
    {
        return $this->hasMany(BackupRestore::class, 'backup_id');
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BackupStatus::Concluido->value,
            BackupStatus::ConcluidoComAvisos->value,
        ]);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BackupStatus::Pendente->value,
            BackupStatus::Executando->value,
        ]);
    }

    /** O painel so apaga o que ele mesmo gerencia e o que nao esta fixado. */
    public function canBeDeletedByPanel(): bool
    {
        return $this->gerenciado && ! $this->protegido;
    }

    public function isRestorable(): bool
    {
        return $this->status->isSuccessful()
            && $this->arquivo_caminho !== null
            && is_file((string) $this->arquivo_caminho);
    }

    /** @return array<int, array<string, mixed>> */
    public function warnings(): array
    {
        return is_array($this->avisos_json) ? $this->avisos_json : [];
    }
}
