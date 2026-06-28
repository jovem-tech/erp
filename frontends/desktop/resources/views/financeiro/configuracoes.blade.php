@extends('layouts.app')

@section('content')
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Financeiro</p>
            <h2 class="surface-title fs-3 mb-2">Configurações financeiras</h2>
            <p class="surface-subtitle mb-0">Categorias e catálogo de DRE usados para classificar automaticamente os lançamentos.</p>
        </div>

        <a href="{{ route('financeiro.index') }}" class="btn btn-outline-light align-self-start">
            <i class="bi bi-arrow-left me-2"></i>
            Voltar para lançamentos
        </a>
    </div>

    <div class="surface-card desktop-config-tabs-shell">
        <div class="config-subtabs" role="tablist" aria-label="Sub-abas financeiras">
            <button type="button" class="config-subtab is-active" data-config-subtab="categorias">
                <i class="bi bi-tags me-1"></i>Categorias
            </button>
            <button type="button" class="config-subtab" data-config-subtab="grupos">
                <i class="bi bi-folder me-1"></i>Grupos DRE
            </button>
            <button type="button" class="config-subtab" data-config-subtab="subgrupos">
                <i class="bi bi-diagram-2 me-1"></i>Subgrupos DRE
            </button>
            <button type="button" class="config-subtab" data-config-subtab="comissionamento">
                <i class="bi bi-person-badge me-1"></i>Comissionamento
            </button>
        </div>

        <div class="config-subpanel is-active" data-config-subpanel="categorias">
            <h4 class="surface-title mt-3 mb-3">Nova categoria</h4>
            <form method="post" action="{{ route('financeiro.configuracoes.categorias.save') }}" class="desktop-grid desktop-grid-three mb-4">
                @csrf
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" class="form-control" required>
                </div>
                <div>
                    <label>Tipo</label>
                    <select name="tipo" class="form-select" required>
                        <option value="receber">A receber</option>
                        <option value="pagar">A pagar</option>
                        <option value="ambos">Ambos</option>
                    </select>
                </div>
                <div>
                    <label>Ordem de exibição</label>
                    <input type="number" name="ordem_exibicao" class="form-control" value="0">
                </div>
                <div>
                    <label>Grupo DRE</label>
                    <select name="dre_grupo_id" class="form-select">
                        <option value="">Sem grupo</option>
                        @foreach ($dreGrupos as $grupo)
                            <option value="{{ $grupo['id'] }}">{{ $grupo['nome'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Subgrupo DRE</label>
                    <select name="dre_subgrupo_id" class="form-select">
                        <option value="">Sem subgrupo</option>
                        @foreach ($dreSubgrupos as $subgrupo)
                            <option value="{{ $subgrupo['id'] }}">{{ $subgrupo['grupo']['nome'] ?? '' }} / {{ $subgrupo['nome'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="d-flex align-items-end gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="impacta_dre_padrao" value="1" id="catImpactaDre" checked>
                        <label class="form-check-label" for="catImpactaDre">Impacta DRE</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="impacta_fluxo_caixa_padrao" value="1" id="catImpactaFluxo" checked>
                        <label class="form-check-label" for="catImpactaFluxo">Impacta caixa</label>
                    </div>
                </div>
                <div class="field-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Criar categoria</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                    <tr>
                        <th>Nome</th><th>Tipo</th><th>Grupo / Subgrupo</th><th>Padrões</th><th class="text-end">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($categorias as $categoria)
                        <tr>
                            <td>{{ $categoria['nome'] }}</td>
                            <td>{{ ucfirst($categoria['tipo']) }}</td>
                            <td>
                                {{ $categoria['dre_grupo']['nome'] ?? '-' }}
                                @if (! empty($categoria['dre_subgrupo']['nome']))
                                    / {{ $categoria['dre_subgrupo']['nome'] }}
                                @endif
                            </td>
                            <td>
                                @if ($categoria['impacta_dre_padrao'] ?? false) <span class="badge text-bg-light border me-1">DRE</span> @endif
                                @if ($categoria['impacta_fluxo_caixa_padrao'] ?? false) <span class="badge text-bg-light border">Caixa</span> @endif
                            </td>
                            <td class="text-end">
                                <form method="post" action="{{ route('financeiro.configuracoes.categorias.delete', $categoria['id']) }}" data-confirm="Excluir esta categoria?" data-confirm-title="Excluir categoria" data-confirm-button="Sim, excluir" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="config-subpanel" data-config-subpanel="grupos">
            <h4 class="surface-title mt-3 mb-3">Novo grupo DRE</h4>
            <form method="post" action="{{ route('financeiro.configuracoes.grupos.save') }}" class="desktop-grid desktop-grid-three mb-4">
                @csrf
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" class="form-control" required>
                </div>
                <div>
                    <label>Descrição</label>
                    <input type="text" name="descricao" class="form-control">
                </div>
                <div>
                    <label>Ordem de exibição</label>
                    <input type="number" name="ordem_exibicao" class="form-control" value="0">
                </div>
                <div class="field-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Criar grupo</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead><tr><th>Nome</th><th>Descrição</th><th>Ordem</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    @foreach ($dreGrupos as $grupo)
                        <tr>
                            <td>{{ $grupo['nome'] }}</td>
                            <td>{{ $grupo['descricao'] ?? '-' }}</td>
                            <td>{{ $grupo['ordem_exibicao'] }}</td>
                            <td class="text-end">
                                <form method="post" action="{{ route('financeiro.configuracoes.grupos.delete', $grupo['id']) }}" data-confirm="Excluir este grupo DRE?" data-confirm-title="Excluir grupo" data-confirm-button="Sim, excluir" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="config-subpanel" data-config-subpanel="subgrupos">
            <h4 class="surface-title mt-3 mb-3">Novo subgrupo DRE</h4>
            <form method="post" action="{{ route('financeiro.configuracoes.subgrupos.save') }}" class="desktop-grid desktop-grid-three mb-4">
                @csrf
                <div>
                    <label>Grupo DRE</label>
                    <select name="grupo_id" class="form-select" required>
                        @foreach ($dreGrupos as $grupo)
                            <option value="{{ $grupo['id'] }}">{{ $grupo['nome'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" class="form-control" required>
                </div>
                <div>
                    <label>Ordem de exibição</label>
                    <input type="number" name="ordem_exibicao" class="form-control" value="0">
                </div>
                <div class="field-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Criar subgrupo</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead><tr><th>Grupo</th><th>Nome</th><th>Ordem</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    @foreach ($dreSubgrupos as $subgrupo)
                        <tr>
                            <td>{{ $subgrupo['grupo']['nome'] ?? '-' }}</td>
                            <td>{{ $subgrupo['nome'] }}</td>
                            <td>{{ $subgrupo['ordem_exibicao'] }}</td>
                            <td class="text-end">
                                <form method="post" action="{{ route('financeiro.configuracoes.subgrupos.delete', $subgrupo['id']) }}" data-confirm="Excluir este subgrupo DRE?" data-confirm-title="Excluir subgrupo" data-confirm-button="Sim, excluir" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="config-subpanel" data-config-subpanel="comissionamento">
            <h4 class="surface-title mt-3 mb-3">Percentual padrão de comissão</h4>
            <form method="post" action="{{ route('financeiro.configuracoes.comissoes.padrao') }}" class="desktop-grid desktop-grid-three mb-4">
                @csrf
                <div>
                    <label>Percentual padrão (%)</label>
                    <input type="number" name="percentual_padrao" class="form-control" step="0.01" min="0" max="100" value="{{ $comissaoPercentualPadrao ?? 0 }}" required>
                    <small class="text-muted d-block mt-1">Usado quando o técnico da OS não tem comissão específica cadastrada.</small>
                </div>
                <div class="field-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save2 me-1"></i>Salvar padrão</button>
                </div>
            </form>

            <h4 class="surface-title mb-3">Comissão específica por técnico</h4>
            <form method="post" action="{{ route('financeiro.configuracoes.comissoes.save') }}" class="desktop-grid desktop-grid-three mb-4">
                @csrf
                <div>
                    <label>ID do técnico (usuário)</label>
                    <input type="number" name="tecnico_id" class="form-control" min="1" required>
                </div>
                <div>
                    <label>Percentual (%)</label>
                    <input type="number" name="percentual_padrao" class="form-control" step="0.01" min="0" max="100" required>
                </div>
                <div class="field-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Cadastrar comissão</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead><tr><th>Técnico</th><th>Percentual</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    @foreach (($comissoesTecnicos ?? []) as $comissao)
                        <tr>
                            <td>{{ $comissao['tecnico']['nome'] ?? ('Usuário #' . ($comissao['tecnico_id'] ?? '-')) }}</td>
                            <td>{{ number_format((float) ($comissao['percentual_padrao'] ?? 0), 2, ',', '.') }}%</td>
                            <td class="text-end">
                                <form method="post" action="{{ route('financeiro.configuracoes.comissoes.delete', $comissao['id']) }}" data-confirm="Excluir esta comissão?" data-confirm-title="Excluir comissão" data-confirm-button="Sim, excluir" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        (() => {
            const subtabs = Array.from(document.querySelectorAll('[data-config-subtab]'));
            const subpanels = Array.from(document.querySelectorAll('[data-config-subpanel]'));

            subtabs.forEach((button) => {
                button.addEventListener('click', () => {
                    const name = button.getAttribute('data-config-subtab') || '';

                    subtabs.forEach((tab) => tab.classList.toggle('is-active', tab.getAttribute('data-config-subtab') === name));
                    subpanels.forEach((panel) => panel.classList.toggle('is-active', panel.getAttribute('data-config-subpanel') === name));
                });
            });
        })();
    </script>
@endsection
