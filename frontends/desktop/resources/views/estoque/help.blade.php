@extends('layouts.app')

@section('content')
    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Ajuda de estoque</h2>
                <p class="surface-subtitle">Este módulo replica o estoque operacional do legado e expõe controle de peças, movimentações e importação em lote.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="surface-card p-3 h-100">
                    <h3 class="h5 mb-2">O que você pode fazer</h3>
                    <ul class="mb-0 text-secondary">
                        <li>Pesquisar peças por código, nome, categoria ou fornecedor.</li>
                        <li>Cadastrar, editar, encerrar e desativar peças.</li>
                        <li>Registrar movimentações de entrada, saída e ajuste.</li>
                        <li>Exportar para CSV e importar em lote usando o modelo oficial.</li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="surface-card p-3 h-100">
                    <h3 class="h5 mb-2">Boas práticas</h3>
                    <ul class="mb-0 text-secondary">
                        <li>Mantenha o código da peça padronizado.</li>
                        <li>Atualize o estoque mínimo para receber alertas de baixa quantidade.</li>
                        <li>Use movimentações vinculadas à OS sempre que a peça for aplicada em serviço.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
@endsection
