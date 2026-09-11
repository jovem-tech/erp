@php
    $report = is_array($report ?? null) ? $report : [];
    $erros = is_array($report['erros'] ?? null) ? $report['erros'] : [];
    $mesclagens = is_array($report['mesclagens'] ?? null) ? $report['mesclagens'] : [];
    $criados = (int) ($report['criados']['marcas'] ?? 0) + (int) ($report['criados']['modelos'] ?? 0);
    $maxErrosExibidos = 200;
@endphp

<section class="desktop-form-card mb-4">
    <div class="surface-card-header">
        <div>
            <h2 class="surface-title">Relatório da última importação</h2>
            <p class="surface-subtitle">
                {{ $criados }} criados · {{ (int) ($report['atualizados'] ?? 0) }} atualizados ·
                {{ (int) ($report['reativados'] ?? 0) }} reativados · {{ (int) ($report['desativados'] ?? 0) }} desativados ·
                {{ (int) ($report['ignorados'] ?? 0) }} ignorados (duplicados no arquivo)
                @if ($mesclagens !== [])
                    · {{ count($mesclagens) }} mesclado(s)
                @endif
            </p>
        </div>
    </div>

    @if ($mesclagens !== [])
        <div class="table-responsive mb-3">
            <table class="table table-stack align-middle">
                <thead>
                <tr>
                    <th style="width: 10rem;">ID perdedor</th>
                    <th style="width: 10rem;">ID vencedor</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($mesclagens as $mesclagem)
                    <tr>
                        <td data-label="ID perdedor">{{ (int) ($mesclagem['de'] ?? 0) }}</td>
                        <td data-label="ID vencedor">{{ (int) ($mesclagem['para'] ?? 0) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($erros !== [])
        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                <tr>
                    <th style="width: 8rem;">Linha</th>
                    <th>Motivo</th>
                </tr>
                </thead>
                <tbody>
                @foreach (array_slice($erros, 0, $maxErrosExibidos) as $erro)
                    <tr>
                        <td data-label="Linha">{{ (int) ($erro['linha'] ?? 0) }}</td>
                        <td data-label="Motivo" class="text-danger">{{ $erro['motivo'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if (count($erros) > $maxErrosExibidos)
            <p class="text-secondary small mb-0">e mais {{ count($erros) - $maxErrosExibidos }} linha(s) com erro.</p>
        @endif
    @else
        <p class="text-success mb-0"><i class="bi bi-check-circle me-1"></i>Nenhuma linha rejeitada.</p>
    @endif
</section>
