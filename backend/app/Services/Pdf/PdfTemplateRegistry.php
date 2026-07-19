<?php

namespace App\Services\Pdf;

use App\Models\PdfTemplate;
use App\Services\Pdf\Contexts\BudgetPdfContextFactory;
use App\Services\Pdf\Contexts\OrderClosurePdfContextFactory;
use App\Services\Pdf\Contexts\OrderPdfContextFactory;
use Illuminate\Support\Facades\Schema;

/**
 * Registro central (em código) dos tipos documentais do motor de PDF.
 *
 * Cada descritor declara: rótulo, factory de contexto, allowlist de
 * variáveis tipadas, coleções tabulares permitidas, o tipo legado usado em
 * `os_documentos.tipo_documento`, o template de mensagem WhatsApp sugerido
 * e os gatilhos automáticos — antes hardcoded em
 * OrderDocumentCenterService::DOCUMENT_TYPES.
 *
 * Adicionar um novo documento ao sistema = 1 descritor aqui + 1 factory de
 * contexto (ou reuso de uma existente) + template publicado na página
 * Modelos PDF. Nada mais.
 */
class PdfTemplateRegistry
{
    /** @var array<string, array<string, mixed>|null> */
    private array $customDescriptorCache = [];

    /**
     * Variáveis disponíveis em TODOS os tipos (empresa + metadados do documento).
     *
     * @var array<string, string>
     */
    private const COMMON_VARIABLES = [
        'empresa.nome_sistema' => 'string',
        'empresa.razao_social' => 'string',
        'empresa.nome_fantasia' => 'string',
        'empresa.cnpj' => 'documento',
        'empresa.inscricao_estadual' => 'string',
        'empresa.telefone' => 'telefone',
        'empresa.email' => 'string',
        'empresa.endereco' => 'string',
        'documento.nome' => 'string',
        'documento.codigo' => 'string',
        'documento.gerado_em' => 'data_hora',
        'documento.usuario' => 'string',
        'documento.versao_template' => 'string',
    ];

    /**
     * Variáveis da OS compartilhadas por todos os tipos ligados a uma OS.
     *
     * @var array<string, string>
     */
    private const ORDER_VARIABLES = [
        'os.numero' => 'string',
        'os.status' => 'string',
        'os.prioridade' => 'string',
        'os.data_abertura' => 'data_hora',
        'os.data_previsao' => 'data',
        'os.data_entrega' => 'data_hora',
        'os.valor_final' => 'moeda',
        'os.relato_cliente' => 'string',
        'os.diagnostico_tecnico' => 'string',
        'os.solucao_aplicada' => 'string',
        'os.forma_pagamento' => 'string',
        'os.garantia_dias' => 'inteiro',
        'os.garantia_validade' => 'data',
        'os.tecnico_nome' => 'string',
        // Blocos HTML derivados do checklist de entrada (legado do modelo de
        // abertura). Tipo `html`: pré-sanitizados na factory, injetados sem
        // re-escape pelo resolver.
        'os.acessorios_html' => 'html',
        'os.estado_fisico_html' => 'html',
        'cliente.nome' => 'string',
        'cliente.telefone' => 'telefone',
        'cliente.email' => 'string',
        'cliente.documento' => 'documento',
        'cliente.endereco' => 'string',
        'equipamento.descricao' => 'string',
        'equipamento.tipo' => 'string',
        'equipamento.marca' => 'string',
        'equipamento.modelo' => 'string',
        'equipamento.serie' => 'string',
    ];

    /**
     * Coleções tabulares comuns dos tipos ligados a uma OS.
     *
     * @var array<string, array<string, string>>
     */
    private const ORDER_COLLECTIONS = [
        'itens' => [
            'tipo' => 'string',
            'descricao' => 'string',
            'quantidade' => 'inteiro',
            'valor_unitario' => 'moeda',
            'valor_total' => 'moeda',
        ],
        'acessorios' => [
            'descricao' => 'string',
        ],
        'estado_fisico' => [
            'item' => 'string',
            'status' => 'string',
            'observacao' => 'string',
        ],
    ];

    /**
     * Tokens de imagem internos permitidos em todos os tipos.
     *
     * @var array<int, string>
     */
    public const IMAGE_TOKENS = ['logo_empresa', 'foto_equipamento_principal'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function types(): array
    {
        $orderVariables = array_merge(self::COMMON_VARIABLES, self::ORDER_VARIABLES);

        return [
            'os_abertura' => [
                'codigo' => 'os_abertura',
                'nome' => 'Comprovante de abertura',
                'descricao' => 'Comprovante entregue ao cliente na abertura da OS, com acessórios e estado físico registrados no check-in.',
                'legacy_tipo' => 'abertura',
                'context_factory' => OrderPdfContextFactory::class,
                'variables' => $orderVariables,
                'collections' => self::ORDER_COLLECTIONS,
                'message_template_code' => 'os_aberta',
                'automatic_triggers' => ['criacao_os'],
            ],
            'os_orcamento' => [
                'codigo' => 'os_orcamento',
                'nome' => 'Orçamento',
                'descricao' => 'Documento comercial do orçamento com itens, totais e link de aprovação.',
                'legacy_tipo' => 'orcamento',
                'context_factory' => BudgetPdfContextFactory::class,
                'variables' => array_merge($orderVariables, [
                    'orcamento.numero' => 'string',
                    'orcamento.titulo' => 'string',
                    'orcamento.validade_dias' => 'inteiro',
                    'orcamento.prazo_execucao' => 'string',
                    'orcamento.condicoes' => 'string',
                    'orcamento.observacoes' => 'string',
                    'orcamento.subtotal' => 'moeda',
                    'orcamento.desconto' => 'moeda',
                    'orcamento.total' => 'moeda',
                    'orcamento.link_aprovacao' => 'string',
                ]),
                'collections' => array_merge(self::ORDER_COLLECTIONS, [
                    'itens' => [
                        'tipo' => 'string',
                        'descricao' => 'string',
                        'quantidade' => 'inteiro',
                        'valor_unitario' => 'moeda',
                        'desconto' => 'moeda',
                        'acrescimo' => 'moeda',
                        'valor_total' => 'moeda',
                        'observacoes' => 'string',
                    ],
                ]),
                'message_template_code' => 'orcamento_enviado',
                'automatic_triggers' => ['envio_orcamento'],
            ],
            'os_laudo_tecnico' => [
                'codigo' => 'os_laudo_tecnico',
                'nome' => 'Laudo técnico',
                'descricao' => 'Laudo com diagnóstico técnico e solução aplicada.',
                'legacy_tipo' => 'laudo',
                'context_factory' => OrderPdfContextFactory::class,
                'variables' => $orderVariables,
                'collections' => self::ORDER_COLLECTIONS,
                'message_template_code' => 'laudo_concluido',
                'automatic_triggers' => ['status_tecnico'],
            ],
            'os_cobranca_manutencao' => [
                'codigo' => 'os_cobranca_manutencao',
                'nome' => 'Cobrança / manutenção',
                'descricao' => 'Resumo financeiro consolidado da OS para cobrança.',
                'legacy_tipo' => 'cobranca_manutencao',
                'context_factory' => OrderPdfContextFactory::class,
                'variables' => $orderVariables,
                'collections' => self::ORDER_COLLECTIONS,
                'message_template_code' => 'cobranca_manutencao',
                'automatic_triggers' => ['baixa_cobranca'],
            ],
            'os_comprovante_entrega' => [
                'codigo' => 'os_comprovante_entrega',
                'nome' => 'Comprovante de entrega',
                'descricao' => 'Comprovante de entrega do equipamento ao cliente.',
                'legacy_tipo' => 'entrega',
                'context_factory' => OrderPdfContextFactory::class,
                'variables' => $orderVariables,
                'collections' => self::ORDER_COLLECTIONS,
                'message_template_code' => 'entrega_concluida',
                'automatic_triggers' => ['baixa_entrega'],
            ],
            'os_devolucao_sem_reparo' => [
                'codigo' => 'os_devolucao_sem_reparo',
                'nome' => 'Devolução sem reparo',
                'descricao' => 'Registro de devolução do equipamento sem execução de reparo.',
                'legacy_tipo' => 'devolucao_sem_reparo',
                'context_factory' => OrderPdfContextFactory::class,
                'variables' => $orderVariables,
                'collections' => self::ORDER_COLLECTIONS,
                'message_template_code' => 'devolucao_sem_reparo',
                'automatic_triggers' => ['baixa_sem_reparo'],
            ],
            'os_encerramento' => [
                'codigo' => 'os_encerramento',
                'nome' => 'Comprovante de encerramento',
                'descricao' => 'PDF consolidado do fechamento da OS: itens, valores, recebimentos e garantia.',
                'legacy_tipo' => 'encerramento',
                'context_factory' => OrderClosurePdfContextFactory::class,
                'variables' => array_merge($orderVariables, [
                    'encerramento.status_final' => 'string',
                    'encerramento.data_entrega' => 'string',
                    'encerramento.observacao' => 'string',
                    'encerramento.valor_titulo' => 'moeda',
                    'encerramento.saldo_restante' => 'moeda',
                ]),
                'collections' => array_merge(self::ORDER_COLLECTIONS, [
                    'recebimentos' => [
                        'forma_pagamento' => 'string',
                        'valor' => 'moeda',
                        'data' => 'string',
                    ],
                ]),
                'message_template_code' => 'entrega_concluida',
                'automatic_triggers' => ['baixa_os'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_keys($this->types());
    }

    public function has(string $codigo): bool
    {
        return $this->get($codigo) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $codigo): ?array
    {
        $coreTypes = $this->types();
        if (isset($coreTypes[$codigo])) {
            return array_merge($coreTypes[$codigo], [
                'personalizado' => false,
                'tipo_base_codigo' => $codigo,
            ]);
        }

        if (! preg_match('/\Acustom_[a-z0-9_]{1,72}\z/', $codigo)) {
            return null;
        }

        if (array_key_exists($codigo, $this->customDescriptorCache)) {
            return $this->customDescriptorCache[$codigo];
        }

        if (! Schema::hasTable('pdf_templates') || ! Schema::hasColumn('pdf_templates', 'personalizado')) {
            return $this->customDescriptorCache[$codigo] = null;
        }

        $family = PdfTemplate::query()
            ->where('tipo_codigo', $codigo)
            ->where('personalizado', true)
            ->where('arquivado', false)
            ->first();
        $baseCode = (string) ($family?->tipo_base_codigo ?? '');
        $baseDescriptor = $coreTypes[$baseCode] ?? null;

        if (! $family instanceof PdfTemplate || ! is_array($baseDescriptor)) {
            return $this->customDescriptorCache[$codigo] = null;
        }

        return $this->customDescriptorCache[$codigo] = array_merge($baseDescriptor, [
            'codigo' => $codigo,
            'nome' => (string) $family->nome,
            'descricao' => (string) ($family->descricao ?? ''),
            'legacy_tipo' => $codigo,
            'message_template_code' => null,
            'automatic_triggers' => [],
            'personalizado' => true,
            'tipo_base_codigo' => $baseCode,
        ]);
    }

    public function forget(string $codigo): void
    {
        unset($this->customDescriptorCache[$codigo]);
    }

    /**
     * Resolve o código do motor a partir do tipo legado de os_documentos
     * (abertura, laudo, ...). Retorna null para tipos desconhecidos.
     */
    public function codeForLegacyType(string $legacyTipo): ?string
    {
        foreach ($this->types() as $codigo => $descriptor) {
            if (($descriptor['legacy_tipo'] ?? null) === $legacyTipo) {
                return $codigo;
            }
        }

        return str_starts_with($legacyTipo, 'custom_') && $this->get($legacyTipo) !== null
            ? $legacyTipo
            : null;
    }
}
