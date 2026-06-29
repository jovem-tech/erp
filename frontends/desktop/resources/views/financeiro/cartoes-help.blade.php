@extends('layouts.app')

@section('content')
    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <p class="desktop-eyebrow">Ajuda</p>
                <h2 class="surface-title mb-1">Cartões e Taxas</h2>
                <p class="surface-subtitle mb-0">Referência operacional rápida para o cadastro de operadoras, bandeiras, faixas e taxas online.</p>
            </div>

            <a href="{{ route('financeiro.cartoes.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar para cartões e taxas
            </a>
        </div>

        <div class="surface-list mt-4">
            <div class="surface-list-item">
                <strong>1. Operadoras de maquininha</strong>
                <span>Cadastre a operadora, defina a ordem de exibição e o prazo padrão de repasse usado nas simulações.</span>
            </div>
            <div class="surface-list-item">
                <strong>2. Bandeiras</strong>
                <span>Mantenha o catálogo de bandeiras ativo para liberar as faixas por parcela com seleção assistida.</span>
            </div>
            <div class="surface-list-item">
                <strong>3. Taxa por parcela</strong>
                <span>Crie faixas com operadora, bandeira, modalidade, parcelas, taxa percentual, taxa fixa e prazo de liquidação.</span>
            </div>
            <div class="surface-list-item">
                <strong>4. Simulador de faturamento líquido</strong>
                <span>Informe valor bruto, operadora, bandeira e modalidade para ver a taxa estimada e o valor líquido antes do recebimento.</span>
            </div>
            <div class="surface-list-item">
                <strong>5. Taxas online</strong>
                <span>Configure gateways e modalidades para embutir taxa em cobranças online sem abrir o legado.</span>
            </div>
            <div class="surface-list-item">
                <strong>6. Edição rápida</strong>
                <span>Use o menu Ações na tabela para editar ou desativar qualquer registro sem sair da página.</span>
            </div>
        </div>
    </section>
@endsection
