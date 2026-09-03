<?php

namespace App\Services\Fiscal;

use App\Support\Documento;

/**
 * Monta os dados do Anexo X já formatados para impressão. Zero HTML.
 *
 * Existe pelo mesmo motivo do `DanfseLayout`: o Blade de um documento com
 * forma definida em norma tem que ser uma folha de papel, não um lugar onde se
 * decide o que aparece. Toda escolha — o que entra, como é escrito, o que é
 * omitido — fica aqui, onde dá para testar sem renderizar PDF.
 *
 * **O que este layout NÃO leva para o formulário:** acumulado do ano, limite
 * do MEI, lista de receita sem documento fiscal e relação de notas emitidas.
 * O Anexo X é um padrão da Receita Federal e não se modifica — esses números
 * vivem na tela e, no caso da relação de documentos, num PDF anexo separado.
 */
class AnexoXLayout
{
    /**
     * @param  array<string, mixed>  $relatorio
     * @return array<string, mixed>
     */
    public function montar(array $relatorio): array
    {
        $empresa = $relatorio['empresa'] ?? [];

        return [
            'titulo' => 'RELATÓRIO MENSAL DAS RECEITAS BRUTAS',
            'fundamento' => 'Anexo X da Resolução CGSN nº 140, de 22 de maio de 2018',
            'cnpj' => $this->texto($empresa['cnpj'] ?? ''),
            'empreendedor' => $this->empreendedor($empresa),
            'periodo' => $this->texto($relatorio['periodo_label'] ?? ''),
            'blocos' => $this->blocos($relatorio['linhas'] ?? []),
            'total_geral' => [
                'numeral' => 'X',
                'rotulo' => 'TOTAL GERAL DAS RECEITAS BRUTAS NO MÊS (III + VI + IX)',
                'valor' => $this->moeda($relatorio['linhas']['x']['valor'] ?? 0),
                'negativo' => (float) ($relatorio['linhas']['x']['valor'] ?? 0) < 0,
            ],
            'local_e_data' => $this->localEData($empresa),
            'anexos' => [
                'Os documentos fiscais comprobatórios das entradas de mercadorias e serviços tomados '
                    .'referentes ao período;',
                'As notas fiscais relativas às operações ou prestações realizadas eventualmente emitidas.',
            ],
            // Rodapé de procedência: o regime muda o número impresso, e um
            // relatório assinado sem dizer por qual critério foi apurado não
            // dá para conferir depois.
            'rodape' => $this->rodape($relatorio),
        ];
    }

    /**
     * Os três blocos de atividade do formulário, na ordem da norma.
     *
     * A indústria entra sempre, zerada. É linha do formulário oficial: um
     * Anexo X sem ela não é o Anexo X.
     *
     * @param  array<string, mixed>  $linhas
     * @return array<int, array<string, mixed>>
     */
    private function blocos(array $linhas): array
    {
        $definicao = [
            [
                'titulo' => 'RECEITA BRUTA MENSAL – REVENDA DE MERCADORIAS (COMÉRCIO)',
                'numerais' => ['I', 'II', 'III'],
                'chaves' => ['i', 'ii', 'iii'],
            ],
            [
                'titulo' => 'RECEITA BRUTA MENSAL – VENDA DE PRODUTOS INDUSTRIALIZADOS (INDÚSTRIA)',
                'numerais' => ['IV', 'V', 'VI'],
                'chaves' => ['iv', 'v', 'vi'],
            ],
            [
                'titulo' => 'RECEITA BRUTA MENSAL – PRESTAÇÃO DE SERVIÇOS',
                'numerais' => ['VII', 'VIII', 'IX'],
                'chaves' => ['vii', 'viii', 'ix'],
            ],
        ];

        $blocos = [];

        foreach ($definicao as $bloco) {
            $itens = [];

            foreach ($bloco['chaves'] as $posicao => $chave) {
                $linha = $linhas[$chave] ?? ['rotulo' => '', 'valor' => 0, 'calculada' => false];

                $itens[] = [
                    'numeral' => $bloco['numerais'][$posicao],
                    'rotulo' => (string) $linha['rotulo'],
                    'valor' => $this->moeda($linha['valor']),
                    'total' => (bool) ($linha['calculada'] ?? false),
                    'negativo' => (float) $linha['valor'] < 0,
                ];
            }

            $blocos[] = ['titulo' => $bloco['titulo'], 'itens' => $itens];
        }

        return $blocos;
    }

    /**
     * @param  array<string, mixed>  $empresa
     */
    private function empreendedor(array $empresa): string
    {
        $razao = trim((string) ($empresa['razao_social'] ?? ''));

        if ($razao !== '') {
            return $razao;
        }

        // O formulário pede o nome do empreendedor individual. Sem razão
        // social cadastrada, o nome fantasia é a melhor aproximação — e a
        // linha em branco é pior que uma aproximação identificável.
        return $this->texto($empresa['nome_fantasia'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $empresa
     */
    private function localEData(array $empresa): string
    {
        $cidade = trim((string) ($empresa['cidade'] ?? ''));
        $uf = strtoupper(trim((string) ($empresa['uf'] ?? '')));

        $local = $cidade !== '' && $uf !== '' ? $cidade.'/'.$uf : ($cidade !== '' ? $cidade : '');

        // A data fica em branco de propósito: quem assina data no ato, e um
        // relatório que chega pré-datado convida a assinar em outro dia sem
        // ninguém notar.
        return $local === '' ? '' : $local.', ______ de _________________ de _______';
    }

    /**
     * @param  array<string, mixed>  $relatorio
     * @return array<int, string>
     */
    private function rodape(array $relatorio): array
    {
        $linhas = [
            'Apurado pelo regime de '.mb_strtoupper($this->rotuloCurtoDeRegime((string) ($relatorio['regime'] ?? '')))
                .' — '.(string) ($relatorio['regime_label'] ?? '').'.',
        ];

        $fechamento = $relatorio['fechamento'] ?? null;

        if (is_array($fechamento) && ($fechamento['status'] ?? '') === 'fechado') {
            $linhas[] = 'Competência encerrada em '
                .$this->dataCurta((string) ($fechamento['fechado_em'] ?? ''))
                .' (versão '.(int) ($fechamento['versao'] ?? 1).').';
        }

        // Assinar um formulário cujo número difere do que o sistema apurou, sem
        // nenhum traço no papel, é pior que a linha extra. Não é seção nova —
        // é o mesmo rodapé de procedência que já declara o regime e o
        // encerramento.
        $ajuste = round((float) ($relatorio['ajustes']['total'] ?? 0), 2);
        $quantidade = (int) ($relatorio['ajustes']['quantidade'] ?? 0);

        if ($quantidade > 0 && abs($ajuste) > 0.005) {
            $linhas[] = 'Inclui '.$this->moeda($ajuste).' em ajuste manual declarado ('
                .$quantidade.($quantidade === 1 ? ' lançamento' : ' lançamentos').').';
        }

        // Mês que ainda não terminou sai com o que foi lançado até agora, e mês
        // futuro sai zerado. Imprimir isso é legítimo — o bloco anual existe
        // para ser conferido —, mas assinar um formulário declarando R$ 0,00
        // para dezembro em setembro seria declaração falsa. O aviso está aqui
        // justamente para que a folha não seja assinada por engano.
        $competencia = (string) ($relatorio['competencia'] ?? '');
        $mesCorrente = now()->format('Y-m');

        if ($competencia > $mesCorrente) {
            $linhas[] = 'ATENÇÃO: competência futura. Este período ainda não ocorreu — não assine esta folha.';
        } elseif ($competencia === $mesCorrente) {
            $linhas[] = 'ATENÇÃO: competência em curso. O mês ainda não terminou; confira antes de assinar.';
        }

        return $linhas;
    }

    private function rotuloCurtoDeRegime(string $regime): string
    {
        return $regime === AnexoXService::REGIME_CAIXA ? 'caixa' : 'competência';
    }

    private function dataCurta(string $iso): string
    {
        if ($iso === '') {
            return '';
        }

        return date('d/m/Y', strtotime($iso) ?: time());
    }

    private function moeda(mixed $valor): string
    {
        return 'R$ '.number_format((float) $valor, 2, ',', '.');
    }

    private function texto(mixed $valor): string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? '—' : $texto;
    }

    /**
     * Relação de documentos fiscais — PDF ANEXO, nunca parte do formulário.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function montarRelacaoDeDocumentos(array $dados): array
    {
        $empresa = $dados['empresa'] ?? [];

        $documentos = array_map(function (array $documento): array {
            $cancelado = ($documento['situacao'] ?? '') === 'cancelado';

            return [
                'tipo' => (string) ($documento['tipo_label'] ?? ''),
                'serie' => $this->texto($documento['serie'] ?? ''),
                'numero' => $this->texto($documento['numero'] ?? ''),
                'emitido_em' => $this->dataCurta((string) ($documento['emitido_em'] ?? '')),
                'tomador' => $this->texto($documento['tomador_nome'] ?? ''),
                'tomador_documento' => Documento::formatar((string) ($documento['tomador_documento'] ?? '')),
                'origem' => $this->texto($documento['origem'] ?? ''),
                'valor' => $this->moeda($documento['valor_total'] ?? 0),
                'cancelado' => $cancelado,
                'situacao' => $cancelado ? 'CANCELADA' : 'Emitida',
            ];
        }, $dados['documentos'] ?? []);

        $totais = $dados['totais'] ?? [];

        return [
            'titulo' => 'RELAÇÃO DE DOCUMENTOS FISCAIS EMITIDOS',
            'subtitulo' => 'Anexo ao Relatório Mensal das Receitas Brutas — '
                .(string) ($dados['periodo_label'] ?? ''),
            'empreendedor' => $this->empreendedor($empresa),
            'cnpj' => $this->texto($empresa['cnpj'] ?? ''),
            'periodo' => $this->texto($dados['periodo_label'] ?? ''),
            'criterio' => (string) ($dados['criterio'] ?? ''),
            'documentos' => $documentos,
            'vazio' => $documentos === [],
            'totais' => [
                'quantidade' => (int) ($totais['quantidade'] ?? 0),
                'geral' => $this->moeda($totais['geral'] ?? 0),
                'canceladas' => $this->moeda($totais['canceladas'] ?? 0),
                'quantidade_canceladas' => (int) ($totais['quantidade_canceladas'] ?? 0),
            ],
        ];
    }
}
