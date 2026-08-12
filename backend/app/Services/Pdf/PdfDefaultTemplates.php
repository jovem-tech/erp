<?php

namespace App\Services\Pdf;

/**
 * Schemas-padrão dos 7 tipos documentais (v1 de cada família). Fonte única
 * usada pela migration de seed, pelos testes e pelo futuro "restaurar
 * padrão" do editor. Paridade funcional com os layouts antigos (blades e
 * documentNarrative), com tema leve e moderno - referências visuais:
 * modelos 1-4 enviados pelo usuário (empresa à esquerda + contatos à
 * direita, faixas de seção, tabela de itens, faixa de total em destaque,
 * banda institucional no rodapé).
 */
class PdfDefaultTemplates
{
    /**
     * @return array<string, array{nome: string, schema: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            'os_abertura' => ['nome' => 'Comprovante de abertura', 'schema' => self::abertura()],
            'os_orcamento' => ['nome' => 'Orçamento', 'schema' => self::orcamento()],
            'os_laudo_tecnico' => ['nome' => 'Laudo técnico', 'schema' => self::laudo()],
            'os_cobranca_manutencao' => ['nome' => 'Cobrança / manutenção', 'schema' => self::cobranca()],
            'os_comprovante_entrega' => ['nome' => 'Comprovante de entrega', 'schema' => self::entrega()],
            'os_devolucao_sem_reparo' => ['nome' => 'Devolução sem reparo', 'schema' => self::devolucao()],
            'os_encerramento' => ['nome' => 'Comprovante de encerramento', 'schema' => self::encerramento()],
            'venda_comprovante' => ['nome' => 'Comprovante de venda', 'schema' => self::vendaComprovante()],
            'caixa_fechamento' => ['nome' => 'Fechamento de caixa', 'schema' => self::caixaFechamento()],
            'venda_devolucao' => ['nome' => 'Comprovante de devolucao', 'schema' => self::vendaDevolucao()],
        ];
    }

    /**
     * Cupom da devolucao - specs/029-devolucao-troca.
     *
     * @return array<string, mixed>
     */
    private static function vendaDevolucao(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => array_merge(self::pagina(), ['papel' => '80mm']),
            'cabecalho' => self::cabecalhoSemOs(),
            'corpo' => [
                ['tipo' => 'grade_campos', 'colunas' => 2, 'campos' => [
                    ['rotulo' => 'Devolucao', 'valor' => '{{ devolucao.numero }}'],
                    ['rotulo' => 'Data', 'valor' => '{{ devolucao.data | data }}'],
                    ['rotulo' => 'Venda de origem', 'valor' => '{{ devolucao.venda_numero }}'],
                    ['rotulo' => 'Cliente', 'valor' => '{{ cliente.nome }}'],
                    ['rotulo' => 'Operador', 'valor' => '{{ devolucao.operador }}'],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Itens devolvidos'],
                ['tipo' => 'tabela', 'fonte' => 'itens', 'vazio_texto' => 'Nenhum item devolvido.', 'colunas' => [
                    ['campo' => 'descricao', 'rotulo' => 'Descricao'],
                    ['campo' => 'quantidade', 'rotulo' => 'Qtd', 'formato' => 'inteiro', 'alinhamento' => 'centro', 'largura' => 8],
                    ['campo' => 'valor_total', 'rotulo' => 'Valor', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 18],
                ]],
                ['tipo' => 'tabela_totais', 'linhas' => [
                    ['rotulo' => 'Total devolvido', 'variavel' => 'devolucao.valor_devolvido', 'formato' => 'moeda', 'destaque' => true],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Reembolso'],
                ['tipo' => 'tabela', 'fonte' => 'reembolsos', 'vazio_texto' => 'Sem reembolso em dinheiro.', 'colunas' => [
                    ['campo' => 'forma_pagamento', 'rotulo' => 'Forma'],
                    ['campo' => 'valor', 'rotulo' => 'Valor', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 20],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'devolucao.valor_abatido', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'observacoes', 'texto' => 'Abatido da divida em aberto: {{ devolucao.valor_abatido | moeda }}.'],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Motivo'],
                ['tipo' => 'paragrafo', 'texto' => '{{ devolucao.motivo }}'],
                ['tipo' => 'observacoes', 'texto' => 'Documento nao fiscal.'],
            ],
            'rodape' => self::rodape(),
        ];
    }

    /**
     * Relatório de fechamento do turno — specs/028-caixa-sessoes.
     *
     * Nasce em 80 mm: sai na mesma impressora do cupom, e o operador anexa à
     * conferência do dia. Sem assinatura — é documento operacional.
     *
     * @return array<string, mixed>
     */
    private static function caixaFechamento(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => array_merge(self::pagina(), ['papel' => '80mm']),
            'cabecalho' => self::cabecalhoSemOs(),
            'corpo' => [
                ['tipo' => 'grade_campos', 'colunas' => 2, 'campos' => [
                    ['rotulo' => 'Caixa', 'valor' => '{{ caixa.numero }}'],
                    ['rotulo' => 'Situação', 'valor' => '{{ caixa.status }}'],
                    ['rotulo' => 'Conta', 'valor' => '{{ caixa.conta }}'],
                    ['rotulo' => 'Operador', 'valor' => '{{ caixa.operador }}'],
                    ['rotulo' => 'Abertura', 'valor' => '{{ caixa.aberto_em | data_hora }}'],
                    ['rotulo' => 'Fechamento', 'valor' => '{{ caixa.fechado_em | data_hora }}'],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Movimento do turno'],
                ['tipo' => 'tabela_totais', 'linhas' => [
                    ['rotulo' => 'Abertura (troco)', 'variavel' => 'caixa.valor_abertura', 'formato' => 'moeda'],
                    ['rotulo' => 'Vendas em dinheiro', 'variavel' => 'caixa.total_vendas', 'formato' => 'moeda'],
                    ['rotulo' => 'Suprimentos', 'variavel' => 'caixa.total_suprimentos', 'formato' => 'moeda'],
                    ['rotulo' => 'Sangrias', 'variavel' => 'caixa.total_sangrias', 'formato' => 'moeda'],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'caixa.total_sangrias', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Sangrias e suprimentos'],
                    ['tipo' => 'tabela', 'fonte' => 'movimentos', 'vazio_texto' => 'Nenhum movimento no turno.', 'colunas' => [
                        ['campo' => 'tipo', 'rotulo' => 'Tipo', 'largura' => 22],
                        ['campo' => 'motivo', 'rotulo' => 'Motivo'],
                        ['campo' => 'valor', 'rotulo' => 'Valor', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 20],
                    ]],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Conferência'],
                ['tipo' => 'tabela_totais', 'linhas' => [
                    ['rotulo' => 'Esperado em caixa', 'variavel' => 'caixa.valor_esperado', 'formato' => 'moeda'],
                    ['rotulo' => 'Contado na gaveta', 'variavel' => 'caixa.valor_informado', 'formato' => 'moeda'],
                    ['rotulo' => 'Diferença', 'variavel' => 'caixa.diferenca', 'formato' => 'moeda', 'destaque' => true],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'caixa.observacoes', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Observações'],
                    ['tipo' => 'paragrafo', 'texto' => '{{ caixa.observacoes }}'],
                ]],
                ['tipo' => 'assinatura', 'visivel_em' => ['a4'], 'rotulos' => ['{{ caixa.operador }} - Operador'], 'linha_data' => true],
            ],
            'rodape' => self::rodape(),
        ];
    }

    /**
     * Cupom não fiscal da venda de balcão — specs/027-vendas-balcao-pdv.
     *
     * Nasce em 80 mm porque o uso normal é a impressora térmica do balcão; o
     * operador pode reimprimir em A4 pela querystring do endpoint. Sem bloco de
     * assinatura: venda de balcão não é documento assinado.
     *
     * @return array<string, mixed>
     */
    private static function vendaComprovante(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => array_merge(self::pagina(), ['papel' => '80mm']),
            'cabecalho' => self::cabecalhoSemOs(),
            'corpo' => [
                ['tipo' => 'grade_campos', 'colunas' => 2, 'campos' => [
                    ['rotulo' => 'Venda', 'valor' => '{{ venda.numero }}'],
                    ['rotulo' => 'Data', 'valor' => '{{ venda.data | data }}'],
                    ['rotulo' => 'Cliente', 'valor' => '{{ cliente.nome }}'],
                    ['rotulo' => 'CPF/CNPJ', 'valor' => '{{ cliente.documento | documento }}'],
                    ['rotulo' => 'Vendedor', 'valor' => '{{ venda.vendedor }}'],
                    ['rotulo' => 'Pagamento', 'valor' => '{{ venda.status_pagamento }}'],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Itens'],
                ['tipo' => 'tabela', 'fonte' => 'itens', 'vazio_texto' => 'Nenhum item nesta venda.', 'colunas' => [
                    ['campo' => 'descricao', 'rotulo' => 'Descrição'],
                    ['campo' => 'quantidade', 'rotulo' => 'Qtd', 'formato' => 'inteiro', 'alinhamento' => 'centro', 'largura' => 8],
                    ['campo' => 'valor_unitario', 'rotulo' => 'Unitário', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 16],
                    ['campo' => 'valor_total', 'rotulo' => 'Total', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 16],
                ]],
                ['tipo' => 'tabela_totais', 'linhas' => [
                    ['rotulo' => 'Subtotal', 'variavel' => 'venda.subtotal', 'formato' => 'moeda'],
                    ['rotulo' => 'Desconto', 'variavel' => 'venda.desconto', 'formato' => 'moeda'],
                    ['rotulo' => 'Acréscimo', 'variavel' => 'venda.acrescimo', 'formato' => 'moeda'],
                    ['rotulo' => 'Total', 'variavel' => 'venda.total', 'formato' => 'moeda', 'destaque' => true],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Pagamento'],
                ['tipo' => 'tabela', 'fonte' => 'pagamentos', 'vazio_texto' => 'Venda em aberto — nenhum pagamento registrado.', 'colunas' => [
                    ['campo' => 'forma_pagamento', 'rotulo' => 'Forma'],
                    ['campo' => 'parcelas', 'rotulo' => 'Parc.', 'formato' => 'inteiro', 'alinhamento' => 'centro', 'largura' => 10],
                    ['campo' => 'valor', 'rotulo' => 'Valor', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 18],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'venda.troco', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'tabela_totais', 'linhas' => [
                        ['rotulo' => 'Troco', 'variavel' => 'venda.troco', 'formato' => 'moeda', 'destaque' => true],
                    ]],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'venda.valor_aberto', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'observacoes', 'texto' => 'Saldo em aberto: {{ venda.valor_aberto | moeda }}.'],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'venda.observacoes', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Observações'],
                    ['tipo' => 'paragrafo', 'texto' => '{{ venda.observacoes }}'],
                ]],
                ['tipo' => 'observacoes', 'texto' => 'Documento não fiscal.'],
            ],
            'rodape' => self::rodape(),
        ];
    }

    /**
     * Converte os tokens legados do modelo de abertura (os_pdf_templates)
     * para a sintaxe nova do motor.
     */
    public static function convertLegacyTokens(string $html): string
    {
        return strtr($html, [
            '{{numero_os}}' => '{{ os.numero }}',
            '{{cliente_nome}}' => '{{ cliente.nome }}',
            '{{cliente_telefone}}' => '{{ cliente.telefone | telefone }}',
            '{{cliente_email}}' => '{{ cliente.email }}',
            '{{equipamento}}' => '{{ equipamento.descricao }}',
            '{{equipamento_tipo}}' => '{{ equipamento.tipo }}',
            '{{equipamento_marca}}' => '{{ equipamento.marca }}',
            '{{equipamento_modelo}}' => '{{ equipamento.modelo }}',
            '{{equipamento_serie}}' => '{{ equipamento.serie }}',
            '{{status_atual}}' => '{{ os.status }}',
            '{{data_abertura}}' => '{{ os.data_abertura | data_hora }}',
            '{{data_entrega}}' => '{{ os.data_entrega | data_hora }}',
            '{{valor_final}}' => '{{ os.valor_final | moeda }}',
            '{{tecnico_nome}}' => '{{ os.tecnico_nome }}',
            '{{prioridade}}' => '{{ os.prioridade }}',
            '{{relato_cliente}}' => '{{ os.relato_cliente }}',
            '{{acessorios_html}}' => '{{ os.acessorios_html }}',
            '{{estado_fisico_html}}' => '{{ os.estado_fisico_html }}',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pagina(): array
    {
        return [
            'tema' => 'leve_moderno_v2',
            'papel' => 'a4',
            'orientacao' => 'retrato',
            'margens' => ['topo' => 12, 'baixo' => 14, 'esq' => 11, 'dir' => 11],
            'fonte' => 'DejaVu Sans',
        ];
    }

    /**
     * Cabeçalho obrigatório: logo + dados institucionais (configurações da
     * empresa) + nome do documento + data de emissão.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cabecalho(): array
    {
        return [
            [
                'tipo' => 'colunas',
                'visivel_em' => ['a4'],
                'larguras' => [25, 50, 25],
                'colunas' => [
                    [
                        ['tipo' => 'imagem', 'token' => '((logo_empresa))', 'largura_max' => 150, 'alinhamento' => 'esquerda'],
                    ],
                    [
                        ['tipo' => 'subtitulo', 'alinhamento' => 'centro', 'texto' => '{{ empresa.nome_fantasia | maiusculas }}'],
                        ['tipo' => 'paragrafo', 'alinhamento' => 'centro', 'texto' => "CNPJ: {{ empresa.cnpj | documento }}\n{{ empresa.telefone | telefone }} - {{ empresa.email }}\n{{ empresa.endereco }}"],
                    ],
                    [
                        ['tipo' => 'imagem', 'token' => '((foto_equipamento_principal))', 'largura_max' => 120, 'alinhamento' => 'direita'],
                    ],
                ],
            ],
            ['tipo' => 'paragrafo', 'visivel_em' => ['80mm'], 'alinhamento' => 'centro', 'texto' => "{{ empresa.nome_fantasia }}\n{{ empresa.telefone | telefone }}"],
            ['tipo' => 'titulo', 'texto' => '{{ documento.nome }}'],
            ['tipo' => 'paragrafo', 'texto' => 'OS {{ os.numero }} - Emitido em {{ documento.gerado_em | data_hora }}'],
            ['tipo' => 'divisor'],
        ];
    }

    /**
     * Cabeçalho para documentos que NÃO pertencem a uma ordem de serviço.
     *
     * Duas diferenças em relação a cabecalho(): sem a coluna da foto do
     * equipamento (não existe equipamento) e sem a linha "OS {{ os.numero }}",
     * que referencia variável fora da allowlist desses tipos — o
     * PdfSchemaValidator rejeitaria o schema.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cabecalhoSemOs(): array
    {
        return [
            [
                'tipo' => 'colunas',
                'visivel_em' => ['a4'],
                'larguras' => [30, 70],
                'colunas' => [
                    [
                        ['tipo' => 'imagem', 'token' => '((logo_empresa))', 'largura_max' => 150, 'alinhamento' => 'esquerda'],
                    ],
                    [
                        ['tipo' => 'subtitulo', 'alinhamento' => 'centro', 'texto' => '{{ empresa.nome_fantasia | maiusculas }}'],
                        ['tipo' => 'paragrafo', 'alinhamento' => 'centro', 'texto' => "CNPJ: {{ empresa.cnpj | documento }}\n{{ empresa.telefone | telefone }} - {{ empresa.email }}\n{{ empresa.endereco }}"],
                    ],
                ],
            ],
            ['tipo' => 'paragrafo', 'visivel_em' => ['80mm'], 'alinhamento' => 'centro', 'texto' => "{{ empresa.nome_fantasia }}\n{{ empresa.telefone | telefone }}"],
            ['tipo' => 'titulo', 'texto' => '{{ documento.nome }}'],
            ['tipo' => 'paragrafo', 'texto' => 'Emitido em {{ documento.gerado_em | data_hora }}'],
            ['tipo' => 'divisor'],
        ];
    }

    /**
     * Aplica o bloco institucional A4 atual sem sobrescrever o restante do
     * cabeçalho personalizado do documento. Isso mantém título, data e blocos
     * próprios, mas impede que criações e clonagens perpetuem o layout legado.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function withStandardHeader(array $schema): array
    {
        $header = is_array($schema['cabecalho'] ?? null)
            ? array_values($schema['cabecalho'])
            : [];

        if ($header === []) {
            $schema['cabecalho'] = self::cabecalho();

            return $schema;
        }

        $standardA4Block = self::cabecalho()[0];
        $replaceAt = null;

        foreach ($header as $index => $block) {
            if (! is_array($block) || ($block['tipo'] ?? null) !== 'colunas') {
                continue;
            }

            $formats = is_array($block['visivel_em'] ?? null)
                ? array_map(static fn (mixed $format): string => strtolower(trim((string) $format)), $block['visivel_em'])
                : [];

            if ($formats === [] || in_array('a4', $formats, true)) {
                $replaceAt = $index;
                break;
            }
        }

        if ($replaceAt === null) {
            array_unshift($header, $standardA4Block);
        } else {
            $header[$replaceAt] = $standardA4Block;
        }

        $schema['cabecalho'] = $header;

        return $schema;
    }

    /**
     * Rodapé obrigatório: banda institucional + metadados de geração +
     * numeração de páginas (marcadores {PAGE_NUM}/{PAGE_COUNT} viram
     * page_text no PdfGenerationService).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rodape(): array
    {
        return [
            ['tipo' => 'divisor'],
            ['tipo' => 'paragrafo', 'alinhamento' => 'centro', 'texto' => '{{ empresa.nome_fantasia }} - {{ empresa.telefone | telefone }} - {{ empresa.email }}'],
            ['tipo' => 'paragrafo', 'alinhamento' => 'centro', 'texto' => 'Gerado em {{ documento.gerado_em | data_hora }} por {{ documento.usuario }} - modelo {{ documento.versao_template }}'],
            ['tipo' => 'paragrafo', 'visivel_em' => ['a4'], 'alinhamento' => 'centro', 'texto' => 'Página {PAGE_NUM} de {PAGE_COUNT}'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function gradeClienteEquipamento(): array
    {
        return [
            ['tipo' => 'cabecalho_secao', 'texto' => 'Dados do cliente e equipamento'],
            ['tipo' => 'grade_campos', 'colunas' => 2, 'campos' => [
                ['rotulo' => 'Cliente', 'valor' => '{{ cliente.nome }}'],
                ['rotulo' => 'Telefone', 'valor' => '{{ cliente.telefone | telefone }}'],
                ['rotulo' => 'Equipamento', 'valor' => '{{ equipamento.descricao }}'],
                ['rotulo' => 'Nº de série', 'valor' => '{{ equipamento.serie }}'],
                ['rotulo' => 'Status da OS', 'valor' => '{{ os.status }}'],
                ['rotulo' => 'Valor final', 'valor' => '{{ os.valor_final | moeda }}'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function abertura(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => self::pagina(),
            'cabecalho' => self::cabecalho(),
            'corpo' => [
                ['tipo' => 'cabecalho_secao', 'texto' => 'Dados do atendimento'],
                ['tipo' => 'grade_campos', 'colunas' => 2, 'campos' => [
                    ['rotulo' => 'Cliente', 'valor' => '{{ cliente.nome }}'],
                    ['rotulo' => 'Telefone', 'valor' => '{{ cliente.telefone | telefone }}'],
                    ['rotulo' => 'Equipamento', 'valor' => '{{ equipamento.descricao }}'],
                    ['rotulo' => 'Nº de série', 'valor' => '{{ equipamento.serie }}'],
                    ['rotulo' => 'Abertura', 'valor' => '{{ os.data_abertura | data_hora }}'],
                    ['rotulo' => 'Previsão de entrega', 'valor' => '{{ os.data_previsao | data_hora }}'],
                    ['rotulo' => 'Prioridade', 'valor' => '{{ os.prioridade }}'],
                    ['rotulo' => 'Técnico responsável', 'valor' => '{{ os.tecnico_nome }}'],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Relato do cliente'],
                ['tipo' => 'paragrafo', 'texto' => '{{ os.relato_cliente }}'],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Acessórios recebidos'],
                ['tipo' => 'texto_rico', 'html' => '{{ os.acessorios_html }}'],
                ['tipo' => 'cabecalho_secao', 'visivel_em' => ['a4'], 'texto' => 'Estado físico na entrada'],
                ['tipo' => 'texto_rico', 'visivel_em' => ['a4'], 'html' => '{{ os.estado_fisico_html }}'],
                ['tipo' => 'observacoes', 'texto' => 'Este comprovante confirma o recebimento do equipamento nas condições descritas acima.'],
            ],
            'rodape' => self::rodape(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function orcamento(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => self::pagina(),
            'cabecalho' => self::cabecalho(),
            'corpo' => [
                ['tipo' => 'cabecalho_secao', 'texto' => 'Dados do cliente'],
                ['tipo' => 'grade_campos', 'colunas' => 2, 'campos' => [
                    ['rotulo' => 'Cliente', 'valor' => '{{ cliente.nome }}'],
                    ['rotulo' => 'Telefone', 'valor' => '{{ cliente.telefone | telefone }}'],
                    ['rotulo' => 'Equipamento', 'valor' => '{{ equipamento.descricao }}'],
                    ['rotulo' => 'Orçamento', 'valor' => '{{ orcamento.numero }}'],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Itens do orçamento'],
                ['tipo' => 'tabela', 'fonte' => 'itens', 'repetir_cabecalho' => true, 'vazio_texto' => 'Nenhum item lançado neste orçamento.', 'colunas' => [
                    ['campo' => 'descricao', 'rotulo' => 'Descrição'],
                    ['campo' => 'quantidade', 'rotulo' => 'Qtd', 'formato' => 'inteiro', 'alinhamento' => 'centro', 'largura' => 8],
                    ['campo' => 'valor_unitario', 'rotulo' => 'Unitário', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 16],
                    ['campo' => 'valor_total', 'rotulo' => 'Total', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 16],
                ]],
                ['tipo' => 'tabela_totais', 'linhas' => [
                    ['rotulo' => 'Subtotal', 'variavel' => 'orcamento.subtotal', 'formato' => 'moeda'],
                    ['rotulo' => 'Desconto', 'variavel' => 'orcamento.desconto', 'formato' => 'moeda'],
                    ['rotulo' => 'TOTAL', 'variavel' => 'orcamento.total', 'formato' => 'moeda', 'destaque' => true],
                ]],
                ...self::blocosCondicoesComerciais(),
                ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.condicoes', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Outras condições'],
                    ['tipo' => 'paragrafo', 'texto' => '{{ orcamento.condicoes }}'],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.observacoes', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Observações'],
                    ['tipo' => 'paragrafo', 'texto' => '{{ orcamento.observacoes }}'],
                ]],
                ...self::blocosAprovacaoOnline(),
            ],
            'rodape' => self::rodape(),
        ];
    }

    /**
     * Aprovação online: botão clicável em vez de URL crua.
     *
     * O clique vale enquanto o link viver, e a validade fica logo abaixo do
     * botão — o cliente vê a ação e o prazo no mesmo lugar, sem precisar
     * decifrar um endereço de 80 caracteres. Público porque a migration que
     * leva o botão aos modelos já publicados reusa esta mesma definição.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function blocosAprovacaoOnline(): array
    {
        return [
            ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.link_aprovacao', 'operador' => 'preenchido'], 'blocos' => [
                ['tipo' => 'cabecalho_secao', 'texto' => 'Aprovação online'],
                ['tipo' => 'paragrafo', 'texto' => 'Clique no botão abaixo para aprovar ou recusar este orçamento.', 'alinhamento' => 'centro'],
                [
                    'tipo' => 'botao_link',
                    'texto' => 'Aprovar ou recusar orçamento',
                    'variavel' => 'orcamento.link_aprovacao',
                    'legenda' => 'Link válido até {{ orcamento.validade_link | data }}.',
                    'alinhamento' => 'centro',
                ],
            ]],
            ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.validade_dias', 'operador' => 'preenchido'], 'blocos' => [
                ['tipo' => 'paragrafo', 'texto' => 'Este orçamento é válido por {{ orcamento.validade_dias }} dia(s) a partir da data de emissão.'],
            ]],
        ];
    }

    /**
     * Condições comerciais do orçamento: formas de pagamento aceitas, chave
     * Pix, parcelamento sem juros e garantia.
     *
     * Público porque a migration que leva estes blocos aos modelos já
     * publicados usa exatamente a mesma definição — modelo novo e modelo
     * antigo nunca divergem.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function blocosCondicoesComerciais(): array
    {
        return [
            ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.formas_pagamento', 'operador' => 'preenchido'], 'blocos' => [
                ['tipo' => 'cabecalho_secao', 'texto' => 'Condições de pagamento'],
                ['tipo' => 'campo', 'rotulo' => 'Formas aceitas', 'valor' => '{{ orcamento.formas_pagamento }}'],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.parcelamento', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'paragrafo', 'texto' => '{{ orcamento.parcelamento }}'],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.chaves_pix', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'tabela', 'fonte' => 'chaves_pix', 'vazio_texto' => 'Nenhuma chave Pix cadastrada.', 'colunas' => [
                        ['campo' => 'tipo', 'rotulo' => 'Tipo', 'largura' => 20],
                        ['campo' => 'chave', 'rotulo' => 'Chave Pix'],
                        ['campo' => 'titular', 'rotulo' => 'Titular', 'largura' => 28],
                    ]],
                ]],
            ]],
            ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.garantia_texto', 'operador' => 'preenchido'], 'blocos' => [
                ['tipo' => 'cabecalho_secao', 'texto' => 'Garantia'],
                ['tipo' => 'paragrafo', 'texto' => '{{ orcamento.garantia_texto }}'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function laudo(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => self::pagina(),
            'cabecalho' => self::cabecalho(),
            'corpo' => [
                ...self::gradeClienteEquipamento(),
                ['tipo' => 'cabecalho_secao', 'texto' => 'Diagnóstico técnico'],
                ['tipo' => 'paragrafo', 'texto' => '{{ os.diagnostico_tecnico }}'],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Solução aplicada'],
                ['tipo' => 'paragrafo', 'texto' => '{{ os.solucao_aplicada }}'],
                ['tipo' => 'assinatura', 'visivel_em' => ['a4'], 'rotulos' => ['{{ os.tecnico_nome }} - Técnico responsável', '{{ cliente.nome }} - Cliente'], 'linha_data' => true],
            ],
            'rodape' => self::rodape(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cobranca(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => self::pagina(),
            'cabecalho' => self::cabecalho(),
            'corpo' => [
                ...self::gradeClienteEquipamento(),
                ['tipo' => 'cabecalho_secao', 'texto' => 'Resumo financeiro'],
                ['tipo' => 'campo', 'rotulo' => 'Valor final consolidado da OS', 'valor' => '{{ os.valor_final | moeda }}'],
                ['tipo' => 'campo', 'rotulo' => 'Forma de pagamento', 'valor' => '{{ os.forma_pagamento }}'],
                ['tipo' => 'tabela', 'visivel_em' => ['a4'], 'fonte' => 'itens', 'vazio_texto' => 'Nenhum item lançado na OS.', 'colunas' => [
                    ['campo' => 'descricao', 'rotulo' => 'Descrição'],
                    ['campo' => 'quantidade', 'rotulo' => 'Qtd', 'formato' => 'inteiro', 'alinhamento' => 'centro', 'largura' => 8],
                    ['campo' => 'valor_total', 'rotulo' => 'Total', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 16],
                ]],
                ['tipo' => 'tabela_totais', 'linhas' => [
                    ['rotulo' => 'TOTAL A PAGAR', 'variavel' => 'os.valor_final', 'formato' => 'moeda', 'destaque' => true],
                ]],
            ],
            'rodape' => self::rodape(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function entrega(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => self::pagina(),
            'cabecalho' => self::cabecalho(),
            'corpo' => [
                ...self::gradeClienteEquipamento(),
                ['tipo' => 'cabecalho_secao', 'texto' => 'Entrega concluída'],
                ['tipo' => 'paragrafo', 'texto' => 'OS encerrada com status de equipamento entregue.'],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Observações do atendimento'],
                ['tipo' => 'paragrafo', 'texto' => '{{ os.solucao_aplicada }}'],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'os.garantia_dias', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'observacoes', 'texto' => 'Garantia: {{ os.garantia_prazo }}, válida até {{ os.garantia_validade | data }}.'],
                ]],
                ['tipo' => 'assinatura', 'visivel_em' => ['a4'], 'rotulos' => ['{{ cliente.nome }} - Cliente'], 'linha_data' => true],
            ],
            'rodape' => self::rodape(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function devolucao(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => self::pagina(),
            'cabecalho' => self::cabecalho(),
            'corpo' => [
                ...self::gradeClienteEquipamento(),
                ['tipo' => 'cabecalho_secao', 'texto' => 'Devolução sem reparo'],
                ['tipo' => 'paragrafo', 'texto' => 'A OS foi encerrada sem execução de reparo.'],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Justificativa / diagnóstico'],
                ['tipo' => 'paragrafo', 'texto' => '{{ os.diagnostico_tecnico }}'],
                ['tipo' => 'assinatura', 'visivel_em' => ['a4'], 'rotulos' => ['{{ cliente.nome }} - Cliente'], 'linha_data' => true],
            ],
            'rodape' => self::rodape(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function encerramento(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => self::pagina(),
            'cabecalho' => self::cabecalho(),
            'corpo' => [
                ['tipo' => 'cabecalho_secao', 'texto' => 'Resumo do encerramento'],
                ['tipo' => 'grade_campos', 'colunas' => 2, 'campos' => [
                    ['rotulo' => 'Cliente', 'valor' => '{{ cliente.nome }}'],
                    ['rotulo' => 'Telefone', 'valor' => '{{ cliente.telefone | telefone }}'],
                    ['rotulo' => 'Equipamento', 'valor' => '{{ equipamento.descricao }}'],
                    ['rotulo' => 'Nº de série', 'valor' => '{{ equipamento.serie }}'],
                    ['rotulo' => 'Status final', 'valor' => '{{ encerramento.status_final }}'],
                    ['rotulo' => 'Data de entrega', 'valor' => '{{ encerramento.data_entrega }}'],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'os.relato_cliente', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Relato do cliente'],
                    ['tipo' => 'paragrafo', 'texto' => '{{ os.relato_cliente }}'],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'os.diagnostico_tecnico', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Diagnóstico técnico'],
                    ['tipo' => 'paragrafo', 'texto' => '{{ os.diagnostico_tecnico }}'],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'os.solucao_aplicada', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Solução aplicada'],
                    ['tipo' => 'paragrafo', 'texto' => '{{ os.solucao_aplicada }}'],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Itens da OS'],
                ['tipo' => 'tabela', 'fonte' => 'itens', 'vazio_texto' => 'Nenhum item lançado na OS.', 'colunas' => [
                    ['campo' => 'descricao', 'rotulo' => 'Descrição'],
                    ['campo' => 'quantidade', 'rotulo' => 'Qtd', 'formato' => 'inteiro', 'alinhamento' => 'centro', 'largura' => 8],
                    ['campo' => 'valor_unitario', 'rotulo' => 'Unitário', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 16],
                    ['campo' => 'valor_total', 'rotulo' => 'Total', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 16],
                ]],
                ['tipo' => 'tabela_totais', 'linhas' => [
                    ['rotulo' => 'Valor final da OS', 'variavel' => 'os.valor_final', 'formato' => 'moeda'],
                    ['rotulo' => 'Valor do título', 'variavel' => 'encerramento.valor_titulo', 'formato' => 'moeda'],
                    ['rotulo' => 'Saldo restante', 'variavel' => 'encerramento.saldo_restante', 'formato' => 'moeda', 'destaque' => true],
                ]],
                ['tipo' => 'cabecalho_secao', 'texto' => 'Recebimentos'],
                ['tipo' => 'tabela', 'fonte' => 'recebimentos', 'vazio_texto' => 'Nenhum recebimento registrado nesta baixa.', 'colunas' => [
                    ['campo' => 'forma_pagamento', 'rotulo' => 'Forma de pagamento'],
                    ['campo' => 'data', 'rotulo' => 'Data', 'formato' => 'data', 'alinhamento' => 'centro', 'largura' => 20],
                    ['campo' => 'valor', 'rotulo' => 'Valor', 'formato' => 'moeda', 'alinhamento' => 'direita', 'largura' => 18],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'encerramento.observacao', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Observações do encerramento'],
                    ['tipo' => 'paragrafo', 'texto' => '{{ encerramento.observacao }}'],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'os.garantia_dias', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'observacoes', 'texto' => 'Garantia: {{ os.garantia_prazo }}, válida até {{ os.garantia_validade | data }}.'],
                ]],
                ['tipo' => 'assinatura', 'visivel_em' => ['a4'], 'rotulos' => ['{{ cliente.nome }} - Cliente'], 'linha_data' => true],
            ],
            'rodape' => self::rodape(),
        ];
    }
}
