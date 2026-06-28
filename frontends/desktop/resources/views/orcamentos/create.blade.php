@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Comercial</p>
            <h2 class="surface-title fs-3 mb-2">Novo orçamento</h2>
            <p class="surface-subtitle mb-0">Crie o orçamento sem acesso direto ao banco e com a mesma linguagem operacional do legado.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('orcamentos.help') }}" class="btn btn-outline-info">
                <i class="bi bi-question-circle me-2"></i>
                Ajuda
            </a>
            <a href="{{ route('orcamentos.index') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('orcamentos.form', [
        'budget' => $budget ?? [],
        'form' => $form ?? [],
        'formAction' => route('orcamentos.store'),
        'formMethod' => 'POST',
        'formTitle' => 'Novo orçamento comercial',
        'formSubtitle' => 'Use os catálogos de clientes, equipamentos, OS, serviços e peças para montar a proposta sem perder o fluxo do legado.',
        'submitLabel' => 'Salvar orçamento',
        'cancelUrl' => route('orcamentos.index'),
        'isEditMode' => false,
    ])
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_ORCAMENTO_FORM = {!! json_encode([
            'draftKey' => 'orcamentos:create',
            'isEditMode' => false,
            'budgetId' => 0,
            'catalogs' => [
                'services' => collect($form['services'] ?? [])->map(static function (array $service): array {
                    return [
                        'id' => (int) ($service['id'] ?? 0),
                        'label' => trim((string) ($service['nome'] ?? 'Serviço')),
                        'description' => trim((string) ($service['descricao'] ?? '')),
                        'price' => (float) ($service['valor'] ?? 0),
                    ];
                })->values(),
                'parts' => collect($form['parts'] ?? [])->map(static function (array $part): array {
                    return [
                        'id' => (int) ($part['id'] ?? 0),
                        'label' => trim((string) (($part['codigo'] ?? '') !== '' ? $part['codigo'] . ' - ' . ($part['nome'] ?? 'Peça') : ($part['nome'] ?? 'Peça'))),
                        'description' => trim((string) ($part['nome'] ?? '')),
                        'price' => (float) ($part['preco_venda'] ?? 0),
                    ];
                })->values(),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
    </script>
    <script src="{{ asset('assets/js/orcamentos-form.js') }}?v={{ filemtime(public_path('assets/js/orcamentos-form.js')) }}"></script>
@endsection
