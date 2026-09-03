@extends('layouts.app')

@section('content')
    <section class="desktop-page-hero">
        <div class="desktop-page-hero-copy">
            <h2>Ajuda do Anexo X</h2>
            <p>Relatório Mensal das Receitas Brutas — Resolução CGSN nº 140/2018, art. 106.</p>
        </div>

        <a href="{{ route('fiscal.anexo-x') }}" class="btn btn-outline-light">
            <i class="bi bi-arrow-left me-2"></i>
            Voltar ao Anexo X
        </a>
    </section>

    <section class="dashboard-help-grid">
        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Para que serve</strong>
                <p>
                    O MEI tem que preencher este relatório todo mês, até o dia 20 do mês seguinte, e guardá-lo
                    pelo prazo decadencial junto com as notas fiscais de entrada e as que tiver emitido. O sistema
                    monta o formulário a partir do que já está lançado — OS entregues, vendas de balcão, devoluções.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>O PDF é o formulário oficial, e só ele</strong>
                <p>
                    O Anexo X é um modelo padronizado pela Receita Federal. O PDF sai exatamente como a norma o
                    desenha — nada é acrescentado. O acumulado do ano, a lista de receita sem documento fiscal e a
                    relação de notas emitidas são informações de conferência: existem nesta tela, e a relação de
                    documentos tem download próprio, para ser <em>anexada</em> ao relatório, nunca embutida nele.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>Comércio × serviços</strong>
                <p>
                    Peça aplicada numa OS entra como revenda de mercadoria; mão de obra entra como prestação de
                    serviço. Quando a OS tem desconto, ele é rateado proporcionalmente entre as duas partes — foi
                    assim que o desconto foi concedido, sobre o total. Item avulso de balcão conta como mercadoria.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>“Com dispensa” × “com documento fiscal emitido”</strong>
                <p>
                    A separação vem dos documentos fiscais lançados no sistema: NFS-e cobre a parte de serviço,
                    NF-e e NFC-e cobrem a parte de mercadoria. Nota cancelada ou em rascunho não cobre nada, e o
                    valor volta para a coluna “com dispensa”. Emitir uma nota nunca muda o TOTAL do mês — só muda
                    de qual coluna o valor sai.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>Venda para pessoa jurídica</strong>
                <p>
                    O MEI é dispensado de emitir documento fiscal para pessoa física, mas não para pessoa
                    jurídica. Por isso a tela destaca em vermelho as operações sem documento cujo tomador é PJ:
                    são as que merecem uma segunda olhada antes de você assinar o relatório.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>A tela é do ano</strong>
                <p>
                    A tabela traz os doze meses do ano-calendário, um por linha. O menu de ações de cada linha
                    abre o mês: conferir as dez linhas do formulário, ver o PDF no padrão da Receita, editar,
                    imprimir ou listar todas as operações. O gráfico acima compara os dois regimes mês a mês.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>Editar o relatório: o que o ajuste é e o que não é</strong>
                <p>
                    O ajuste é um lançamento <em>somado</em> ao que o sistema apurou — o valor calculado
                    continua visível ao lado dele. Serve para declarar receita bruta que existiu mas não passou
                    pelo ERP, como uma venda cobrada em dinheiro e não lançada. Todo ajuste exige motivo e
                    registra quem lançou e quando; corrigir é cancelar e lançar de novo, e o cancelado continua
                    na lista, riscado. Com a competência encerrada, nada pode ser ajustado — reabra antes.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>Por que só seis das dez linhas aceitam ajuste</strong>
                <p>
                    III, VI, IX e X são <em>somas</em> das demais. Ajustar uma delas exigiria repartir o valor
                    de volta entre as linhas de origem — e essa repartição é decisão fiscal: a receita extra teve
                    documento emitido ou não? Só quem lança sabe. Por isso o ajuste vai direto na linha de
                    origem (I, II, IV, V, VII ou VIII) e os totais se recompõem sozinhos.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>O ajuste faz o Anexo X se afastar do DRE — de propósito</strong>
                <p>
                    O valor <em>calculado</em> continua batendo exatamente com a receita líquida do DRE do mesmo
                    mês. O <em>declarado</em> é esse valor mais os ajustes. O DRE é relatório gerencial do que o
                    sistema conhece e não enxerga receita que nunca passou por ele; o Anexo X tem que declarar
                    toda a receita bruta. O rodapé do PDF informa quanto do total veio de ajuste manual.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>Competência × caixa</strong>
                <p>
                    Por competência, a receita da OS entra no mês da entrega e a da venda no mês da venda — é o
                    mesmo critério do DRE por competência. Por caixa, entra no mês em que o dinheiro foi recebido.
                    O regime usado sai impresso no rodapé do PDF, para dar para conferir depois.
                </p>
                <p>
                    <strong>Só a competência conta para o limite do MEI.</strong> O teto de R$ 81.000 é sobre a
                    receita bruta <em>auferida</em> no ano-calendário, e "auferida" é o termo do regime de
                    competência. O regime de caixa é uma opção do ME/EPP exercida no PGDAS-D, e o MEI usa a
                    DASN-Simei, que não tem esse mecanismo — por isso a leitura de caixa fica aqui como
                    informação gerencial, e o card do acumulado soma sempre competência.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>Encerrar a competência</strong>
                <p>
                    Encerrar congela os valores. Depois disso, corrigir uma OS daquele mês não altera mais o
                    relatório — que é o ponto, já que ele foi assinado e arquivado. O botão <em>Reconferir</em>
                    recalcula com os dados de hoje e mostra o que teria mudado. Reabrir exige senha de
                    administrador e um motivo; a versão anterior fica guardada como evidência.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>Por que a relação de documentos pode não bater com as colunas II e VIII</strong>
                <p>
                    As colunas do formulário classificam a OPERAÇÃO; a relação anexa lista por data de EMISSÃO.
                    Uma NFS-e emitida em 3 de outubro, de uma OS entregue em 28 de setembro, conta na coluna VIII
                    de setembro e aparece na relação de outubro. A divergência é legítima, não é erro.
                </p>
            </div>
            <div class="dashboard-help-item">
                <strong>Limite do MEI</strong>
                <p>
                    R$ 81.000 por ano-calendário, proporcional aos meses de atividade no ano de abertura — informe
                    a data de abertura em Configurações › Sistema para o cálculo sair proporcional. Até 20% de
                    excesso, o desenquadramento vale a partir de 1º de janeiro do ano seguinte; acima disso, ele
                    retroage ao início do ano.
                </p>
            </div>
        </article>
    </section>
@endsection
