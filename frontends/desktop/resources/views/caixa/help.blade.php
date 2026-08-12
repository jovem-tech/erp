@extends('layouts.app')

@section('content')
    <section class="desktop-page-hero">
        <div class="desktop-page-hero-copy">
            <h2>Ajuda do caixa</h2>
            <p>Turno da gaveta: abertura, sangria, suprimento e conferência no fechamento.</p>
        </div>

        <a href="{{ route('caixa.index') }}" class="btn btn-outline-light">
            <i class="bi bi-arrow-left me-2"></i>
            Voltar ao caixa
        </a>
    </section>

    <section class="dashboard-help-grid">
        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Para que serve</strong>
                <p>Responder, no fim do dia, se o dinheiro da gaveta bate com o que o sistema registrou — e, quando não bate, mostrar de quanto foi a diferença e de quem era o turno.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Abertura</strong>
                <p>Conte o troco que está na gaveta e informe o valor. A primeira abertura de todas também cria a conta "Caixa da loja" no financeiro.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Esqueceu de abrir?</strong>
                <p>A primeira venda em dinheiro abre o turno sozinha, herdando o valor contado no fechamento anterior. O turno fica marcado como automático e o valor pode ser corrigido.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>O que entra na gaveta</strong>
                <p>Só dinheiro. Cartão e Pix vão direto para a conta da maquininha ou do banco, então não participam da conferência.</p>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Sangria</strong>
                <p>Dinheiro que sai da gaveta. Informando a conta de destino, o sistema registra a transferência de verdade entre o caixa e o banco ou cofre.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Suprimento</strong>
                <p>Dinheiro que entra na gaveta fora de venda, normalmente reforço de troco.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Motivo obrigatório</strong>
                <p>Sangria e suprimento exigem motivo. É o que torna a conferência auditável depois — sem ele, o dinheiro some do relatório sem explicação.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Troco</strong>
                <p>Não precisa ser lançado. Numa venda de R$ 50 paga com R$ 100, a gaveta ganha 100 e devolve 50: o sistema já considera só o líquido.</p>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Fechamento cego</strong>
                <p>O sistema não mostra quanto deveria ter antes de você contar. Conte a gaveta, informe o total e só então aparecem esperado, contado e diferença.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Diferença</strong>
                <p>Negativa é falta, positiva é sobra. Ela não impede o fechamento: fica registrada com o turno e ajusta o saldo da conta, para o dia seguinte começar do dinheiro real.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Venda cancelada</strong>
                <p>Sai da conferência automaticamente — o dinheiro voltou para o cliente e não está mais na gaveta.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Reabrir turno</strong>
                <p>Possível para corrigir um fechamento errado, com e-mail e senha de administrador. A conferência anterior é descartada.</p>
            </div>
        </article>
    </section>
@endsection
