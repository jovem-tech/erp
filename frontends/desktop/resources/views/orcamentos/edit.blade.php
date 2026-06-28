@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Comercial</p>
            <h2 class="surface-title fs-3 mb-2">Editar orçamento</h2>
            <p class="surface-subtitle mb-0">Atualize cliente, equipamento, itens e valores mantendo a rastreabilidade comercial.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('orcamentos.help') }}" class="btn btn-outline-info">
                <i class="bi bi-question-circle me-2"></i>
                Ajuda
            </a>
            <a href="{{ route('orcamentos.show', $budget['id'] ?? 0) }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar
            </a>
        </div>
    </div>

    @include('orcamentos.form', [
        'budget' => $budget ?? [],
        'form' => $form ?? [],
        'formAction' => route('orcamentos.update', $budget['id'] ?? 0),
        'formMethod' => 'PATCH',
        'formTitle' => 'Edição de orçamento',
        'formSubtitle' => 'Revise origem, status, vigência e pacotes antes de salvar as alterações.',
        'submitLabel' => 'Salvar alterações',
        'cancelUrl' => route('orcamentos.show', $budget['id'] ?? 0),
        'isEditMode' => true,
    ])
@endsection

@section('scripts')
    <script>
        window.__DESKTOP_ORCAMENTO_FORM = {!! json_encode([
            'draftKey' => 'orcamentos:edit:' . (int) ($budget['id'] ?? 0),
            'isEditMode' => true,
            'budgetId' => (int) ($budget['id'] ?? 0),
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
