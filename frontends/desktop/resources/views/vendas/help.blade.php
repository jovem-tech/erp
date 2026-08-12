@extends('layouts.app')

@section('content')
    <section class="desktop-page-hero">
        <div class="desktop-page-hero-copy">
            <h2>Ajuda de vendas</h2>
            <p>Venda de balcão: produtos, acessórios e serviços sem ordem de serviço.</p>
        </div>

        <a href="{{ route('vendas.index') }}" class="btn btn-outline-light">
            <i class="bi bi-arrow-left me-2"></i>
            Voltar à listagem
        </a>
    </section>

    <section class="dashboard-help-grid">
        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Quando usar</strong>
                <p>Para vender película, carregador, cabo, capa, celular ou monitor direto no balcão. Conserto continua sendo ordem de serviço.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>PDV</strong>
                <p>Busque por nome, código interno (PC00001) ou código de barras. Enter adiciona o primeiro resultado, F2 finaliza e Esc limpa o carrinho.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Cliente</strong>
                <p>Escolher cliente é opcional. Sem seleção, a venda sai como consumidor final, com nome e CPF/CNPJ opcionais para o cupom.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Itens avulsos</strong>
                <p>Produto sem cadastro pode ser lançado pelo botão "Item avulso": entra na venda e no financeiro, mas não movimenta estoque.</p>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Pagamento misto</strong>
                <p>Dinheiro, Pix, débito e crédito podem conviver na mesma venda. Em dinheiro, informe o valor recebido e o troco é calculado.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Cartão</strong>
                <p>A taxa da operadora é calculada a partir de Financeiro &gt; Cartões e lançada automaticamente como despesa.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Venda fiada</strong>
                <p>Receber menos que o total é permitido. A venda fecha, o produto sai do estoque e o título fica pendente no financeiro para cobrança.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Estoque insuficiente</strong>
                <p>O sistema avisa e deixa vender mesmo assim. O saldo fica negativo e a venda é marcada para acerto no inventário.</p>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Cancelamento</strong>
                <p>Venda concluída não é editada: corrija cancelando e refazendo. O cancelamento devolve o estoque e estorna o título financeiro.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Venda de outro dia</strong>
                <p>Cancelar uma venda que não é de hoje exige confirmação com e-mail e senha de administrador.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Comprovante</strong>
                <p>Cupom em 80 mm para impressora térmica ou A4 para folha inteira. O modelo é editável em Modelos PDF.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Nota fiscal</strong>
                <p>O comprovante é um documento não fiscal. A emissão de NFC-e ainda não faz parte do sistema.</p>
            </div>
        </article>
    </section>
@endsection
