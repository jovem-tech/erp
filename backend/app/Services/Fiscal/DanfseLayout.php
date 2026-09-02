<?php

namespace App\Services\Fiscal;

use App\Support\MunicipioIbge;
use App\Support\QrCodePng;
use Illuminate\Support\Carbon;

/**
 * Monta o DANFSe conforme a Nota Técnica nº 008 (SE/CGNFS-e, 05/05/2026).
 *
 * Traduz o que o `NfseXmlImporter` leu do XML no conjunto de campos já
 * formatados que o Blade imprime — códigos viram descrições, valores viram
 * "R$ 0,00", campo vazio vira traço. **Toda a norma mora aqui**; o Blade só
 * posiciona.
 *
 * Por que uma classe separada do Blade: a NT-008 tem regra demais para viver
 * dentro de template (supressão de blocos, truncamento com reticências por
 * tamanho de campo, ordem obrigatória das informações complementares,
 * redistribuição de altura). Aqui isso é testável sem renderizar PDF.
 *
 * Referências no código apontam para os itens da própria NT-008.
 */
class DanfseLayout
{
    /**
     * Endereço da consulta pública, item 2.4.3. A chave entra depois do "=".
     */
    public const CONSULTA = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=';

    /**
     * Altura útil do corpo do DANFSe, em centímetros.
     *
     * A4 tem 29,70cm; a margem obrigatória do item 2.2.2 é de 0,20cm em cima e
     * embaixo, e a borda de 1 ponto do item 2.2.3 come mais 0,07cm no total.
     * Sobram 29,23cm; o valor aqui é 28,90, aferido renderizando o documento —
     * inclusive no pior caso, com descrição do serviço e informações
     * complementares nos tamanhos máximos que o item 2.4.5 permite. Os ~0,3cm
     * de folga no rodapé são baratos perto de o canhoto cair na segunda página,
     * que o item 2.2 proíbe.
     *
     * A tabela de coordenadas do item 2.4.5 termina o canhoto em y=28,77, mas
     * ela parte de uma margem de 0,30cm que a própria norma não permite; o
     * espaço a mais vai para "Informações Complementares", como mandam os itens
     * 2.3.1 a 2.3.3.
     */
    private const ALTURA_CORPO = 28.90;

    /**
     * Caracteres que cabem numa linha do corpo do documento, em 7 pontos.
     *
     * Medido na fonte que o DANFSe usa (métrica da Arial): avanço médio de
     * 556/1000 em, que a 7pt dá 0,137cm por caractere numa faixa útil de
     * ~20,4cm. Fica em 145, e não nos 148 do cálculo, para o texto real —
     * com maiúsculas e acentos — não estourar a conta.
     */
    private const CARACTERES_POR_LINHA = 145;

    /**
     * Altura de uma linha de texto corrido, em centímetros.
     *
     * **Medida no PDF, não calculada.** O dompdf ignora `line-height` no texto
     * que ele mesmo quebra: o passo sai da métrica da fonte embutida e fica em
     * 0,385cm para 7 pontos. Arredondamos para cima — o modelo precisa errar
     * para o lado de sobrar espaço, porque errar para o outro joga o canhoto
     * para uma segunda página.
     */
    private const ALTURA_LINHA = 0.40;

    /**
     * Sobra vertical de cada linha além da altura declarada, em centímetros:
     * os 2 pontos de padding do Blade mais a divisória do item 2.2.3.
     *
     * Aferido contra o PDF renderizado (0,08 a 0,11 por linha, conforme o
     * bloco); fica no teto da faixa pelo mesmo motivo de `ALTURA_LINHA`.
     */
    private const ENTRELINHA = 0.11;

    /**
     * @param  array<string, mixed>  $nota  saída de NfseXmlImporter::ler()
     * @return array<string, mixed>
     */
    public function montar(array $nota, ?string $marcaDagua = null): array
    {
        $tomador = $this->bloco($nota['tomador'] ?? [], 'TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
        $destinatario = $this->destinatario($nota);
        $intermediario = $this->bloco($nota['intermediario'] ?? [], 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
        $issqn = $this->issqn($nota);
        $federal = $this->federal($nota);
        $servico = $this->servico($nota);
        $complementares = $this->complementares($nota);

        $alturaInfo = $this->alturaInformacoesComplementares(
            $tomador, $destinatario, $intermediario, $issqn, $federal, $servico
        );

        // O texto se ajusta ao quadro, e nao o contrario: deixar o bloco crescer
        // empurraria o canhoto para uma segunda pagina, que o item 2.2 proibe.
        // Truncar e' o remedio que a propria norma usa (item 2.4.5) e o item
        // 2.5.3 confirma que este e' o bloco que cede espaco.
        $complementares['texto'] = (string) $this->limitar(
            $complementares['texto'],
            $this->cabeEm($alturaInfo, $complementares['tributos'])
        );

        return [
            'cabecalho' => $this->cabecalho($nota),
            'identificacao' => $this->identificacao($nota),
            'qrcode' => $this->qrcode((string) ($nota['chave'] ?? '')),
            'prestador' => $this->prestador($nota),
            'tomador' => $tomador,
            'destinatario' => $destinatario,
            'intermediario' => $intermediario,
            'servico' => $servico,
            'issqn' => $issqn,
            'federal' => $federal,
            'ibscbs' => $this->ibscbs($nota),
            'valores' => $this->valores($nota),
            'complementares' => $complementares,
            'canhoto' => [
                'numero_chave' => $this->traco(trim(
                    $this->texto($nota['numero'] ?? null).' / '.$this->texto($nota['chave'] ?? null)
                )),
            ],
            'marca_dagua' => $marcaDagua,
            'alturas' => ['informacoes_complementares' => $alturaInfo],
        ];
    }

    // -----------------------------------------------------------------
    // Blocos
    // -----------------------------------------------------------------

    /**
     * Cabeçalho, item 2.4.3.
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, mixed>
     */
    private function cabecalho(array $nota): array
    {
        $codigoTributacao = preg_replace('/\D/', '', (string) ($nota['codigo_tributacao'] ?? ''));

        return [
            // "Obs.: Nao exibir, quando o item do cod. de tributacao nacional
            // informado for 99" (item 2.4.5) — o item sao os dois primeiros
            // digitos do cTribNac.
            'municipio' => str_starts_with((string) $codigoTributacao, '99')
                ? null
                : $this->municipioComUf(
                    $nota['prestador']['cmun'] ?? null,
                    $nota['municipio'] ?? null,
                    $nota['prestador']['uf'] ?? null
                ),
            'ambiente_gerador' => $this->traco($nota['ambiente_gerador'] ?? null),
            'tipo_ambiente' => $this->traco($nota['ambiente'] ?? null),
            // Item 2.4.3, observacao: so' em producao restrita (homologacao).
            'sem_validade_juridica' => (string) ($nota['ambiente'] ?? '') === '2',
        ];
    }

    /**
     * Dados de identificação da NFS-e, item 2.1.2.
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, string>
     */
    private function identificacao(array $nota): array
    {
        return [
            'chave' => $this->traco($nota['chave'] ?? null),
            'numero' => $this->traco($nota['numero'] ?? null),
            'competencia' => $this->traco($this->data($nota['competencia'] ?? null)),
            'emissao_nfse' => $this->traco($this->dataHora($nota['processado_em'] ?? null)),
            'numero_dps' => $this->traco($nota['numero_dps'] ?? null),
            'serie_dps' => $this->traco($nota['serie'] ?? null),
            'emissao_dps' => $this->traco($this->dataHora($nota['emitido_em'] ?? null)),
            'emitente' => $this->traco(DanfseCodigos::emitente($nota['emitente_tipo'] ?? null)),
            'situacao' => $this->traco($this->limitar(DanfseCodigos::situacao($nota['situacao_codigo'] ?? null), 37)),
            // Nao ha tabela publicada de `finNFSe` no Anexo IV vigente: imprime
            // o codigo cru em vez de inventar descricao (ver DanfseCodigos).
            'finalidade' => $this->traco($this->limitar($nota['finalidade'] ?? null, 37)),
        ];
    }

    /**
     * QR Code da consulta pública, item 2.4.3.
     *
     * @return array<string, string>
     */
    private function qrcode(string $chave): array
    {
        $chave = preg_replace('/\D/', '', $chave);
        $url = self::CONSULTA.$chave;

        return [
            'url' => $url,
            'imagem' => $chave === '' ? '' : QrCodePng::dataUri($url),
        ];
    }

    /**
     * Prestador / Fornecedor, item 2.1.3.
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, mixed>
     */
    private function prestador(array $nota): array
    {
        $pessoa = (array) ($nota['prestador'] ?? []);

        return $this->campos($pessoa) + [
            'simples_nacional' => $this->traco(
                $this->limitar(DanfseCodigos::simplesNacional($pessoa['simples_nacional'] ?? null), 37)
            ),
            'regime_apuracao' => $this->traco(
                $this->limitar(DanfseCodigos::regimeApuracaoSn($pessoa['regime_apuracao_sn'] ?? null), 77)
            ),
        ];
    }

    /**
     * Destinatário da operação, itens 2.1.5, 2.3.2 e nota 3.
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, mixed>
     */
    private function destinatario(array $nota): array
    {
        $dest = (array) ($nota['destinatario'] ?? []);
        $toma = (array) ($nota['tomador'] ?? []);

        $mesmaPessoa = ($dest['documento'] ?? null) !== null
            && preg_replace('/\D/', '', (string) $dest['documento'])
                === preg_replace('/\D/', '', (string) ($toma['documento'] ?? ''));

        if ($mesmaPessoa) {
            return ['suprimido' => true, 'aviso' => 'O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO'];
        }

        return $this->bloco($dest, 'DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
    }

    /**
     * Bloco de pessoa que vira uma linha só quando não foi informado (nota 2).
     *
     * @param  array<string, mixed>  $pessoa
     * @return array<string, mixed>
     */
    private function bloco(array $pessoa, string $aviso): array
    {
        $identificado = ($pessoa['documento'] ?? null) !== null || ($pessoa['nome'] ?? null) !== null;

        return $identificado
            ? ['suprimido' => false] + $this->campos($pessoa)
            : ['suprimido' => true, 'aviso' => $aviso];
    }

    /**
     * Campos comuns a prestador, tomador, destinatário e intermediário.
     *
     * @param  array<string, mixed>  $pessoa
     * @return array<string, string>
     */
    private function campos(array $pessoa): array
    {
        return [
            'documento' => $this->traco($this->documento($pessoa['documento_tipo'] ?? null, $pessoa['documento'] ?? null)),
            'im' => $this->traco($pessoa['im'] ?? null),
            'fone' => $this->traco($this->telefone($pessoa['fone'] ?? null)),
            'nome' => $this->traco($this->limitar($pessoa['nome'] ?? null, 77)),
            'municipio' => $this->traco($this->municipioComUf(
                $pessoa['cmun'] ?? null,
                $pessoa['cidade_exterior'] ?? null,
                $pessoa['uf'] ?? null
            )),
            'ibge_cep' => $this->traco($this->ibgeCep($pessoa)),
            'endereco' => $this->traco($this->limitar($this->endereco($pessoa), 77)),
            'email' => $this->traco($this->limitar($pessoa['email'] ?? null, 77)),
        ];
    }

    /**
     * Serviço prestado, item 2.1.7.
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, mixed>
     */
    private function servico(array $nota): array
    {
        // "SE xTribMun <> '' ENTAO Descricao Municipal SENAO Descricao
        // Nacional" (item 2.4.5).
        $descricaoCodigo = $this->texto($nota['descricao_tributacao_municipal'] ?? null) !== ''
            ? $nota['descricao_tributacao_municipal']
            : ($nota['descricao_tributacao'] ?? null);

        return [
            'codigo_tributacao' => $this->traco(trim(
                $this->traco($this->codigoTributacao($nota['codigo_tributacao'] ?? null))
                .' / '
                .$this->traco($nota['codigo_tributacao_municipal'] ?? null)
            )),
            'nbs' => $this->traco($this->codigoNbs($nota['codigo_nbs'] ?? null)),
            'local' => $this->traco($this->localComPais(
                $nota['local_prestacao'] ?? null,
                $nota['codigo_local_prestacao'] ?? null,
                $nota['pais_prestacao'] ?? null
            )),
            'descricao_codigo' => $this->traco($this->limitar($descricaoCodigo, 167)),
            'descricao' => $this->traco($this->limitar($nota['descricao_servico'] ?? null, 1297)),
        ];
    }

    /**
     * Tributação municipal (ISSQN), item 2.1.8 e notas 4 e 5.
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, mixed>
     */
    private function issqn(array $nota): array
    {
        $issqn = (array) ($nota['issqn'] ?? []);

        if (($issqn['tributacao'] ?? null) === null) {
            return [
                'suprimido' => true,
                'aviso' => 'TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN',
            ];
        }

        // O regime especial nao mora no grupo do ISSQN: a NT-008 aponta este
        // campo para `infDPS/prest/regTrib/regEspTrib`.
        $regimeEspecial = ($nota['prestador']['regime_especial'] ?? null);

        // Nota 5: cada uma destas duas linhas some inteira quando nenhum dos
        // seus campos existe no XML. "0 - Nenhum" conta como ausencia — o XSD
        // obriga a enviar `regEspTrib`, entao o zero nunca some sozinho, e e'
        // assim que o DANFSe do proprio portal se comporta.
        $beneficios = array_filter([
            $regimeEspecial === '0' ? null : $regimeEspecial,
            $issqn['tipo_imunidade'] ?? null,
            $issqn['suspensao'] ?? null,
            $issqn['processo_suspensao'] ?? null,
        ], static fn ($v) => $v !== null);

        $deducoes = array_filter([
            $issqn['beneficio_municipal'] ?? null,
            $issqn['calculo_bm'] ?? null,
            $issqn['total_deducoes'] ?? null,
            $issqn['desconto_incondicionado'] ?? null,
        ], static fn ($v) => $v !== null);

        return [
            'suprimido' => false,
            'tributacao' => $this->traco($this->limitar(DanfseCodigos::tributacaoIssqn($issqn['tributacao'] ?? null), 21)),
            'municipio_incidencia' => $this->traco($this->localComPais(
                $issqn['municipio_incidencia'] ?? null,
                $issqn['codigo_municipio_incidencia'] ?? null,
                $issqn['pais_resultado'] ?? null
            )),
            'linha_beneficios' => $beneficios !== [],
            'regime_especial' => $this->traco(DanfseCodigos::regimeEspecial($regimeEspecial)),
            'imunidade' => $this->traco($this->limitar(DanfseCodigos::imunidade($issqn['tipo_imunidade'] ?? null), 37)),
            'suspensao' => $this->traco($this->limitar(DanfseCodigos::suspensao($issqn['suspensao'] ?? null), 37)),
            'processo_suspensao' => $this->traco($issqn['processo_suspensao'] ?? null),
            'linha_deducoes' => $deducoes !== [],
            'beneficio_municipal' => $this->traco(DanfseCodigos::beneficioMunicipal($issqn['beneficio_municipal'] ?? null)),
            'calculo_bm' => $this->traco($this->moeda($issqn['calculo_bm'] ?? null)),
            'total_deducoes' => $this->traco($this->moeda($issqn['total_deducoes'] ?? null)),
            'desconto_incondicionado' => $this->traco($this->moeda($issqn['desconto_incondicionado'] ?? null)),
            'base_calculo' => $this->traco($this->moeda($issqn['base_calculo'] ?? null)),
            'aliquota' => $this->traco($this->percentual($issqn['aliquota'] ?? null)),
            'retencao' => $this->traco(DanfseCodigos::retencaoIssqn($issqn['retencao'] ?? null)),
            'apurado' => $this->traco($this->moeda($issqn['apurado'] ?? null)),
        ];
    }

    /**
     * Tributação federal (exceto CBS), item 2.1.9 e nota 6.
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, mixed>
     */
    private function federal(array $nota): array
    {
        $federal = (array) ($nota['federal'] ?? []);
        $competencia = $this->ano($nota['competencia'] ?? null);

        return [
            'irrf' => $this->traco($this->moeda($federal['irrf'] ?? null)),
            'previdenciaria' => $this->traco($this->moeda($federal['previdenciaria'] ?? null)),
            'sociais' => $this->traco($this->moeda($federal['sociais'] ?? null)),
            // Nota 6: a linha de PIS/COFINS so' e' impressa para NFS-e com
            // competencia ate' o fim do ano-calendario de 2026.
            'linha_pis_cofins' => $competencia === null || $competencia <= 2026,
            'pis' => $this->traco($this->moeda($federal['pis'] ?? null)),
            'cofins' => $this->traco($this->moeda($federal['cofins'] ?? null)),
            'retencao_pis_cofins' => $this->traco(
                $this->limitar(DanfseCodigos::retencaoPisCofins($federal['retencao_pis_cofins'] ?? null), 35)
            ),
        ];
    }

    /**
     * Tributação IBS/CBS, item 2.1.10.
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, string>
     */
    private function ibscbs(array $nota): array
    {
        $ibs = (array) ($nota['ibscbs'] ?? []);
        $issqn = (array) ($nota['issqn'] ?? []);
        $totais = (array) ($nota['totais'] ?? []);

        // "Somatorio de todos estes campos" (item 2.4.5): descontos
        // incondicionados, reembolsos, ISSQN, PIS e COFINS.
        $exclusoes = array_sum(array_map(
            static fn ($v) => (float) ($v ?? 0),
            [
                $totais['desconto_incondicionado'] ?? null,
                $ibs['reembolsos'] ?? null,
                $issqn['apurado'] ?? null,
                $nota['federal']['pis'] ?? null,
                $nota['federal']['cofins'] ?? null,
            ]
        ));

        return [
            'cst' => $this->traco(trim(
                $this->traco($ibs['cst'] ?? null).' / '.$this->traco($ibs['classificacao'] ?? null)
            )),
            'indicador_operacao' => $this->traco(implode(' / ', [
                $this->traco($ibs['indicador_operacao'] ?? null),
                $this->traco($ibs['codigo_municipio_incidencia'] ?? null),
                $this->traco($this->municipioComUf(
                    $ibs['codigo_municipio_incidencia'] ?? null,
                    $ibs['municipio_incidencia'] ?? null,
                    null
                )),
            ])),
            'exclusoes' => $this->moeda((string) $exclusoes),
            'base_calculo' => $this->traco($this->moeda($ibs['base_calculo'] ?? null)),
            'reducao_aliquota' => $this->traco(implode(' / ', [
                $this->traco($this->percentual($ibs['reducao_aliquota_uf'] ?? null)),
                $this->traco($this->percentual($ibs['reducao_aliquota_mun'] ?? null)),
                $this->traco($this->percentual($ibs['reducao_aliquota_cbs'] ?? null)),
            ])),
            'aliquota_ibs' => $this->traco(implode(' / ', [
                $this->traco($this->percentual($ibs['aliquota_ibs_uf'] ?? null)),
                $this->traco($this->percentual($ibs['aliquota_ibs_mun'] ?? null)),
            ])),
            'aliquota_efetiva_mun' => $this->traco($this->percentual($ibs['aliquota_efetiva_mun'] ?? null)),
            'valor_ibs_mun' => $this->traco($this->moeda($ibs['valor_ibs_mun'] ?? null)),
            'aliquota_efetiva_uf' => $this->traco($this->percentual($ibs['aliquota_efetiva_uf'] ?? null)),
            'valor_ibs_uf' => $this->traco($this->moeda($ibs['valor_ibs_uf'] ?? null)),
            'valor_ibs_total' => $this->traco($this->moeda($ibs['valor_ibs_total'] ?? null)),
            'aliquota_cbs' => $this->traco($this->percentual($ibs['aliquota_cbs'] ?? null)),
            'aliquota_efetiva_cbs' => $this->traco($this->percentual($ibs['aliquota_efetiva_cbs'] ?? null)),
            'valor_cbs' => $this->traco($this->moeda($ibs['valor_cbs'] ?? null)),
        ];
    }

    /**
     * Valor total da NFS-e, item 2.1.11.
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, string>
     */
    private function valores(array $nota): array
    {
        $totais = (array) ($nota['totais'] ?? []);
        $ibs = (array) ($nota['ibscbs'] ?? []);

        $totalIbsCbs = ($ibs['valor_ibs_total'] ?? null) !== null || ($ibs['valor_cbs'] ?? null) !== null
            ? (string) ((float) ($ibs['valor_ibs_total'] ?? 0) + (float) ($ibs['valor_cbs'] ?? 0))
            : null;

        return [
            'servico' => $this->traco($this->moeda($totais['servico'] ?? null)),
            'desconto_incondicionado' => $this->traco($this->moeda($totais['desconto_incondicionado'] ?? null)),
            'desconto_condicionado' => $this->traco($this->moeda($totais['desconto_condicionado'] ?? null)),
            'retencoes' => $this->traco($this->moeda($totais['retencoes'] ?? null)),
            'liquido' => $this->traco($this->moeda($totais['liquido'] ?? null)),
            'total_ibs_cbs' => $this->traco($this->moeda($totalIbsCbs)),
            'liquido_com_ibs_cbs' => $this->traco($this->moeda($ibs['valor_total_nf'] ?? null)),
        ];
    }

    /**
     * Informações complementares, item 2.1.12 e notas 7 a 10.
     *
     * A ordem, os rótulos e o separador (pipe) são os que a NT-008 fixa. A
     * linha de totais aproximados de tributos é obrigatória e vem sempre por
     * último — ela não entra no truncamento das 1997 posições porque a própria
     * nota técnica a chama de "fixa".
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, string>
     */
    private function complementares(array $nota): array
    {
        $c = (array) ($nota['complementares'] ?? []);

        $partes = array_filter([
            $this->rotulado('Inf. Cont.', $c['informacao'] ?? null),
            $this->rotulado('NFS-e Subst.', $c['chave_substituida'] ?? null),
            $this->rotulado('Doc. Ref.', $c['documento_referencia'] ?? null),
            $this->rotulado('Cod. Obra', $c['codigo_obra'] ?? null),
            $this->rotulado('Insc. Imob.', $c['inscricao_imobiliaria'] ?? null),
            $this->rotulado('Cod. Evt.', $c['codigo_evento'] ?? null),
            $this->rotulado('Doc. Tec.', $c['documento_tecnico'] ?? null),
            $this->rotulado('Núm. Ped.', $c['numero_pedido'] ?? null),
            $this->rotulado('Item Ped.', $c['item_pedido'] ?? null),
            $this->rotulado('Inf. A. T. Mun.', $c['informacao_municipio'] ?? null),
        ]);

        return [
            'texto' => (string) $this->limitar(implode(' | ', $partes), 1997),
            'tributos' => sprintf(
                'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: %s ; Estaduais: %s ; Municipais: %s',
                $this->tributoAproximado($c['tributos_federais'] ?? null, $c['tributos_federais_percentual'] ?? null),
                $this->tributoAproximado($c['tributos_estaduais'] ?? null, $c['tributos_estaduais_percentual'] ?? null),
                $this->tributoAproximado($c['tributos_municipais'] ?? null, $c['tributos_municipais_percentual'] ?? null),
            ),
        ];
    }

    /**
     * Altura livre do bloco de informações complementares, em centímetros.
     *
     * O DANFSe tem página única e altura fixa (item 2.2) e o canhoto fica no pé
     * da folha. Cada linha dos demais blocos ocupa a altura que a tabela do item
     * 2.4.5 manda — as classes `h63`, `h65`... do Blade — e **o que sobra vai
     * para "Informações Complementares"**, que é o ajuste prescrito nos itens
     * 2.3.1 a 2.3.3 quando um bloco é suprimido a uma linha só, e o bloco que o
     * item 2.5.3 aponta como o que cede espaço quando falta.
     *
     * Sem esta conta o documento terminaria no meio da folha (quando há blocos
     * suprimidos) ou passaria para a segunda página (quando a descrição do
     * serviço é longa) — as duas coisas fora da norma.
     *
     * @param  array<string, mixed>  $tomador
     * @param  array<string, mixed>  $destinatario
     * @param  array<string, mixed>  $intermediario
     * @param  array<string, mixed>  $issqn
     * @param  array<string, mixed>  $federal
     * @param  array<string, mixed>  $servico
     */
    private function alturaInformacoesComplementares(
        array $tomador,
        array $destinatario,
        array $intermediario,
        array $issqn,
        array $federal,
        array $servico
    ): float {
        /** Bloco de pessoa: 3 linhas de 0,63cm, ou 1 linha só quando suprimido. */
        $pessoa = static fn (array $b): array => ($b['suprimido'] ?? false) ? [0.32] : [0.63, 0.63, 0.63];

        $linhas = array_merge(
            [1.21],                                 // cabeçalho
            [0.77, 0.67, 0.67, 0.67],               // dados da NFS-e
            [0.63, 0.63, 0.63, 0.63],               // prestador
            $pessoa($tomador),
            $pessoa($destinatario),
            $pessoa($intermediario),
            [
                0.63,
                0.38 + $this->linhasExtras($servico['descricao_codigo'] ?? ''),
                0.63 + $this->linhasExtras($servico['descricao'] ?? ''),
            ],
            ($issqn['suprimido'] ?? false)
                ? [0.32]
                : array_fill(0, 2 + (int) ($issqn['linha_beneficios'] ?? false) + (int) ($issqn['linha_deducoes'] ?? false), 0.65),
            array_fill(0, 1 + (int) ($federal['linha_pis_cofins'] ?? false), 0.65),
            [0.63, 0.63, 0.63, 0.63],               // IBS/CBS
            [0.67, 0.67],                           // valor total
            [0.39],                                 // título de informações complementares
            [0.67],                                 // canhoto
        );

        // Uma linha a mais, a do próprio conteúdo das informações complementares.
        $ocupado = array_sum($linhas) + (count($linhas) + 1) * self::ENTRELINHA;

        // Nunca menos que uma linha: o bloco existe sempre, porque a linha de
        // totais aproximados de tributos é obrigatória (nota 10).
        return round(max(self::ALTURA_CORPO - $ocupado, self::ALTURA_LINHA), 2);
    }

    /**
     * Quantos caracteres de texto livre cabem no quadro de informações
     * complementares, descontada a linha fixa de totais aproximados.
     */
    private function cabeEm(float $altura, string $linhaFixa): int
    {
        $disponivel = $altura - $this->linhasExtras($linhaFixa) - self::ALTURA_LINHA;

        $linhas = (int) floor($disponivel / self::ALTURA_LINHA);

        // O truncamento da NT-008 para este campo é de 1997 posições; o quadro
        // só aperta mais, nunca afrouxa.
        return max(0, min(1997, $linhas * self::CARACTERES_POR_LINHA));
    }

    // -----------------------------------------------------------------
    // Formatação
    // -----------------------------------------------------------------

    private function documento(?string $tipo, ?string $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        if ($tipo === 'NIF') {
            return $this->nulo($valor);
        }

        if (strlen((string) $digitos) === 11) {
            return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digitos));
        }

        if (strlen((string) $digitos) === 14) {
            return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digitos));
        }

        return $this->nulo($valor);
    }

    private function telefone(?string $valor): ?string
    {
        $digitos = (string) preg_replace('/\D/', '', (string) $valor);

        return match (strlen($digitos)) {
            10 => sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6)),
            11 => sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7)),
            default => $this->nulo($valor),
        };
    }

    private function cep(?string $valor): ?string
    {
        $digitos = (string) preg_replace('/\D/', '', (string) $valor);

        return strlen($digitos) === 8
            ? sprintf('%s.%s-%s', substr($digitos, 0, 2), substr($digitos, 2, 3), substr($digitos, 5))
            : $this->nulo($valor);
    }

    /**
     * "nnnnnnn / nn.nnn-nnn", ou o código postal quando o endereço é exterior.
     *
     * @param  array<string, mixed>  $pessoa
     */
    private function ibgeCep(array $pessoa): ?string
    {
        $codigo = $this->nulo($pessoa['cmun'] ?? null);
        $cep = $this->cep($pessoa['cep'] ?? null) ?? $this->nulo($pessoa['codigo_postal_exterior'] ?? null);

        if ($codigo === null && $cep === null) {
            return null;
        }

        return trim($this->traco($codigo).' / '.$this->traco($cep));
    }

    /**
     * "ccc, ccc, ccc, ccc" — logradouro, número, complemento e bairro.
     *
     * @param  array<string, mixed>  $pessoa
     */
    private function endereco(array $pessoa): ?string
    {
        $partes = array_filter([
            $this->nulo($pessoa['logradouro'] ?? null),
            $this->nulo($pessoa['numero'] ?? null),
            $this->nulo($pessoa['complemento'] ?? null),
            $this->nulo($pessoa['bairro'] ?? null),
        ]);

        return $partes === [] ? null : implode(', ', $partes);
    }

    private function municipioComUf(?string $codigo, ?string $nome, ?string $uf): ?string
    {
        $resolvido = MunicipioIbge::nomeComUf($codigo, $nome);

        if ($resolvido !== null) {
            return $resolvido;
        }

        $nome = $this->nulo($nome);
        $uf = $this->nulo($uf);

        if ($nome === null) {
            return $uf;
        }

        return $uf === null ? $nome : $nome.' / '.$uf;
    }

    /**
     * "Município / UF / País", itens 2.1.7 e 2.1.8.
     */
    private function localComPais(?string $nome, ?string $codigo, ?string $pais): ?string
    {
        $municipio = $this->municipioComUf($codigo, $nome, null);

        if ($municipio === null && $this->nulo($pais) === null) {
            return null;
        }

        return trim($this->traco($municipio).' / '.$this->traco($pais));
    }

    /**
     * cTribNac no formato "nn.nn.nn" (item, subitem e desdobro).
     */
    private function codigoTributacao(?string $valor): ?string
    {
        $digitos = (string) preg_replace('/\D/', '', (string) $valor);

        return strlen($digitos) === 6
            ? implode('.', str_split($digitos, 2))
            : $this->nulo($valor);
    }

    /**
     * cNBS no formato "n.nnnn.nn.nn".
     */
    private function codigoNbs(?string $valor): ?string
    {
        $digitos = (string) preg_replace('/\D/', '', (string) $valor);

        return strlen($digitos) === 9
            ? sprintf('%s.%s.%s.%s', $digitos[0], substr($digitos, 1, 4), substr($digitos, 5, 2), substr($digitos, 7, 2))
            : $this->nulo($valor);
    }

    private function moeda(?string $valor): ?string
    {
        return $this->nulo($valor) === null ? null : 'R$ '.number_format((float) $valor, 2, ',', '.');
    }

    private function percentual(?string $valor): ?string
    {
        return $this->nulo($valor) === null ? null : number_format((float) $valor, 2, ',', '.').'%';
    }

    /**
     * Totais aproximados podem vir em reais OU em percentual (nota 10).
     */
    private function tributoAproximado(?string $valor, ?string $percentual): string
    {
        return $this->traco($this->moeda($valor) ?? $this->percentual($percentual));
    }

    private function rotulado(string $rotulo, ?string $valor): ?string
    {
        $valor = $this->nulo($valor);

        return $valor === null ? null : $rotulo.': '.$valor;
    }

    private function data(?string $valor): ?string
    {
        return $this->formatar($valor, 'd/m/Y');
    }

    private function dataHora(?string $valor): ?string
    {
        return $this->formatar($valor, 'd/m/Y H:i:s');
    }

    private function ano(?string $valor): ?int
    {
        $formatado = $this->formatar($valor, 'Y');

        return $formatado === null ? null : (int) $formatado;
    }

    private function formatar(?string $valor, string $formato): ?string
    {
        if ($this->nulo($valor) === null) {
            return null;
        }

        try {
            return Carbon::parse((string) $valor)->format($formato);
        } catch (\Throwable) {
            return (string) $valor;
        }
    }

    /**
     * Trunca com reticências, como a NT-008 manda campo a campo.
     *
     * `$visivel` é o número de caracteres do texto; as reticências ocupam as
     * três posições seguintes do campo.
     */
    private function limitar(?string $valor, int $visivel): ?string
    {
        $valor = $this->nulo($valor);

        if ($valor === null || mb_strlen($valor) <= $visivel) {
            return $valor;
        }

        return mb_substr($valor, 0, $visivel).'...';
    }

    /**
     * Altura que um texto longo ocupa além da primeira linha, em centímetros.
     */
    private function linhasExtras(string $texto): float
    {
        $linhas = (int) ceil(max(1, mb_strlen($texto)) / self::CARACTERES_POR_LINHA);

        return max(0, $linhas - 1) * self::ALTURA_LINHA;
    }

    /**
     * Campo sem informação no XML vira traço (nota 12).
     */
    private function traco(?string $valor): string
    {
        return $this->nulo($valor) ?? '-';
    }

    private function nulo(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' || $valor === '-' ? null : $valor;
    }

    private function texto(?string $valor): string
    {
        return trim((string) $valor);
    }
}
