<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Família de template do motor central de PDF (1 por tipo documental do
 * PdfTemplateRegistry). As versões imutáveis vivem em PdfTemplateVersao.
 */
class PdfTemplate extends Model
{
    protected $table = 'pdf_templates';

    protected $guarded = [];

    protected $casts = [
        'arquivado' => 'boolean',
        'personalizado' => 'boolean',
        'origem_template_id' => 'integer',
        'criado_por' => 'integer',
        'atualizado_por' => 'integer',
    ];

    public function versoes(): HasMany
    {
        return $this->hasMany(PdfTemplateVersao::class, 'template_id');
    }

    public function versaoPublicada(): ?PdfTemplateVersao
    {
        return $this->versoes()
            ->where('status', PdfTemplateVersao::STATUS_PUBLICADO)
            ->orderByDesc('versao')
            ->first();
    }

    public function rascunhoAtual(): ?PdfTemplateVersao
    {
        return $this->versoes()
            ->where('status', PdfTemplateVersao::STATUS_RASCUNHO)
            ->orderByDesc('versao')
            ->first();
    }
}
