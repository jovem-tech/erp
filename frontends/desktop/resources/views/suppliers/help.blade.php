@extends('layouts.app')

@section('content')
    <section class="desktop-page-hero">
        <div class="desktop-page-hero-copy">
            <h2>Ajuda de fornecedores</h2>
            <p>Guia rápido do fluxo comercial de fornecedores no desktop.</p>
        </div>

        <a href="{{ route('suppliers.index') }}" class="btn btn-outline-light">
            <i class="bi bi-arrow-left me-2"></i>
            Voltar à listagem
        </a>
    </section>

    <section class="dashboard-help-grid">
        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Listagem</strong>
                <p>Mostra nome fantasia, razão social, documento, telefone e situação para acesso rápido ao cadastro.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Cadastro</strong>
                <p>O formulário permite salvar fornecedores físicos ou jurídicos, com status ativo ou inativo.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Consulta por CNPJ</strong>
                <p>Quando o documento é válido, o sistema tenta preencher os dados públicos automaticamente antes de salvar.</p>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-help-item">
                <strong>Encerrar</strong>
                <p>O encerrar marca o fornecedor como inativo sem apagar o histórico já existente no ERP.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Excluir</strong>
                <p>A exclusão deve ser usada somente quando o cadastro ainda não tiver impacto operacional relevante.</p>
            </div>
            <div class="dashboard-help-item">
                <strong>Pesquisa</strong>
                <p>A busca cobre nome fantasia, razão social, documento, telefone, cidade e observações do cadastro.</p>
            </div>
        </article>
    </section>
@endsection
