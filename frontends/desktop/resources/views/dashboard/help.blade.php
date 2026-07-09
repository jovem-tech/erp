@extends('layouts.app')

@section('content')
    <section class="desktop-page-hero">
        <div class="desktop-page-hero-copy">
            <h2>Ajuda do dashboard</h2>
            <p>Guia rápido dos blocos visíveis nesta tela do desktop.</p>
        </div>
    </section>

    <section class="dashboard-help-grid">
        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>OS abertas</strong>
                <p>Mostra as ordens ainda em posse da assistência, usando o mesmo critério da listagem: exclui apenas Equipamento entregue, Devolvido sem reparo e Equipamento descartado.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Card principal</strong>
                <p>Exibe faturamento do mês para perfis financeiros ou comissões / destaque operacional conforme o perfil de acesso.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Equipamento entregue</strong>
                <p>Resumo das ordens finalizadas com baixa técnica e entrega registrada no período visível da API central.</p>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Gráfico mensal</strong>
                <p>Comparativo entre OS abertas e entregues reparadas por mês. O filtro de ano atualiza apenas esse bloco.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>OS por status</strong>
                <p>Distribuição das OS abertas para leitura rápida do estágio atual do trabalho.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Tipos de equipamento</strong>
                <p>Filtra os tipos operacionais por mês e ano sem recarregar a página inteira.</p>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Últimas OS</strong>
                <p>A tabela traz acesso ao modal de pré-visualização e ao link de página cheia para cada ordem.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Alerta de estoque baixo</strong>
                <p>Este bloco aparece apenas quando a API central retorna itens críticos de estoque com ação permitida.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Atualização em tempo real</strong>
                <p>Os filtros de ano e período recarregam apenas os dados necessários, mantendo a navegação fluida no desktop.</p>
            </div>
        </article>
    </section>
@endsection
