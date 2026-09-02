@extends('layouts.app')

@section('content')
    @php
        $areas = $prontidao['areas'] ?? [];
        $empresa = $areas['empresa'] ?? [];
        $clientes = $areas['clientes'] ?? [];
        $servicos = $areas['servicos'] ?? [];
        $pecas = $areas['pecas'] ?? [];
        $pendencias = (int) ($prontidao['pendencias_totais'] ?? 0);
        $numero = static fn ($valor) => number_format((float) $valor, 0, ',', '.');
        $pct = static fn ($valor) => number_format((float) $valor, 1, ',', '.') . '%';
        $camposFaltando = $empresa['campos_faltando'] ?? [];
        $certificado = $areas['certificado'] ?? [];
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Fiscal</p>
            <h2 class="surface-title fs-3 mb-2">Prontidão fiscal <x-favorite-toggle /></h2>
            <p class="surface-subtitle mb-0">
                A partir de 1º de janeiro de 2027 a nota passa a ser obrigatória em toda operação,
                inclusive para pessoa física. A NFS-e exige identificar o tomador e classificar o que
                foi vendido — sem esses dados no cadastro, não há nota a emitir.
            </p>
        </div>
    </div>

    @if ($pendencias === 0)
        <div class="alert alert-success">Nenhuma pendência de cadastro. Nada pendente por aqui.</div>
    @else
        <div class="alert alert-warning">
            <strong>{{ $numero($pendencias) }}</strong> pendência(s) de cadastro. Esse dado entra pela
            porta do cadastro, um registro de cada vez — não existe importação que resolva.
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="surface-card h-100 p-3">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <p class="desktop-eyebrow mb-1">Clientes</p>
                        <p class="fs-3 fw-semibold mb-0">
                            {{ $numero($clientes['prontos'] ?? 0) }} <span class="surface-subtitle fs-6">de {{ $numero($clientes['total'] ?? 0) }}</span>
                        </p>
                    </div>
                    <span class="badge bg-secondary align-self-center">{{ $pct($clientes['percentual_pronto'] ?? 0) }}</span>
                </div>
                <ul class="mb-3 ps-3 surface-subtitle small">
                    <li><strong class="text-danger">{{ $numero($clientes['sem_documento'] ?? 0) }}</strong> sem CPF/CNPJ — pedir no próximo atendimento.</li>
                    <li><strong class="text-warning">{{ $numero($clientes['documento_invalido'] ?? 0) }}</strong> com documento inválido — erro de digitação já gravado.</li>
                </ul>
                <a href="{{ route('clients.index') }}" class="btn btn-outline-primary btn-sm">Abrir clientes</a>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="surface-card h-100 p-3">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <p class="desktop-eyebrow mb-1">Dados da empresa</p>
                        <p class="fs-3 fw-semibold mb-0">
                            {{ $numero($empresa['prontos'] ?? 0) }} <span class="surface-subtitle fs-6">de {{ $numero($empresa['total'] ?? 0) }} campos</span>
                        </p>
                    </div>
                    <span class="badge bg-secondary align-self-center">{{ $pct($empresa['percentual_pronto'] ?? 0) }}</span>
                </div>
                @if ($camposFaltando !== [])
                    <p class="surface-subtitle small mb-3">Faltando: {{ implode(', ', $camposFaltando) }}.</p>
                @else
                    <p class="surface-subtitle small mb-3">Todos os campos fiscais estão preenchidos.</p>
                @endif
                <a href="{{ route('configurations.system.index') }}" class="btn btn-outline-primary btn-sm">Abrir configurações</a>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="surface-card h-100 p-3">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <p class="desktop-eyebrow mb-1">Serviços ativos</p>
                        <p class="fs-3 fw-semibold mb-0">
                            {{ $numero($servicos['prontos'] ?? 0) }} <span class="surface-subtitle fs-6">de {{ $numero($servicos['total'] ?? 0) }}</span>
                        </p>
                    </div>
                    <span class="badge bg-secondary align-self-center">{{ $pct($servicos['percentual_pronto'] ?? 0) }}</span>
                </div>
                <p class="surface-subtitle small mb-3">
                    <strong>{{ $numero($servicos['sem_codigo_tributacao'] ?? 0) }}</strong> sem código de tributação nacional.
                </p>
                <a href="{{ route('servicos.index') }}" class="btn btn-outline-primary btn-sm">Abrir serviços</a>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="surface-card h-100 p-3">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <p class="desktop-eyebrow mb-1">Peças ativas</p>
                        <p class="fs-3 fw-semibold mb-0">
                            {{ $numero($pecas['prontos'] ?? 0) }} <span class="surface-subtitle fs-6">de {{ $numero($pecas['total'] ?? 0) }}</span>
                        </p>
                    </div>
                    <span class="badge bg-secondary align-self-center">{{ $pct($pecas['percentual_pronto'] ?? 0) }}</span>
                </div>
                <p class="surface-subtitle small mb-3">
                    <strong>{{ $numero($pecas['sem_ncm'] ?? 0) }}</strong> sem NCM.
                </p>
                <a href="{{ route('estoque.index') }}" class="btn btn-outline-primary btn-sm">Abrir estoque</a>
            </div>
        </div>
    </div>

    <div class="surface-card p-3 mb-3">
        <h3 class="surface-title fs-5 mb-2">Certificado digital A1</h3>
        @if (! ($certificado['instalado'] ?? false))
            <p class="surface-subtitle mb-0">
                Nenhum certificado instalado — e isso <strong>não</strong> impede a nota de serviço:
                a NFS-e continua sendo emitida pelo modo assistido, com sua conta gov.br. O A1 é
                necessário para emitir direto do sistema e, obrigatoriamente, para nota de peça,
                que sai pela SEFAZ do estado.
            </p>
        @elseif ($certificado['usavel'] ?? false)
            <p class="mb-1">
                <span class="badge bg-success">Válido</span>
                {{ $certificado['titular'] ?? '' }}
                <span class="surface-subtitle">· {{ $certificado['documento_titular'] ?? '' }}</span>
            </p>
            <p class="surface-subtitle mb-0">
                Vence em {{ \Illuminate\Support\Carbon::parse($certificado['expira_em'])->format('d/m/Y') }}
                ({{ $certificado['dias_ate_vencimento'] }} dias).
                @if ((int) ($certificado['dias_ate_vencimento'] ?? 999) <= 30)
                    <strong class="text-warning">Renove agora — vencido, a emissão para de autenticar sem aviso.</strong>
                @endif
            </p>
        @else
            <p class="mb-1"><span class="badge bg-danger">Com problema</span></p>
            <ul class="mb-0 ps-3 surface-subtitle">
                @foreach (($certificado['problemas'] ?? []) as $problema)
                    <li>{{ $problema }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="surface-card p-3">
        <h3 class="surface-title fs-5 mb-2">O que este relatório não resolve</h3>
        <ul class="mb-0 ps-3 surface-subtitle">
            <li>Certificado digital e-CNPJ A1 — obrigatório para nota de peça, que sai pela SEFAZ estadual.</li>
            <li>O código de tributação de cada serviço e o NCM de cada peça precisam de confirmação do contador.</li>
            <li>Só contamos serviço e peça <strong>ativos</strong>: item encerrado não vai para nota nenhuma.</li>
        </ul>
    </div>
@endsection
