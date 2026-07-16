<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento da timeline unificada da OS (tabela append-only `os_eventos`).
 *
 * Regra de projeto: linhas desta tabela NUNCA sao atualizadas nem excluidas
 * pela aplicacao — o unico caminho de escrita e OrderEventService::record()
 * (excecao: o comando os:backfill-eventos, que so remove/reinsere linhas
 * importadas do legado, identificadas por legacy_tabela NOT NULL).
 */
class OrderEvent extends Model
{
    public const CATEGORIA_STATUS = 'status';
    public const CATEGORIA_ORCAMENTO = 'orcamento';
    public const CATEGORIA_FINANCEIRO = 'financeiro';
    public const CATEGORIA_DOCUMENTO = 'documento';
    public const CATEGORIA_MENSAGEM = 'mensagem';
    public const CATEGORIA_REGISTRO = 'registro';

    public const ORIGEM_SISTEMA = 'sistema';
    public const ORIGEM_USUARIO = 'usuario';
    public const ORIGEM_CLIENTE = 'cliente';
    public const ORIGEM_AUTOMACAO = 'automacao';

    // Catalogo de tipos de evento (slug maquina). Manter em sincronia com a
    // documentacao (documentacao/03-arquitetura-tecnica/eventos-os.md).
    public const TIPO_OS_CRIADA = 'os_criada';
    public const TIPO_STATUS_ALTERADO = 'status_alterado';
    public const TIPO_STATUS_SINCRONIZADO_ORCAMENTO = 'status_sincronizado_orcamento';
    public const TIPO_PRAZO_REDEFINIDO = 'prazo_redefinido';
    public const TIPO_FECHAMENTO_CANCELADO = 'fechamento_cancelado';
    public const TIPO_OS_ATUALIZADA = 'os_atualizada';
    public const TIPO_DADOS_TECNICOS_ATUALIZADOS = 'dados_tecnicos_atualizados';
    public const TIPO_PROCEDIMENTO_REGISTRADO = 'procedimento_registrado';
    public const TIPO_FOTOS_ADICIONADAS = 'fotos_adicionadas';
    public const TIPO_CHECKLIST_REGISTRADO = 'checklist_registrado';
    public const TIPO_FECHAMENTO_CONCLUIDO = 'fechamento_concluido';
    public const TIPO_RETORNO_AGENDADO = 'retorno_agendado';
    public const TIPO_ADIANTAMENTO_REGISTRADO = 'adiantamento_registrado';
    public const TIPO_TITULO_CRIADO = 'titulo_criado';
    public const TIPO_TITULO_ATUALIZADO = 'titulo_atualizado';
    public const TIPO_TITULO_EXCLUIDO = 'titulo_excluido';
    public const TIPO_TITULO_CANCELADO = 'titulo_cancelado';
    public const TIPO_MOVIMENTO_REGISTRADO = 'movimento_registrado';
    public const TIPO_FINANCEIRO_FECHAMENTO_REMOVIDO = 'financeiro_fechamento_removido';
    public const TIPO_COBRANCAS_AGENDADAS = 'cobrancas_agendadas';
    public const TIPO_COBRANCA_AGENDADA = 'cobranca_agendada';
    public const TIPO_COBRANCAS_CANCELADAS = 'cobrancas_canceladas';
    public const TIPO_COBRANCA_ENVIADA = 'cobranca_enviada';
    public const TIPO_ORCAMENTO_CRIADO = 'orcamento_criado';
    public const TIPO_ORCAMENTO_ATUALIZADO = 'orcamento_atualizado';
    public const TIPO_ORCAMENTO_EXCLUIDO = 'orcamento_excluido';
    public const TIPO_ORCAMENTO_ENVIADO = 'orcamento_enviado';
    public const TIPO_ORCAMENTO_APROVADO = 'orcamento_aprovado';
    public const TIPO_ORCAMENTO_RECUSADO = 'orcamento_recusado';
    public const TIPO_ORCAMENTO_PDF_GERADO = 'orcamento_pdf_gerado';
    public const TIPO_FECHAMENTO_PDF_GERADO = 'fechamento_pdf_gerado';
    public const TIPO_WHATSAPP_ENVIADO = 'whatsapp_enviado';

    protected $table = 'os_eventos';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'os_id' => 'integer',
        'usuario_id' => 'integer',
        'legacy_id' => 'integer',
        'dados' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * @return array<int, string>
     */
    public static function categorias(): array
    {
        return [
            self::CATEGORIA_STATUS,
            self::CATEGORIA_ORCAMENTO,
            self::CATEGORIA_FINANCEIRO,
            self::CATEGORIA_DOCUMENTO,
            self::CATEGORIA_MENSAGEM,
            self::CATEGORIA_REGISTRO,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id', 'id');
    }
}
