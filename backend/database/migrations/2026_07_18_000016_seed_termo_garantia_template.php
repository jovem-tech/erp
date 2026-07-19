<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publica o Termo de Garantia aprovado no motor central de PDFs.
 *
 * A migration apenas cria a familia quando ela ainda nao existe. Templates
 * criados ou personalizados diretamente no ambiente de destino nunca sao
 * sobrescritos. O schema abaixo e um snapshot imutavel da versao publicada
 * no ambiente de desenvolvimento em 18/07/2026.
 */
return new class extends Migration
{
    private const TYPE_CODE = 'custom_termo_de_garantia_trt0vfnh';

    private const NAME = 'Termo de Garantia';

    private const BASE_TYPE_CODE = 'os_encerramento';

    public function up(): void
    {
        if (
            ! Schema::hasTable('pdf_templates')
            || ! Schema::hasTable('pdf_template_versoes')
            || ! Schema::hasColumn('pdf_templates', 'personalizado')
            || ! Schema::hasColumn('pdf_templates', 'tipo_base_codigo')
            || ! Schema::hasColumn('pdf_templates', 'origem_template_id')
        ) {
            return;
        }

        $schema = $this->schema();
        $schemaJson = json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        DB::transaction(function () use ($schema, $schemaJson): void {
            $existing = DB::table('pdf_templates')
                ->where('tipo_codigo', self::TYPE_CODE)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return;
            }

            // Impede duplicidade caso um Termo de Garantia ja tenha sido
            // criado manualmente no ambiente de destino com outro codigo.
            $sameDocument = DB::table('pdf_templates')
                ->where('personalizado', true)
                ->where('nome', self::NAME)
                ->lockForUpdate()
                ->first();

            if ($sameDocument !== null) {
                return;
            }

            $now = now();
            $templateId = DB::table('pdf_templates')->insertGetId([
                'tipo_codigo' => self::TYPE_CODE,
                'nome' => self::NAME,
                'descricao' => 'Documento utilizado na finalização da ordem de serviço entregue, reparada e paga.',
                'arquivado' => false,
                'personalizado' => true,
                'tipo_base_codigo' => self::BASE_TYPE_CODE,
                'origem_template_id' => null,
                'criado_por' => null,
                'atualizado_por' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('pdf_template_versoes')->insert([
                'template_id' => $templateId,
                'versao' => 1,
                'status' => 'publicado',
                'schema_json' => $schemaJson,
                'papel' => (string) $schema['pagina']['papel'],
                'orientacao' => (string) $schema['pagina']['orientacao'],
                'margens_json' => json_encode(
                    $schema['pagina']['margens'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'fonte' => (string) $schema['pagina']['fonte'],
                'hash_schema' => hash('sha256', $schemaJson),
                'publicado_em' => $now,
                'publicado_por' => null,
                'criado_por' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 3);
    }

    public function down(): void
    {
        if (! Schema::hasTable('pdf_templates') || ! Schema::hasTable('pdf_template_versoes')) {
            return;
        }

        $schemaJson = json_encode(
            $this->schema(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $expectedHash = hash('sha256', $schemaJson);

        DB::transaction(function () use ($expectedHash): void {
            $template = DB::table('pdf_templates')
                ->where('tipo_codigo', self::TYPE_CODE)
                ->lockForUpdate()
                ->first();

            if ($template === null || ! (bool) $template->personalizado) {
                return;
            }

            $versions = DB::table('pdf_template_versoes')
                ->where('template_id', $template->id)
                ->lockForUpdate()
                ->get();

            if (
                $versions->count() !== 1
                || (int) $versions->first()->versao !== 1
                || ! hash_equals($expectedHash, (string) $versions->first()->hash_schema)
            ) {
                return;
            }

            DB::table('pdf_template_versoes')->where('template_id', $template->id)->delete();
            DB::table('pdf_templates')->where('id', $template->id)->delete();
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'versao_schema' => 1,
            'pagina' => [
                'tema' => 'leve_moderno_v2',
                'papel' => 'a4',
                'orientacao' => 'retrato',
                'margens' => ['topo' => 12, 'baixo' => 14, 'esq' => 11, 'dir' => 11],
                'fonte' => 'DejaVu Sans',
            ],
            'cabecalho' => [
                [
                    'tipo' => 'colunas',
                    'visivel_em' => ['a4'],
                    'larguras' => [25, 50, 25],
                    'colunas' => [
                        [[
                            'tipo' => 'imagem',
                            'token' => '((logo_empresa))',
                            'largura_max' => 150,
                            'alinhamento' => 'esquerda',
                        ]],
                        [
                            [
                                'tipo' => 'subtitulo',
                                'alinhamento' => 'centro',
                                'texto' => '{{ empresa.nome_fantasia | maiusculas }}',
                            ],
                            [
                                'tipo' => 'paragrafo',
                                'alinhamento' => 'centro',
                                'texto' => "CNPJ: {{ empresa.cnpj | documento }}\n{{ empresa.telefone | telefone }} - {{ empresa.email }}\n{{ empresa.endereco }}",
                            ],
                        ],
                        [[
                            'tipo' => 'imagem',
                            'token' => '((foto_equipamento_principal))',
                            'largura_max' => 120,
                            'alinhamento' => 'direita',
                        ]],
                    ],
                ],
                ['tipo' => 'titulo', 'texto' => '{{ documento.nome }}'],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'OS {{ os.numero }} - Emitido em {{ documento.gerado_em | data_hora }}',
                ],
                ['tipo' => 'divisor'],
            ],
            'corpo' => [
                ['tipo' => 'cabecalho_secao', 'texto' => 'Termo de Garantia'],
                [
                    'tipo' => 'subtitulo',
                    'texto' => 'TERMO DE GARANTIA DOS SERVIÇOS',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'A {{ empresa.nome_fantasia }}, inscrita no CNPJ sob o nº {{ empresa.cnpj | documento }}, declara que os serviços descritos na Ordem de Serviço nº {{ os.numero }} foram executados no equipamento {{ equipamento.tipo }} {{ equipamento.marca }} {{ equipamento.modelo }}, pertencente a {{ cliente.nome }}.',
                    'alinhamento' => 'esquerda',
                ],
                ['tipo' => 'cabecalho_secao', 'texto' => '1. OBJETO DA GARANTIA'],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'A presente garantia abrange os serviços efetivamente executados, os componentes substituídos e os procedimentos descritos nesta Ordem de Serviço, especialmente:',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => '{{ os.solucao_aplicada }}',
                    'alinhamento' => 'esquerda',
                ],
                ['tipo' => 'cabecalho_secao', 'texto' => '2. GARANTIA LEGAL E CONTRATUAL'],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Os direitos assegurados pela legislação de proteção ao consumidor permanecem integralmente preservados.',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'A garantia contratual concedida por este estabelecimento é complementar à garantia legal e possui prazo de {{ os.garantia_dias }} dias, contado a partir da entrega do equipamento, com validade até {{ os.garantia_validade | data }}.',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'A garantia legal observará os prazos e condições previstos no Código de Defesa do Consumidor. Tratando-se de vício oculto, o prazo para reclamação será contado a partir do momento em que o defeito se tornar evidente, conforme a legislação aplicável.',
                    'alinhamento' => 'esquerda',
                ],
                ['tipo' => 'cabecalho_secao', 'texto' => '3. COBERTURA'],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Durante o prazo de garantia, sendo constatado defeito relacionado ao serviço executado ou à peça fornecida, o equipamento será reavaliado sem custo para o consumidor.',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Confirmada a cobertura, o estabelecimento providenciará, conforme o caso:',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'lista',
                    'itens_estaticos' => [
                        'a reexecução do serviço sem custo adicional;',
                        'o reparo ou a substituição da peça fornecida;',
                        'a restituição do valor correspondente; ou',
                        'o abatimento proporcional do preço.',
                    ],
                    'estilo' => 'topicos',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'A medida aplicável será definida de acordo com a natureza do defeito e os direitos assegurados ao consumidor.',
                    'alinhamento' => 'esquerda',
                ],
                ['tipo' => 'cabecalho_secao', 'texto' => '4. SITUAÇÕES NÃO COBERTAS'],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'A garantia contratual não cobre defeitos comprovadamente decorrentes de:',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'lista',
                    'itens_estaticos' => [
                        'quedas, impactos, pressão, quebra ou danos físicos ocorridos após a entrega;',
                        'contato com líquidos, umidade excessiva, oxidação ou exposição a condições inadequadas;',
                        'descarga elétrica, variação de tensão ou utilização de carregadores e acessórios incompatíveis;',
                        'uso inadequado ou contrário às orientações do fabricante;',
                        'intervenção posterior realizada por terceiros, quando demonstrada relação entre a intervenção e o novo defeito;',
                        'defeitos em componentes que não foram reparados ou substituídos nesta Ordem de Serviço e que não tenham relação com o serviço executado;',
                        'desgaste natural, consumíveis ou danos surgidos depois da entrega sem relação com o reparo realizado.',
                    ],
                    'estilo' => 'topicos',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Essas situações não afastam a responsabilidade do estabelecimento quando o dano decorrer de falha comprovada no serviço prestado ou na peça fornecida.',
                    'alinhamento' => 'esquerda',
                ],
                ['tipo' => 'cabecalho_secao', 'texto' => '5. SOLICITAÇÃO DA GARANTIA'],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Para solicitar atendimento, o consumidor deverá apresentar o equipamento e informar o número da Ordem de Serviço.',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'O atendimento poderá ser solicitado pelos canais oficiais:',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Telefone: {{ empresa.telefone | telefone }} E-mail: {{ empresa.email }} Endereço: {{ empresa.endereco }}',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'O equipamento será submetido à avaliação técnica. Caso o defeito esteja coberto, nenhum valor será cobrado pela correção. Serviços adicionais ou problemas sem relação com o reparo anterior dependerão de novo orçamento e de autorização expressa do consumidor.',
                    'alinhamento' => 'esquerda',
                ],
                ['tipo' => 'cabecalho_secao', 'texto' => '6. DADOS E ARQUIVOS DO EQUIPAMENTO'],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Recomenda-se que o consumidor mantenha cópia de segurança de seus dados antes da entrega do equipamento. Essa recomendação não exclui eventual responsabilidade por perda ou dano decorrente de falha comprovada na prestação do serviço.',
                    'alinhamento' => 'esquerda',
                ],
                ['tipo' => 'cabecalho_secao', 'texto' => '7. DISPOSIÇÕES FINAIS'],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Nenhuma disposição deste termo poderá ser interpretada como renúncia, limitação ou exclusão dos direitos assegurados pelo Código de Defesa do Consumidor.',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Este termo está vinculado à Ordem de Serviço nº {{ os.numero }} e deve ser apresentado juntamente com o respectivo comprovante de atendimento.',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Data da entrega: {{ os.data_entrega | data }}',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Cliente: {{ cliente.nome }}',
                    'alinhamento' => 'esquerda',
                ],
                [
                    'tipo' => 'paragrafo',
                    'texto' => 'Empresa responsável: {{ empresa.nome_fantasia }}',
                    'alinhamento' => 'esquerda',
                ],
            ],
            'rodape' => [
                [
                    'tipo' => 'paragrafo',
                    'alinhamento' => 'centro',
                    'texto' => 'Gerado em {{ documento.gerado_em | data_hora }} por {{ documento.usuario }} - modelo {{ documento.versao_template }}',
                ],
                [
                    'tipo' => 'paragrafo',
                    'visivel_em' => ['a4'],
                    'alinhamento' => 'centro',
                    'texto' => 'Página {PAGE_NUM} de {PAGE_COUNT}',
                ],
                [
                    'tipo' => 'paragrafo',
                    'alinhamento' => 'centro',
                    'texto' => '{{ empresa.nome_fantasia }} - {{ empresa.telefone | telefone }} - {{ empresa.email }}',
                ],
            ],
        ];
    }
};
