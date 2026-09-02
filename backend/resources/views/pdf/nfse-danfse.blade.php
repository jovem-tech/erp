{{--
    DANFSe v2.0 — Documento Auxiliar da NFS-e.

    Reproduz o modelo do Anexo I da Nota Tecnica no 008 (SE/CGNFS-e,
    05/05/2026). A disposicao dos campos e' obrigatoria (item 2.2.4), por isso
    a ordem dos blocos e das colunas aqui NAO e' escolha de estilo: as quatro
    colunas de 5,09cm e as linhas de cada bloco saem da tabela de coordenadas
    do item 2.4.5.

    Este arquivo so' posiciona. Traducao de codigo para descricao, formatacao,
    truncamento, supressao de bloco e altura estao em
    App\Services\Fiscal\DanfseLayout.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>DANFSe {{ $danfse['identificacao']['numero'] }}</title>
    <style>
        /* Item 2.2.2: a margem entre o corpo impresso e o fim do formulario
           tem de ficar entre 0,15cm e 0,20cm — usamos o maximo. A tabela de
           coordenadas do item 2.4.5 sugere x=0,30, mas ela e' declaradamente
           "sugestao de tamanho e posicao"; a margem, nao. Onde os dois se
           contradizem, vale a regra obrigatoria. */
        @page { size: A4 portrait; margin: 0.20cm; }

        /* Item 2.4: Arial nos rotulos, Microsoft Sans Serif no conteudo.
           Os arquivos vem de resources/fonts/danfse/ — ver o LEIA-ME de la'
           sobre quais fontes sao realmente embutidas e como usar as da
           Microsoft quando ha licenca. */
@if (($fontes['titulo'] ?? null) !== null)
        @font-face { font-family: 'DANFSe Titulo'; font-weight: normal; font-style: normal; src: url('{{ $fontes['titulo'] }}') format('truetype'); }
@endif
@if (($fontes['titulo_negrito'] ?? null) !== null)
        @font-face { font-family: 'DANFSe Titulo'; font-weight: bold; font-style: normal; src: url('{{ $fontes['titulo_negrito'] }}') format('truetype'); }
@endif
@if (($fontes['conteudo'] ?? null) !== null)
        @font-face { font-family: 'DANFSe Conteudo'; font-weight: normal; font-style: normal; src: url('{{ $fontes['conteudo'] }}') format('truetype'); }
@endif
@if (($fontes['conteudo_negrito'] ?? null) !== null)
        @font-face { font-family: 'DANFSe Conteudo'; font-weight: bold; font-style: normal; src: url('{{ $fontes['conteudo_negrito'] }}') format('truetype'); }
@endif

        /* O conteudo e' o padrao do documento; os rotulos trocam para a familia
           dos titulos nas classes `.rot`, `.rot-id` e `.titulo-bloco`. */
        body { font-family: 'DANFSe Conteudo', Arial, Helvetica, sans-serif; color: #000; margin: 0; font-size: 7pt; }
        .rot, .rot-id, .titulo-bloco, .cabecalho .marca, .cabecalho .sem-validade, .linha-unica {
            font-family: 'DANFSe Titulo', Arial, Helvetica, sans-serif;
        }

        /* Item 2.2.3: borda da pagina de 1 ponto; divisorias de 0,5 ponto. */
        .folha { border: 1pt solid #000; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td { vertical-align: top; padding: 1pt 3pt; line-height: 0.30cm; }
        .bloco { border-top: 0.5pt solid #000; }

        /* Item 2.4.1: titulo de bloco em 7pt, negrito, caixa alta.
           Item 2.2.3: fundo cinza claro (5%). */
        .titulo-bloco { font-size: 7pt; font-weight: bold; line-height: 0.26cm; background: #f2f2f2; }

        /* Entrelinha em centimetro, e nao em multiplo do tamanho da fonte: com
           multiplo o dompdf resolve a altura pela metrica da fonte embutida, uma
           linha de 7pt vira 0,38cm em vez de 0,30cm, e o calculo de espaco do
           DanfseLayout — que decide se o documento cabe numa pagina — passa a
           mentir. Estes valores e as constantes de la' sao um par. */
        /* Item 2.4.2: rotulo de campo em 6pt, negrito. */
        .rot { display: block; font-size: 6pt; font-weight: bold; line-height: 0.22cm; }
        /* Item 2.4.4: conteudo em 7pt, formato normal. */
        .val { display: block; font-size: 7pt; line-height: 0.30cm; word-wrap: break-word; }
        /* Item 2.4.2: os rotulos do bloco de identificacao vao a 7pt, caixa alta.
           A caixa alta e' escrita no texto, nao via `text-transform`: o modelo
           do Anexo I grafa "NFS-e" com o "e" minusculo ate' nesses rotulos. */
        .rot-id { display: block; font-size: 7pt; font-weight: bold; line-height: 0.26cm; }

        .sombreado { background: #f2f2f2; }
        .linha-unica { font-size: 7pt; font-weight: bold; text-align: center; line-height: 0.26cm; }

        .cabecalho td { padding: 2pt 3pt; background: #f2f2f2; }
        .cabecalho .marca { font-size: 9pt; font-weight: bold; text-align: center; line-height: 0.38cm; }
        /* Item 2.4.3, observacao: vermelho solido, 9pt, negrito. */
        .cabecalho .sem-validade { font-size: 9pt; font-weight: bold; text-align: center; color: #ff0000; }
        .cabecalho .municipio { font-size: 8pt; line-height: 0.32cm; }
        .cabecalho .ambiente { font-size: 6pt; line-height: 0.22cm; }

        .qr { text-align: center; padding: 2pt 0 0 0; }
        /* Item 2.4.3: minimo de 1,52cm x 1,52cm. A imagem inclui a zona de
           silencio de 4 modulos exigida pela ISO, entao o simbolo em si mede
           1,85 x 41/49 = 1,55cm. */
        .qr img { width: 1.85cm; height: 1.85cm; }
        .qr-texto { font-size: 6pt; line-height: 0.22cm; text-align: left; padding: 1pt 3pt; }

        .chave { font-size: 7pt; letter-spacing: 0.2pt; }
        .descricao { display: block; font-size: 7pt; line-height: 1.2; word-wrap: break-word; }

        .canhoto td { border-top: 0.5pt solid #000; height: 0.67cm; }

        /* Alturas de linha do item 2.4.5. Nao sao decoracao: e' delas que sai o
           espaco que sobra para "Informacoes Complementares" quando um bloco e'
           suprimido (itens 2.3.1 a 2.3.3), e e' o que mantem o canhoto no pe'
           da folha, em pagina unica (item 2.2). Se mudarem aqui, mudam tambem
           em DanfseLayout::alturas(). */
        tr.h116 td { height: 1.16cm; }
        tr.h77 td { height: 0.77cm; }
        tr.h67 td { height: 0.67cm; }
        tr.h65 td { height: 0.65cm; }
        tr.h63 td { height: 0.63cm; }
        tr.h39 td { height: 0.39cm; }
        tr.h38 td { height: 0.38cm; }
        tr.h23 td { height: 0.23cm; }

        /* Itens 2.5.1 e 2.5.2: marca d'agua na diagonal, minimo 50pt, cinza K35. */
        .marca-dagua {
            position: absolute; top: 11cm; left: 0; width: 20.4cm;
            text-align: center; font-size: 50pt; color: #a6a6a6;
            transform: rotate(-45deg);
        }
    </style>
</head>
<body>
@php
    /** Campo simples: rotulo por cima, conteudo por baixo. */
    $campo = static function (string $rotulo, string $valor, string $classe = ''): string {
        return '<span class="rot'.($classe ? ' '.$classe : '').'">'.e($rotulo).'</span>'
            .'<span class="val">'.e($valor).'</span>';
    };
@endphp

<div class="folha">

    {{-- ============ CABECALHO (item 2.4.3) ============ --}}
    <table class="cabecalho">
        <tr class="h116">
            <td style="width:20%">
                @if ($logo !== '')
                    <img src="{{ $logo }}" alt="NFS-e" style="width:4cm">
                @endif
            </td>
            <td style="width:52%">
                <div class="marca">DANFSe v2.0<br>Documento Auxiliar da NFS-e</div>
                @if ($danfse['cabecalho']['sem_validade_juridica'])
                    <div class="sem-validade">NFS-e SEM VALIDADE JURÍDICA</div>
                @endif
            </td>
            <td style="width:28%">
                @if ($danfse['cabecalho']['municipio'] !== null)
                    <div class="municipio">Município: {{ $danfse['cabecalho']['municipio'] }}</div>
                @endif
                <div class="ambiente">Ambiente Gerador: {{ $danfse['cabecalho']['ambiente_gerador'] }}</div>
                <div class="ambiente">Tipo de Ambiente: {{ $danfse['cabecalho']['tipo_ambiente'] }}</div>
            </td>
        </tr>
    </table>

    {{-- ============ DADOS DA NFS-e (item 2.1.2) ============ --}}
    <table class="bloco">
        <tr class="h77">
            <td colspan="3" style="width:75%">
                <span class="rot-id">CHAVE DE ACESSO DA NFS-e</span>
                <span class="val chave">{{ $danfse['identificacao']['chave'] }}</span>
            </td>
            <td rowspan="4" style="width:25%" class="qr">
                @if ($danfse['qrcode']['imagem'] !== '')
                    <img src="{{ $danfse['qrcode']['imagem'] }}" alt="QR Code da NFS-e">
                @endif
                <div class="qr-texto">
                    A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR
                    ou pela consulta da chave de acesso no portal nacional da NFS-e
                </div>
            </td>
        </tr>
        <tr class="h67">
            <td style="width:25%">{!! $campo('NÚMERO DA NFS-e', $danfse['identificacao']['numero'], 'rot-id') !!}</td>
            <td style="width:25%">{!! $campo('COMPETÊNCIA DA NFS-e', $danfse['identificacao']['competencia'], 'rot-id') !!}</td>
            <td style="width:25%">{!! $campo('DATA E HORA DA EMISSÃO DA NFS-e', $danfse['identificacao']['emissao_nfse'], 'rot-id') !!}</td>
        </tr>
        <tr class="h67">
            <td>{!! $campo('NÚMERO DA DPS', $danfse['identificacao']['numero_dps'], 'rot-id') !!}</td>
            <td>{!! $campo('SÉRIE DA DPS', $danfse['identificacao']['serie_dps'], 'rot-id') !!}</td>
            <td>{!! $campo('DATA E HORA DA EMISSÃO DA DPS', $danfse['identificacao']['emissao_dps'], 'rot-id') !!}</td>
        </tr>
        <tr class="h67">
            {{-- Item 2.2.3: "Emitente da NFS-e" e um dos dois campos sombreados. --}}
            <td class="sombreado">{!! $campo('EMITENTE DA NFS-e', $danfse['identificacao']['emitente'], 'rot-id') !!}</td>
            <td>{!! $campo('SITUAÇÃO DA NFS-e', $danfse['identificacao']['situacao'], 'rot-id') !!}</td>
            <td>{!! $campo('FINALIDADE', $danfse['identificacao']['finalidade'], 'rot-id') !!}</td>
        </tr>
    </table>

    {{-- ============ PRESTADOR / FORNECEDOR (item 2.1.3) ============ --}}
    <table class="bloco">
        <tr class="h63">
            <td class="titulo-bloco" style="width:25%">PRESTADOR / FORNECEDOR</td>
            <td style="width:25%">{!! $campo('CNPJ / CPF / NIF', $danfse['prestador']['documento']) !!}</td>
            <td style="width:25%">{!! $campo('Indicador Municipal (Inscrição)', $danfse['prestador']['im']) !!}</td>
            <td style="width:25%">{!! $campo('Telefone', $danfse['prestador']['fone']) !!}</td>
        </tr>
        <tr class="h63">
            <td colspan="2">{!! $campo('Nome / Nome Empresarial', $danfse['prestador']['nome']) !!}</td>
            <td>{!! $campo('Município / Sigla UF', $danfse['prestador']['municipio']) !!}</td>
            <td>{!! $campo('Código IBGE / CEP', $danfse['prestador']['ibge_cep']) !!}</td>
        </tr>
        <tr class="h63">
            <td colspan="2">{!! $campo('Endereço', $danfse['prestador']['endereco']) !!}</td>
            <td colspan="2">{!! $campo('E-mail', $danfse['prestador']['email']) !!}</td>
        </tr>
        <tr class="h63">
            <td colspan="2">{!! $campo('Simples Nacional na Data de Competência', $danfse['prestador']['simples_nacional']) !!}</td>
            <td colspan="2">{!! $campo('Regime de Apuração Tributária pelo SN', $danfse['prestador']['regime_apuracao']) !!}</td>
        </tr>
    </table>

    {{-- ============ TOMADOR / ADQUIRENTE (item 2.1.4, nota 2) ============ --}}
    @include('pdf.partials.danfse-pessoa', ['titulo' => 'TOMADOR / ADQUIRENTE', 'pessoa' => $danfse['tomador'], 'campo' => $campo, 'comIm' => true])

    {{-- ============ DESTINATARIO DA OPERACAO (itens 2.1.5 e 2.3.2) ============ --}}
    @include('pdf.partials.danfse-pessoa', ['titulo' => 'DESTINATÁRIO DA OPERAÇÃO', 'pessoa' => $danfse['destinatario'], 'campo' => $campo, 'comIm' => false])

    {{-- ============ INTERMEDIARIO DA OPERACAO (item 2.1.6) ============ --}}
    @include('pdf.partials.danfse-pessoa', ['titulo' => 'INTERMEDIÁRIO DA OPERAÇÃO', 'pessoa' => $danfse['intermediario'], 'campo' => $campo, 'comIm' => true])

    {{-- ============ SERVICO PRESTADO (item 2.1.7) ============ --}}
    <table class="bloco">
        <tr class="h63">
            <td class="titulo-bloco" style="width:25%">SERVIÇO PRESTADO</td>
            <td style="width:25%">{!! $campo('Código de Tributação Nacional / Municipal', $danfse['servico']['codigo_tributacao']) !!}</td>
            <td style="width:25%">{!! $campo('Código da NBS', $danfse['servico']['nbs']) !!}</td>
            <td style="width:25%">{!! $campo('Local da Prestação / Sigla UF / País', $danfse['servico']['local']) !!}</td>
        </tr>
        <tr class="h38">
            {{-- Item 2.4.5: "Nao ha titulo (label) deste campo no DANFSe". --}}
            <td colspan="4"><span class="descricao">{{ $danfse['servico']['descricao_codigo'] }}</span></td>
        </tr>
        <tr class="h63">
            <td colspan="4">
                <span class="rot">Descrição do Serviço</span>
                <span class="descricao">{{ $danfse['servico']['descricao'] }}</span>
            </td>
        </tr>
    </table>

    {{-- ============ TRIBUTACAO MUNICIPAL (ISSQN) (item 2.1.8, notas 4 e 5) ============ --}}
    @if ($danfse['issqn']['suprimido'])
        <table class="bloco">
            <tr class="h23"><td colspan="4" class="linha-unica">{{ $danfse['issqn']['aviso'] }}</td></tr>
        </table>
    @else
        <table class="bloco">
            <tr class="h65">
                <td class="titulo-bloco" style="width:25%">TRIBUTAÇÃO MUNICIPAL (ISSQN)</td>
                <td style="width:25%">{!! $campo('Tipo de Tributação do ISSQN', $danfse['issqn']['tributacao']) !!}</td>
                <td colspan="2" style="width:50%">{!! $campo('Município / Sigla UF / País de Incidência do ISSQN', $danfse['issqn']['municipio_incidencia']) !!}</td>
            </tr>
            @if ($danfse['issqn']['linha_beneficios'])
                <tr class="h65">
                    <td>{!! $campo('Regime Especial de Tributação do ISSQN', $danfse['issqn']['regime_especial']) !!}</td>
                    <td>{!! $campo('Tipo de Imunidade do ISSQN', $danfse['issqn']['imunidade']) !!}</td>
                    <td>{!! $campo('Suspensão da Exigibilidade do ISSQN', $danfse['issqn']['suspensao']) !!}</td>
                    <td>{!! $campo('Número Processo Suspensão', $danfse['issqn']['processo_suspensao']) !!}</td>
                </tr>
            @endif
            @if ($danfse['issqn']['linha_deducoes'])
                <tr class="h65">
                    <td>{!! $campo('Benefício Municipal', $danfse['issqn']['beneficio_municipal']) !!}</td>
                    <td>{!! $campo('Cálculo do BM', $danfse['issqn']['calculo_bm']) !!}</td>
                    <td>{!! $campo('Total Deduções/Reduções', $danfse['issqn']['total_deducoes']) !!}</td>
                    <td>{!! $campo('Desconto Incondicionado', $danfse['issqn']['desconto_incondicionado']) !!}</td>
                </tr>
            @endif
            <tr class="h65">
                <td>{!! $campo('BC ISSQN', $danfse['issqn']['base_calculo']) !!}</td>
                <td>{!! $campo('Alíquota Aplicada', $danfse['issqn']['aliquota']) !!}</td>
                <td>{!! $campo('Retenção do ISSQN', $danfse['issqn']['retencao']) !!}</td>
                <td>{!! $campo('ISSQN Apurado', $danfse['issqn']['apurado']) !!}</td>
            </tr>
        </table>
    @endif

    {{-- ============ TRIBUTACAO FEDERAL (EXCETO CBS) (item 2.1.9, nota 6) ============ --}}
    <table class="bloco">
        <tr class="h65">
            <td class="titulo-bloco" style="width:25%">TRIBUTAÇÃO FEDERAL (EXCETO CBS)</td>
            <td style="width:25%">{!! $campo('IRRF', $danfse['federal']['irrf']) !!}</td>
            <td style="width:25%">{!! $campo('Contribuição Previdenciária - Retida', $danfse['federal']['previdenciaria']) !!}</td>
            <td style="width:25%">{!! $campo('Contribuições Sociais - Retidas', $danfse['federal']['sociais']) !!}</td>
        </tr>
        @if ($danfse['federal']['linha_pis_cofins'])
            <tr class="h65">
                <td>{!! $campo('PIS - Débito Apuração Própria', $danfse['federal']['pis']) !!}</td>
                <td>{!! $campo('COFINS - Débito Apuração Própria', $danfse['federal']['cofins']) !!}</td>
                <td colspan="2">{!! $campo('Descrição Contrib. Sociais - Retidas', $danfse['federal']['retencao_pis_cofins']) !!}</td>
            </tr>
        @endif
    </table>

    {{-- ============ TRIBUTACAO IBS / CBS (item 2.1.10) ============ --}}
    <table class="bloco">
        <tr class="h63">
            <td class="titulo-bloco" style="width:25%">TRIBUTAÇÃO IBS / CBS</td>
            <td style="width:25%">{!! $campo('CST / cClassTrib', $danfse['ibscbs']['cst']) !!}</td>
            <td colspan="2" style="width:50%">{!! $campo('Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF', $danfse['ibscbs']['indicador_operacao']) !!}</td>
        </tr>
        <tr class="h63">
            <td>{!! $campo('Exclusões e Reduções da Base de Cálculo', $danfse['ibscbs']['exclusoes']) !!}</td>
            <td>{!! $campo('Base de Cálculo Após Exclusões e Reduções', $danfse['ibscbs']['base_calculo']) !!}</td>
            <td>{!! $campo('Red. Alíquota IBS / Red. Alíquota CBS', $danfse['ibscbs']['reducao_aliquota']) !!}</td>
            <td>{!! $campo('Alíquota - IBS UF / IBS Mun', $danfse['ibscbs']['aliquota_ibs']) !!}</td>
        </tr>
        <tr class="h63">
            <td>{!! $campo('Alíq. Efetiva Municipal - IBS', $danfse['ibscbs']['aliquota_efetiva_mun']) !!}</td>
            <td>{!! $campo('Valor Apurado Municipal - IBS', $danfse['ibscbs']['valor_ibs_mun']) !!}</td>
            <td>{!! $campo('Alíq. Efetiva Estadual - IBS', $danfse['ibscbs']['aliquota_efetiva_uf']) !!}</td>
            <td>{!! $campo('Valor Apurado Estadual - IBS', $danfse['ibscbs']['valor_ibs_uf']) !!}</td>
        </tr>
        <tr class="h63">
            <td>{!! $campo('Valor Total Apurado - IBS', $danfse['ibscbs']['valor_ibs_total']) !!}</td>
            <td>{!! $campo('Alíquota - CBS', $danfse['ibscbs']['aliquota_cbs']) !!}</td>
            <td>{!! $campo('Alíquota Efetiva - CBS', $danfse['ibscbs']['aliquota_efetiva_cbs']) !!}</td>
            <td>{!! $campo('Valor Total Apurado - CBS', $danfse['ibscbs']['valor_cbs']) !!}</td>
        </tr>
    </table>

    {{-- ============ VALOR TOTAL DA NFS-e (item 2.1.11) ============ --}}
    <table class="bloco">
        <tr class="h67">
            <td class="titulo-bloco" style="width:25%">VALOR TOTAL DA NFS-e</td>
            <td style="width:25%">{!! $campo('VALOR DA OPERAÇÃO / SERVIÇO', $danfse['valores']['servico']) !!}</td>
            <td style="width:25%">{!! $campo('Desconto Incondicionado', $danfse['valores']['desconto_incondicionado']) !!}</td>
            <td style="width:25%">{!! $campo('Desconto Condicionado', $danfse['valores']['desconto_condicionado']) !!}</td>
        </tr>
        <tr class="h67">
            <td>{!! $campo('Total das Retenções (ISSQN / Federais)', $danfse['valores']['retencoes']) !!}</td>
            <td>{!! $campo('VALOR LÍQUIDO DA NFS-e', $danfse['valores']['liquido']) !!}</td>
            <td>{!! $campo('Total do IBS/CBS', $danfse['valores']['total_ibs_cbs']) !!}</td>
            {{-- Item 2.2.3: o segundo campo sombreado do documento. --}}
            <td class="sombreado">{!! $campo('VALOR LÍQUIDO DA NFS-e + IBS/CBS', $danfse['valores']['liquido_com_ibs_cbs']) !!}</td>
        </tr>
    </table>

    {{-- ============ INFORMACOES COMPLEMENTARES (item 2.1.12) ============ --}}
    <table class="bloco">
        <tr class="h39"><td colspan="4" class="titulo-bloco">INFORMAÇÕES COMPLEMENTARES</td></tr>
        <tr>
            <td colspan="4" style="height:{{ $danfse['alturas']['informacoes_complementares'] }}cm">
                @if ($danfse['complementares']['texto'] !== '')
                    <span class="descricao">{{ $danfse['complementares']['texto'] }}</span>
                @endif
                {{-- Nota 10: linha obrigatoria, sempre presente. --}}
                <span class="descricao">{{ $danfse['complementares']['tributos'] }}</span>
            </td>
        </tr>
    </table>

    {{-- ============ CANHOTO (item 2.1.13, nota 11) ============ --}}
    <table class="canhoto">
        <tr class="h67">
            <td style="width:25%"><span class="rot">DATA CIENTIFICAÇÃO:</span></td>
            <td style="width:25%"><span class="rot">IDENTIFICAÇÃO E ASSINATURA</span></td>
            <td colspan="2" style="width:50%">
                {!! $campo('Nº NFS-e / CHAVE NFS-e', $danfse['canhoto']['numero_chave']) !!}
            </td>
        </tr>
    </table>

</div>

@if ($danfse['marca_dagua'] !== null)
    <div class="marca-dagua">{{ $danfse['marca_dagua'] }}</div>
@endif

</body>
</html>
