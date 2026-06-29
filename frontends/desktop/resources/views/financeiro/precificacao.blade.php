@extends('layouts.app')

@section('content')
    @php
        $precificacao = is_array($precificacao ?? null) ? $precificacao : [];
        $settings = is_array($precificacao['settings'] ?? null) ? $precificacao['settings'] : [];
        $summary = is_array($precificacao['summary'] ?? null) ? $precificacao['summary'] : [];
        $rulesPeca = is_array($precificacao['rules_peca'] ?? null) ? $precificacao['rules_peca'] : [];
        $rulesServico = is_array($precificacao['rules_servico'] ?? null) ? $precificacao['rules_servico'] : [];
        $componentes = is_array($precificacao['componentes'] ?? null) ? $precificacao['componentes'] : [];
        $categorias = is_array($precificacao['categorias'] ?? null) ? $precificacao['categorias'] : [];
        $categoriaEncargos = is_array($precificacao['categoria_encargos'] ?? null) ? $precificacao['categoria_encargos'] : [];
        $servicoOverrides = is_array($precificacao['servico_overrides'] ?? null) ? $precificacao['servico_overrides'] : [];
        $pecas = is_array($precificacao['pecas'] ?? null) ? $precificacao['pecas'] : [];
        $servicos = is_array($precificacao['servicos'] ?? null) ? $precificacao['servicos'] : [];
        $activeTab = (string) ($activeTab ?? 'configuracao');
        $simulation = is_array($simulation ?? null) ? $simulation : [];
        $simulationType = (string) ($simulationType ?? '');
        $boolValue = static fn ($value): bool => in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    @endphp

    <section class="desktop-page-stack">
        <div class="desktop-page-hero">
            <div>
                <h2>Precificação</h2>
                <p>Módulo financeiro dedicado à formação de preço de peças e serviços, com regras centrais vindas do backend.</p>
            </div>

            <a href="{{ route('financeiro.index') }}" class="btn btn-outline-light align-self-start">
                <i class="bi bi-arrow-left me-2"></i>Voltar ao financeiro
            </a>
        </div>

        <div class="surface-card desktop-config-tabs-shell">
            <div class="config-subtabs flex-wrap" role="tablist" aria-label="Precificação">
                <button type="button" class="config-subtab {{ $activeTab === 'configuracao' ? 'is-active' : '' }}" data-config-subtab="configuracao">
                    <i class="bi bi-sliders me-1"></i>Configuração
                </button>
                <button type="button" class="config-subtab {{ $activeTab === 'simulador' ? 'is-active' : '' }}" data-config-subtab="simulador">
                    <i class="bi bi-calculator me-1"></i>Simulador
                </button>
            </div>

            <div class="config-subpanel {{ $activeTab === 'configuracao' ? 'is-active' : '' }}" data-config-subpanel="configuracao">
                <form method="post" action="{{ route('financeiro.precificacao.save') }}" class="desktop-grid gap-4">
                    @csrf

                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Regras de peça</h3>
                                <p class="surface-subtitle mb-0">Base, encargos e margem utilizados na formação do preço da peça.</p>
                            </div>
                        </div>

                        <div class="desktop-grid desktop-grid-two">
                            <div>
                                <label>Base da peça</label>
                                <select name="precificacao_peca_base" class="form-select">
                                    <option value="custo" @selected(old('precificacao_peca_base', $settings['precificacao_peca_base'] ?? 'custo') === 'custo')>Custo da peça</option>
                                    <option value="venda" @selected(old('precificacao_peca_base', $settings['precificacao_peca_base'] ?? 'custo') === 'venda')>Venda da peça</option>
                                </select>
                            </div>
                            <div>
                                <label>Encargos totais (%)</label>
                                <input type="number" step="0.01" min="0" max="500" class="form-control" name="precificacao_peca_encargos_percentual" value="{{ old('precificacao_peca_encargos_percentual', $settings['precificacao_peca_encargos_percentual'] ?? '15') }}">
                            </div>
                            <div>
                                <label>Margem da peça (%)</label>
                                <input type="number" step="0.01" min="0" max="500" class="form-control" name="precificacao_peca_margem_percentual" value="{{ old('precificacao_peca_margem_percentual', $settings['precificacao_peca_margem_percentual'] ?? '45') }}">
                            </div>
                            <div>
                                <label>Respeitar preço de venda</label>
                                <select name="precificacao_peca_respeitar_preco_venda" class="form-select">
                                    <option value="1" @selected($boolValue(old('precificacao_peca_respeitar_preco_venda', $settings['precificacao_peca_respeitar_preco_venda'] ?? '1')))>Sim</option>
                                    <option value="0" @selected(! $boolValue(old('precificacao_peca_respeitar_preco_venda', $settings['precificacao_peca_respeitar_preco_venda'] ?? '1')))>Não</option>
                                </select>
                            </div>
                            <div>
                                <label>Usar componentes da peça</label>
                                <select name="precificacao_peca_usa_componentes" class="form-select">
                                    <option value="1" @selected($boolValue(old('precificacao_peca_usa_componentes', $settings['precificacao_peca_usa_componentes'] ?? '1')))>Sim</option>
                                    <option value="0" @selected(! $boolValue(old('precificacao_peca_usa_componentes', $settings['precificacao_peca_usa_componentes'] ?? '1')))>Não</option>
                                </select>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <span class="badge text-bg-light border text-secondary">Base: {{ $rulesPeca['base'] ?? 'custo' }}</span>
                            <span class="badge text-bg-light border text-secondary">Encargos: {{ number_format((float) ($rulesPeca['encargos_percentual'] ?? 0), 2, ',', '.') }}%</span>
                            <span class="badge text-bg-light border text-secondary">Margem: {{ number_format((float) ($rulesPeca['margem_percentual'] ?? 0), 2, ',', '.') }}%</span>
                            <span class="badge text-bg-light border text-secondary">Componentes: {{ $summary['componentes_peca_total'] ?? 0 }}</span>
                        </div>
                    </div>

                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Regras de serviço</h3>
                                <p class="surface-subtitle mb-0">Custo hora, margem e taxas usadas na simulação comercial.</p>
                            </div>
                        </div>

                        <div class="desktop-grid desktop-grid-three">
                            <div>
                                <label>Custo hora produtiva (R$)</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="precificacao_servico_custo_hora_produtiva" value="{{ old('precificacao_servico_custo_hora_produtiva', $settings['precificacao_servico_custo_hora_produtiva'] ?? '40') }}">
                            </div>
                            <div>
                                <label>Margem alvo (%)</label>
                                <input type="number" step="0.01" min="0" max="500" class="form-control" name="precificacao_servico_margem_percentual" value="{{ old('precificacao_servico_margem_percentual', $settings['precificacao_servico_margem_percentual'] ?? '25') }}">
                            </div>
                            <div>
                                <label>Taxa de recebimento (%)</label>
                                <input type="number" step="0.01" min="0" max="100" class="form-control" name="precificacao_servico_taxa_recebimento_percentual" value="{{ old('precificacao_servico_taxa_recebimento_percentual', $settings['precificacao_servico_taxa_recebimento_percentual'] ?? '3.5') }}">
                            </div>
                            <div>
                                <label>Imposto (%)</label>
                                <input type="number" step="0.01" min="0" max="100" class="form-control" name="precificacao_servico_imposto_percentual" value="{{ old('precificacao_servico_imposto_percentual', $settings['precificacao_servico_imposto_percentual'] ?? '0') }}">
                            </div>
                            <div>
                                <label>Tempo padrão (h)</label>
                                <input type="number" step="0.01" min="0.1" class="form-control" name="precificacao_servico_tempo_padrao_horas" value="{{ old('precificacao_servico_tempo_padrao_horas', $settings['precificacao_servico_tempo_padrao_horas'] ?? '1') }}">
                            </div>
                            <div>
                                <label>Usar componentes de serviço</label>
                                <select name="precificacao_servico_usa_componentes" class="form-select">
                                    <option value="1" @selected($boolValue(old('precificacao_servico_usa_componentes', $settings['precificacao_servico_usa_componentes'] ?? '1')))>Sim</option>
                                    <option value="0" @selected(! $boolValue(old('precificacao_servico_usa_componentes', $settings['precificacao_servico_usa_componentes'] ?? '1')))>Não</option>
                                </select>
                            </div>
                            <div>
                                <label>Aplicar catálogo</label>
                                <select name="precificacao_servico_aplicar_catalogo" class="form-select">
                                    <option value="1" @selected($boolValue(old('precificacao_servico_aplicar_catalogo', $settings['precificacao_servico_aplicar_catalogo'] ?? '1')))>Sim</option>
                                    <option value="0" @selected(! $boolValue(old('precificacao_servico_aplicar_catalogo', $settings['precificacao_servico_aplicar_catalogo'] ?? '1')))>Não</option>
                                </select>
                            </div>
                            <div>
                                <label>Aplicar piso</label>
                                <select name="precificacao_servico_aplicar_piso" class="form-select">
                                    <option value="1" @selected($boolValue(old('precificacao_servico_aplicar_piso', $settings['precificacao_servico_aplicar_piso'] ?? '0')))>Sim</option>
                                    <option value="0" @selected(! $boolValue(old('precificacao_servico_aplicar_piso', $settings['precificacao_servico_aplicar_piso'] ?? '0')))>Não</option>
                                </select>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <span class="badge text-bg-light border text-secondary">Custo hora: R$ {{ number_format((float) ($rulesServico['custo_hora_produtiva'] ?? 0), 2, ',', '.') }}</span>
                            <span class="badge text-bg-light border text-secondary">Margem: {{ number_format((float) ($rulesServico['margem_percentual'] ?? 0), 2, ',', '.') }}%</span>
                            <span class="badge text-bg-light border text-secondary">Taxa: {{ number_format((float) ($rulesServico['taxa_recebimento_percentual'] ?? 0), 2, ',', '.') }}%</span>
                            <span class="badge text-bg-light border text-secondary">Componentes: {{ $summary['componentes_servico_custo_total'] ?? 0 }}/{{ $summary['componentes_servico_risco_total'] ?? 0 }}</span>
                        </div>
                    </div>

                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Catálogos e apoio operacional</h3>
                                <p class="surface-subtitle mb-0">Visão rápida das categorias e dos registros usados pelo simulador.</p>
                            </div>
                        </div>

                        <div class="desktop-grid desktop-grid-three">
                            <div>
                                <label>Peças cadastradas</label>
                                <div class="form-control bg-light-subtle">
                                    <strong>{{ count($pecas) }}</strong> itens ativos
                                </div>
                            </div>
                            <div>
                                <label>Serviços cadastrados</label>
                                <div class="form-control bg-light-subtle">
                                    <strong>{{ count($servicos) }}</strong> itens ativos
                                </div>
                            </div>
                            <div>
                                <label>Overrides de serviço</label>
                                <div class="form-control bg-light-subtle">
                                    <strong>{{ count($servicoOverrides) }}</strong> registros
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-save me-1"></i>Salvar precificação
                        </button>
                    </div>
                </form>

                <div class="desktop-grid desktop-grid-two mt-4">
                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Componentes da peça</h3>
                                <p class="surface-subtitle mb-0">Soma dos encargos percentuais aplicada quando o uso de componentes está ativo.</p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-stack align-middle">
                                <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Valor</th>
                                    <th>Tipo</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($componentes['peca'] ?? [] as $item)
                                    <tr>
                                        <td>{{ $item['nome'] ?? '-' }}</td>
                                        <td>{{ number_format((float) ($item['valor'] ?? 0), 2, ',', '.') }}%</td>
                                        <td>{{ $item['tipo_valor'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">Nenhum componente de peça configurado.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Componentes de serviço</h3>
                                <p class="surface-subtitle mb-0">Custos fixos e risco usados no cálculo operacional.</p>
                            </div>
                        </div>

                        <div class="table-responsive mb-3">
                            <table class="table table-stack align-middle">
                                <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Valor</th>
                                    <th>Grupo</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($componentes['servico_custo'] ?? [] as $item)
                                    <tr>
                                        <td>{{ $item['nome'] ?? '-' }}</td>
                                        <td>R$ {{ number_format((float) ($item['valor'] ?? 0), 2, ',', '.') }}</td>
                                        <td>{{ $item['grupo'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">Nenhum custo direto configurado.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-stack align-middle">
                                <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Valor</th>
                                    <th>Grupo</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($componentes['servico_risco'] ?? [] as $item)
                                    <tr>
                                        <td>{{ $item['nome'] ?? '-' }}</td>
                                        <td>{{ number_format((float) ($item['valor'] ?? 0), 2, ',', '.') }}%</td>
                                        <td>{{ $item['grupo'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">Nenhum risco configurado.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="desktop-grid desktop-grid-two mt-4">
                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Categorias de peça</h3>
                                <p class="surface-subtitle mb-0">Overrides de encargos e margem por categoria de peça.</p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-stack align-middle">
                                <thead>
                                <tr>
                                    <th>Categoria</th>
                                    <th>Encargos</th>
                                    <th>Margem</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($categorias['peca'] ?? [] as $item)
                                    <tr>
                                        <td>{{ $item['categoria_nome'] ?? '-' }}</td>
                                        <td>{{ number_format((float) ($item['encargos_percentual'] ?? 0), 2, ',', '.') }}%</td>
                                        <td>{{ number_format((float) ($item['margem_percentual'] ?? 0), 2, ',', '.') }}%</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">Nenhuma categoria de peça configurada.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Categorias de serviço</h3>
                                <p class="surface-subtitle mb-0">Categorias usadas para ajustes de risco e margem de serviço.</p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-stack align-middle">
                                <thead>
                                <tr>
                                    <th>Categoria</th>
                                    <th>Encargos</th>
                                    <th>Margem</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($categorias['servico'] ?? [] as $item)
                                    <tr>
                                        <td>{{ $item['categoria_nome'] ?? '-' }}</td>
                                        <td>{{ number_format((float) ($item['encargos_percentual'] ?? 0), 2, ',', '.') }}%</td>
                                        <td>{{ number_format((float) ($item['margem_percentual'] ?? 0), 2, ',', '.') }}%</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">Nenhuma categoria de serviço configurada.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="desktop-form-card mt-4">
                    <div class="surface-card-header">
                        <div>
                            <h3 class="surface-title mb-1">Encargos por categoria</h3>
                            <p class="surface-subtitle mb-0">Base estrutural para o detalhamento de encargos quando a configuração avançar.</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-stack align-middle">
                            <thead>
                            <tr>
                                <th>Categoria</th>
                                <th>Encargos cadastrados</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($categoriaEncargos as $entry)
                                <tr>
                                    <td>{{ $entry['categoria']['categoria_nome'] ?? '-' }}</td>
                                    <td>{{ count($entry['encargos'] ?? []) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-muted">Nenhum detalhe de encargos por categoria disponível.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="config-subpanel {{ $activeTab === 'simulador' ? 'is-active' : '' }}" data-config-subpanel="simulador">
                <div class="desktop-grid desktop-grid-two">
                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Simular peça</h3>
                                <p class="surface-subtitle mb-0">Use um registro existente ou informe valores manuais.</p>
                            </div>
                        </div>

                        <form method="post" action="{{ route('financeiro.precificacao.simular-peca') }}" class="desktop-grid desktop-grid-two">
                            @csrf
                            <div class="col-span-2">
                                <label>Peça cadastrada</label>
                                <select class="form-select" name="peca_id">
                                    <option value="">Seleção manual</option>
                                    @foreach ($pecas as $peca)
                                        <option value="{{ $peca['id'] }}" @selected((string) old('peca_id') === (string) ($peca['id'] ?? ''))>
                                            {{ $peca['nome'] ?? 'Peça' }} - R$ {{ number_format((float) ($peca['preco_custo'] ?? 0), 2, ',', '.') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label>Preço de custo</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="preco_custo" value="{{ old('preco_custo') }}">
                            </div>
                            <div>
                                <label>Preço de venda</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="preco_venda" value="{{ old('preco_venda') }}">
                            </div>
                            <div class="col-span-2">
                                <label>Categoria</label>
                                <input type="text" class="form-control" name="categoria" value="{{ old('categoria') }}" placeholder="Ex.: Insumos">
                            </div>
                            <div class="col-span-2 text-end">
                                <button type="submit" class="btn btn-outline-primary">
                                    <i class="bi bi-play-circle me-1"></i>Simular peça
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="desktop-form-card">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Simular serviço</h3>
                                <p class="surface-subtitle mb-0">Use um serviço cadastrado ou ajuste os valores de entrada.</p>
                            </div>
                        </div>

                        <form method="post" action="{{ route('financeiro.precificacao.simular-servico') }}" class="desktop-grid desktop-grid-two">
                            @csrf
                            <div class="col-span-2">
                                <label>Serviço cadastrado</label>
                                <select class="form-select" name="servico_id">
                                    <option value="">Seleção manual</option>
                                    @foreach ($servicos as $servico)
                                        <option value="{{ $servico['id'] }}" @selected((string) old('servico_id') === (string) ($servico['id'] ?? ''))>
                                            {{ $servico['nome'] ?? 'Serviço' }} - R$ {{ number_format((float) ($servico['valor'] ?? 0), 2, ',', '.') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label>Tempo padrão (h)</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="tempo_padrao_horas" value="{{ old('tempo_padrao_horas') }}">
                            </div>
                            <div>
                                <label>Custo direto padrão</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="custo_direto_padrao" value="{{ old('custo_direto_padrao') }}">
                            </div>
                            <div>
                                <label>Valor cadastrado</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="valor_cadastro" value="{{ old('valor_cadastro') }}">
                            </div>
                            <div>
                                <label>Tipo de equipamento</label>
                                <input type="text" class="form-control" name="tipo_equipamento" value="{{ old('tipo_equipamento') }}" placeholder="Notebook, desktop, smartphone...">
                            </div>
                            <div class="col-span-2 text-end">
                                <button type="submit" class="btn btn-outline-primary">
                                    <i class="bi bi-play-circle me-1"></i>Simular serviço
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                @if (! empty($simulation))
                    <div class="desktop-form-card mt-4">
                        <div class="surface-card-header">
                            <div>
                                <h3 class="surface-title mb-1">Resultado da simulação</h3>
                                <p class="surface-subtitle mb-0">Último cálculo executado no simulador de precificação.</p>
                            </div>
                        </div>

                        @if ($simulationType === 'peca')
                            <div class="desktop-grid desktop-grid-three">
                                <div><label>Preço base</label><div class="form-control bg-light-subtle">R$ {{ number_format((float) ($simulation['preco_base'] ?? 0), 2, ',', '.') }}</div></div>
                                <div><label>Encargos</label><div class="form-control bg-light-subtle">{{ number_format((float) ($simulation['percentual_encargos'] ?? 0), 2, ',', '.') }}%</div></div>
                                <div><label>Margem</label><div class="form-control bg-light-subtle">{{ number_format((float) ($simulation['percentual_margem'] ?? 0), 2, ',', '.') }}%</div></div>
                                <div><label>Valor dos encargos</label><div class="form-control bg-light-subtle">R$ {{ number_format((float) ($simulation['valor_encargos'] ?? 0), 2, ',', '.') }}</div></div>
                                <div><label>Valor da margem</label><div class="form-control bg-light-subtle">R$ {{ number_format((float) ($simulation['valor_margem'] ?? 0), 2, ',', '.') }}</div></div>
                                <div><label>Valor recomendado</label><div class="form-control bg-light-subtle">R$ {{ number_format((float) ($simulation['valor_recomendado'] ?? 0), 2, ',', '.') }}</div></div>
                            </div>
                        @else
                            <div class="desktop-grid desktop-grid-three">
                                <div><label>Custo hora</label><div class="form-control bg-light-subtle">R$ {{ number_format((float) ($simulation['custo_hora_produtiva'] ?? 0), 2, ',', '.') }}</div></div>
                                <div><label>Custo mão de obra</label><div class="form-control bg-light-subtle">R$ {{ number_format((float) ($simulation['custo_mao_obra'] ?? 0), 2, ',', '.') }}</div></div>
                                <div><label>Custo direto total</label><div class="form-control bg-light-subtle">R$ {{ number_format((float) ($simulation['custo_direto_total'] ?? 0), 2, ',', '.') }}</div></div>
                                <div><label>Custo total</label><div class="form-control bg-light-subtle">R$ {{ number_format((float) ($simulation['custo_total'] ?? 0), 2, ',', '.') }}</div></div>
                                <div><label>Preço mínimo</label><div class="form-control bg-light-subtle">R$ {{ number_format((float) ($simulation['preco_minimo'] ?? 0), 2, ',', '.') }}</div></div>
                                <div><label>Valor recomendado</label><div class="form-control bg-light-subtle">R$ {{ number_format((float) ($simulation['valor_recomendado'] ?? 0), 2, ',', '.') }}</div></div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
