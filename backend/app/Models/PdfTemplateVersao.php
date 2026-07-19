<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versão de template do motor central de PDF. Publicada é imutável:
 * qualquer edição cria/atualiza um rascunho; publicar rebaixa o publicado
 * anterior para arquivado e promove o rascunho, atomicamente
 * (transação + lockForUpdate na família).
 */
class PdfTemplateVersao extends Model
{
    public const STATUS_RASCUNHO = 'rascunho';
    public const STATUS_PUBLICADO = 'publicado';
    public const STATUS_ARQUIVADO = 'arquivado';

    protected $table = 'pdf_template_versoes';

    protected $guarded = [];

    protected $casts = [
        'template_id' => 'integer',
        'versao' => 'integer',
        'publicado_em' => 'datetime',
        'publicado_por' => 'integer',
        'criado_por' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PdfTemplate::class, 'template_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $decoded = json_decode((string) ($this->schema_json ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, int|float>
     */
    public function margens(): array
    {
        $decoded = json_decode((string) ($this->margens_json ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }
}
