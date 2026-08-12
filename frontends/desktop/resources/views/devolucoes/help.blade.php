@extends('layouts.app')

@section('content')
    <section class="desktop-page-hero">
        <div class="desktop-page-hero-copy">
            <h2>Ajuda de devoluções</h2>
            <p>Devolver parte de uma venda, com o dinheiro voltando pela mesma forma em que entrou.</p>
        </div>

        <a href="{{ route('devolucoes.index') }}" class="btn btn-outline-light">
            <i class="bi bi-arrow-left me-2"></i>
            Voltar à listagem
        </a>
    </section>

    <section class="dashboard-help-grid">
        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Onde começa</strong>
                <p>Pela venda: abra a venda de origem e use "Devolver". A listagem de devoluções serve para consultar o que já voltou.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Devolução parcial</strong>
                <p>Informe a quantidade de cada item. Uma venda pode ter várias devoluções ao longo do tempo, e o sistema controla o saldo devolvível de cada linha.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Devolver x cancelar</strong>
                <p>Cancelar desfaz a venda inteira e some com o número. Devolver preserva a venda e registra o que voltou — é o caminho certo quando o cliente traz só parte da compra.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Quanto volta</strong>
                <p>O reembolso considera o desconto que a venda teve. Num item de R$ 50 numa venda com 10% de desconto, o cliente recebe R$ 45 — o que ele realmente pagou.</p>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Como o dinheiro volta</strong>
                <p>Pela mesma forma em que entrou, rateado entre os pagamentos da venda. Pagou 40 em dinheiro e 60 no cartão? Devolvendo metade, voltam 20 em dinheiro e 30 no cartão.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Cartão</strong>
                <p>O sistema registra o estorno, mas processar na maquininha continua sendo manual. Se esquecer, o financeiro mostra a saída e o extrato da operadora não.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Taxa do cartão</strong>
                <p>A operadora não devolve a taxa. Ela aparece na devolução como custo, e a despesa lançada na venda continua valendo — porque foi cobrada de verdade.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Caixa</strong>
                <p>Devolução em dinheiro sai da gaveta do turno aberto agora, não do turno em que a venda aconteceu, e entra na conferência do fechamento de hoje.</p>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Venda fiada</strong>
                <p>Se o cliente ainda devia, só volta em dinheiro o que ele já tinha pago. O restante do crédito abate a dívida em aberto.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Estoque</strong>
                <p>Produto volta à prateleira. Serviço já executado e item avulso não geram movimentação — a tela mostra isso item a item antes de você confirmar.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Venda antiga</strong>
                <p>Devolver depois de 7 dias da venda exige e-mail e senha de administrador, e fica registrado quem autorizou.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Troca</strong>
                <p>Registre a venda nova normalmente no PDV e informe o número dela na devolução: as duas ficam ligadas, e a diferença é cobrada ou devolvida na venda nova.</p>
            </div>
        </article>
    </section>
@endsection
