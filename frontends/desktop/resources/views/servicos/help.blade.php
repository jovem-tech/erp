@extends('layouts.app')

@section('content')
    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Ajuda de serviços</h2>
                <p class="surface-subtitle">Este módulo replica a lista operacional de serviços do legado e conversa somente com a API central.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="surface-card p-3 h-100">
                    <h3 class="h5 mb-2">O que você pode fazer</h3>
                    <ul class="mb-0 text-secondary">
                        <li>Pesquisar serviços por nome, descrição ou tipo de equipamento.</li>
                        <li>Criar, editar, encerrar e excluir serviços.</li>
                        <li>Exportar a lista para CSV e importar em lote pelo modelo.</li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="surface-card p-3 h-100">
                    <h3 class="h5 mb-2">Boas práticas</h3>
                    <ul class="mb-0 text-secondary">
                        <li>Use nomenclatura objetiva para o serviço.</li>
                        <li>Defina o tipo de equipamento quando o serviço for especializado.</li>
                        <li>Encerre serviços que não devam mais aparecer no fluxo principal.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
@endsection
