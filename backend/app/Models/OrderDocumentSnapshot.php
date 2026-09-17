<?php

namespace App\Models;

use App\Services\Pdf\Snapshots\DocumentSnapshotSerializer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dados congelados de uma versão documental (os_documento_snapshots). O PDF
 * dessa versão é renderizado sob demanda a partir daqui; o binário só existe
 * em disco quando há assinatura formal.
 */
class OrderDocumentSnapshot extends Model
{
    protected $table = 'os_documento_snapshots';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'documento_id' => 'integer',
        'versao_formato' => 'integer',
        'template_id' => 'integer',
        'template_versao_id' => 'integer',
        'template_versao' => 'integer',
        'tamanho_bytes' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(OrderDocument::class, 'documento_id', 'id');
    }

    /**
     * snapshot_json é gravado já codificado pelo DocumentSnapshotSerializer
     * (sem cast: o encoder tolera bytes fora de UTF-8 em dados legados).
     *
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        $raw = $this->getAttribute('snapshot_json');
        if (is_array($raw)) {
            return $raw;
        }

        return is_string($raw) && $raw !== '' ? DocumentSnapshotSerializer::decode($raw) : [];
    }

    /**
     * @return array<int, string>
     */
    public function formats(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->formatos))));
    }
}
