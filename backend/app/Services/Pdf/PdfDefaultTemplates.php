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
                ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.condicoes', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Condições comerciais'],
                    ['tipo' => 'paragrafo', 'texto' => '{{ orcamento.condicoes }}'],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.observacoes', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'cabecalho_secao', 'texto' => 'Observações'],
                    ['tipo' => 'paragrafo', 'texto' => '{{ orcamento.observacoes }}'],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.link_aprovacao', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'observacoes', 'texto' => "Aprovação online: acesse o link abaixo para aprovar ou recusar este orçamento.\n{{ orcamento.link_aprovacao }}"],
                ]],
                ['tipo' => 'condicional', 'se' => ['variavel' => 'orcamento.validade_dias', 'operador' => 'preenchido'], 'blocos' => [
                    ['tipo' => 'paragrafo', 'texto' => 'Este orçamento é válido por {{ orcamento.validade_dias }} dia(s) a partir da data de emissão.'],
                ]],
            ],
            'rodape' => self::rodape(),
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
                    ['tipo' => 'observacoes', 'texto' => 'Garantia: {{ os.garantia_dias }} dia(s), válida até {{ os.garantia_validade | data }}.'],
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
                    ['tipo' => 'observacoes', 'texto' => 'Garantia: {{ os.garantia_dias }} dia(s), válida até {{ os.garantia_validade | data }}.'],
                ]],
                ['tipo' => 'assinatura', 'visivel_em' => ['a4'], 'rotulos' => ['{{ cliente.nome }} - Cliente'], 'linha_data' => true],
            ],
            'rodape' => self::rodape(),
        ];
    }
}
