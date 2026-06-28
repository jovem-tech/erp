@extends('layouts.app')

@section('content')
    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Ajuda de orçamentos</h2>
                <p class="surface-subtitle">Guia rápido para o fluxo comercial do desktop.</p>
            </div>
        </div>

        <div class="desktop-grid desktop-grid-two">
            <article class="summary-card">
                <span class="summary-card-eyebrow">Fluxo</span>
                <div class="summary-card-value">Lista → criação → detalhe → aprovação</div>
                <div class="summary-card-meta">O desktop consome a API central em todas as etapas.</div>
            </article>

            <article class="summary-card">
                <span class="summary-card-eyebrow">Itens</span>
                <div class="summary-card-value">Serviços e peças</div>
                <div class="summary-card-meta">O catálogo vem do backend central e não do navegador.</div>
            </article>

            <article class="summary-card">
                <span class="summary-card-eyebrow">Rastreio</span>
                <div class="summary-card-value">Histórico e envios</div>
                <div class="summary-card-meta">Cada mudança de status fica registrada para auditoria.</div>
            </article>

            <article class="summary-card">
                <span class="summary-card-eyebrow">Segurança</span>
                <div class="summary-card-value">RBAC por módulo</div>
                <div class="summary-card-meta">Usuários sem permissão não veem ação sensível no desktop.</div>
            </article>
        </div>
    </section>
@endsection
