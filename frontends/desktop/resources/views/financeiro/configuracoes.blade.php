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
            <button type="button" class="config-subtab" data-config-subtab="formas-pagamento">
                <i class="bi bi-credit-card me-1"></i>Formas de Pagamento
            </button>
        </div>

        <div class="config-subpanel is-active" data-config-subpanel="categorias">
            <h4 class="surface-title mt-3 mb-3" id="categoriaFormTitle">Nova categoria</h4>
            <form method="post" action="{{ route('financeiro.configuracoes.categorias.save') }}" class="desktop-grid desktop-grid-three mb-4" id="categoriaForm">
                @csrf
                <input type="hidden" name="id" id="categoriaFormId" value="">
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" class="form-control" id="categoriaFormNome" required>
                </div>
                <div>
                    <label>Tipo</label>
                    <select name="tipo" class="form-select" id="categoriaFormTipo" required>
                        <option value="receber">A receber</option>
                        <option value="pagar">A pagar</option>
                        <option value="ambos">Ambos</option>
                    </select>
                </div>
                <div>
                    <label>Ordem de exibição</label>
                    <input type="number" name="ordem_exibicao" class="form-control" id="categoriaFormOrdem" value="0">
                </div>
                <div>
                    <label>Grupo DRE</label>
                    <select name="dre_grupo_id" class="form-select" id="categoriaFormGrupo">
                        <option value="">Sem grupo</option>
                        @foreach ($dreGrupos as $grupo)
                            <option value="{{ $grupo['id'] }}">{{ $grupo['nome'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Subgrupo DRE</label>
                    <select name="dre_subgrupo_id" class="form-select" id="categoriaFormSubgrupo">
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
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="dre_fixo_mensal_padrao" value="1" id="catFixoMensal">
                        <label class="form-check-label" for="catFixoMensal">Despesa fixa mensal</label>
                    </div>
                </div>
                <div class="field-actions">
                    <button type="submit" class="btn btn-primary" id="categoriaFormSubmit"><i class="bi bi-plus-lg me-1"></i>Criar categoria</button>
                    <button type="button" class="btn btn-outline-light d-none" id="categoriaFormCancel">Cancelar edição</button>
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
                                @if ($categoria['impacta_fluxo_caixa_padrao'] ?? false) <span class="badge text-bg-light border me-1">Caixa</span> @endif
                                @if ($categoria['dre_fixo_mensal_padrao'] ?? false) <span class="badge text-bg-light border">Fixo mensal</span> @endif
                            </td>
                            <td class="text-end">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-light"
                                    title="Editar categoria"
                                    data-categoria-edit
                                    data-categoria="{{ json_encode([
                                        'id' => $categoria['id'],
                                        'nome' => $categoria['nome'],
                                        'tipo' => $categoria['tipo'],
                                        'ordem_exibicao' => $categoria['ordem_exibicao'] ?? 0,
                                        'dre_grupo_id' => $categoria['dre_grupo']['id'] ?? '',
                                        'dre_subgrupo_id' => $categoria['dre_subgrupo']['id'] ?? '',
                                        'impacta_dre_padrao' => (bool) ($categoria['impacta_dre_padrao'] ?? false),
                                        'impacta_fluxo_caixa_padrao' => (bool) ($categoria['impacta_fluxo_caixa_padrao'] ?? false),
                                        'dre_fixo_mensal_padrao' => (bool) ($categoria['dre_fixo_mensal_padrao'] ?? false),
                                    ]) }}"
                                >
                                    <i class="bi bi-pencil"></i>
                                </button>
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
            <h4 class="surface-title mt-3 mb-3" id="grupoFormTitle">Novo grupo DRE</h4>
            <form method="post" action="{{ route('financeiro.configuracoes.grupos.save') }}" class="desktop-grid desktop-grid-three mb-4" id="grupoForm">
                @csrf
                <input type="hidden" name="id" id="grupoFormId" value="">
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" class="form-control" id="grupoFormNome" required>
                </div>
                <div>
                    <label>Descrição</label>
                    <input type="text" name="descricao" class="form-control" id="grupoFormDescricao">
                </div>
                <div>
                    <label>Ordem de exibição</label>
                    <input type="number" name="ordem_exibicao" class="form-control" id="grupoFormOrdem" value="0">
                </div>
                <div class="field-actions">
                    <button type="submit" class="btn btn-primary" id="grupoFormSubmit"><i class="bi bi-plus-lg me-1"></i>Criar grupo</button>
                    <button type="button" class="btn btn-outline-light d-none" id="grupoFormCancel">Cancelar edição</button>
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
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-light"
                                    title="Editar grupo"
                                    data-grupo-edit
                                    data-grupo="{{ json_encode([
                                        'id' => $grupo['id'],
                                        'nome' => $grupo['nome'],
                                        'descricao' => $grupo['descricao'] ?? '',
                                        'ordem_exibicao' => $grupo['ordem_exibicao'] ?? 0,
                                    ]) }}"
                                >
                                    <i class="bi bi-pencil"></i>
                                </button>
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
            <h4 class="surface-title mt-3 mb-3" id="subgrupoFormTitle">Novo subgrupo DRE</h4>
            <form method="post" action="{{ route('financeiro.configuracoes.subgrupos.save') }}" class="desktop-grid desktop-grid-three mb-4" id="subgrupoForm">
                @csrf
                <input type="hidden" name="id" id="subgrupoFormId" value="">
                <div>
                    <label>Grupo DRE</label>
                    <select name="grupo_id" class="form-select" id="subgrupoFormGrupo" required>
                        @foreach ($dreGrupos as $grupo)
                            <option value="{{ $grupo['id'] }}">{{ $grupo['nome'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" class="form-control" id="subgrupoFormNome" required>
                </div>
                <div>
                    <label>Ordem de exibição</label>
                    <input type="number" name="ordem_exibicao" class="form-control" id="subgrupoFormOrdem" value="0">
                </div>
                <div class="field-actions">
                    <button type="submit" class="btn btn-primary" id="subgrupoFormSubmit"><i class="bi bi-plus-lg me-1"></i>Criar subgrupo</button>
                    <button type="button" class="btn btn-outline-light d-none" id="subgrupoFormCancel">Cancelar edição</button>
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
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-light"
                                    title="Editar subgrupo"
                                    data-subgrupo-edit
                                    data-subgrupo="{{ json_encode([
                                        'id' => $subgrupo['id'],
                                        'nome' => $subgrupo['nome'],
                                        'grupo_id' => $subgrupo['grupo']['id'] ?? '',
                                        'ordem_exibicao' => $subgrupo['ordem_exibicao'] ?? 0,
                                    ]) }}"
                                >
                                    <i class="bi bi-pencil"></i>
                                </button>
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

        <div class="config-subpanel" data-config-subpanel="formas-pagamento">
            <h4 class="surface-title mt-3 mb-1">Nova forma de pagamento</h4>
            <p class="surface-subtitle mb-3">
                As formas cadastradas aqui aparecem na baixa de OS, nos lançamentos e nas formas padrão das contas.
                Marque "É cartão" para que a forma peça operadora, bandeira e parcelas, e calcule a taxa.
            </p>

            <form method="post" action="{{ route('financeiro.configuracoes.formas.save') }}" class="desktop-grid desktop-grid-three mb-4">
                @csrf
                <div>
                    <label>Nome</label>
                    <input type="text" name="nome" class="form-control" maxlength="60" required placeholder="Ex.: Cheque">
                </div>
                <div>
                    <label>Ordem de exibição</label>
                    <input type="number" name="ordem_exibicao" class="form-control" value="900" min="0" max="9999">
                </div>
                <div class="d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_cartao" id="formaIsCartao" value="1">
                        <label class="form-check-label" for="formaIsCartao">É cartão (usa operadora e taxa)</label>
                    </div>
                </div>
                <div class="field-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Criar forma</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Código</th>
                            <th>Tipo</th>
                            <th>Ordem</th>
                            <th>Situação</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($formasPagamento as $forma)
                        <tr>
                            <td>
                                <form method="post" action="{{ route('financeiro.configuracoes.formas.save') }}" class="d-flex gap-2 align-items-center">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $forma['id'] }}">
                                    <input type="hidden" name="ordem_exibicao" value="{{ $forma['ordem_exibicao'] }}">
                                    @unless ($forma['sistema'] ?? false)
                                        <input type="hidden" name="is_cartao" value="{{ ($forma['is_cartao'] ?? false) ? 1 : 0 }}">
                                    @endunless
                                    <input type="hidden" name="ativo" value="{{ ($forma['ativo'] ?? true) ? 1 : 0 }}">
                                    <input type="text" name="nome" class="form-control form-control-sm" value="{{ $forma['nome'] }}" maxlength="60" required>
                                    <button type="submit" class="btn btn-sm btn-outline-light" title="Salvar nome"><i class="bi bi-check-lg"></i></button>
                                </form>
                            </td>
                            <td><code>{{ $forma['codigo'] }}</code></td>
                            <td>
                                @if ($forma['is_cartao'] ?? false)
                                    <span class="badge text-bg-light border"><i class="bi bi-credit-card me-1"></i>Cartão</span>
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td>{{ $forma['ordem_exibicao'] }}</td>
                            <td>
                                @if ($forma['ativo'] ?? true)
                                    <span class="badge text-bg-success">Ativa</span>
                                @else
                                    <span class="badge text-bg-secondary">Inativa</span>
                                @endif
                                @if ($forma['sistema'] ?? false)
                                    <span class="badge text-bg-light border ms-1" title="Forma padrão do sistema: o código é fixo e ela não pode ser excluída.">Sistema</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if (($forma['codigo'] ?? '') === 'pix')
                                    <button type="button" class="btn btn-sm btn-outline-light" data-pix-keys-toggle title="Cadastrar as chaves Pix informadas ao cliente nas condições do orçamento.">
                                        <i class="bi bi-key me-1"></i>Chaves
                                        <span class="badge text-bg-light border ms-1">{{ count($chavesPix ?? []) }}</span>
                                    </button>
                                @endif

                                <form method="post" action="{{ route('financeiro.configuracoes.formas.save') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $forma['id'] }}">
                                    <input type="hidden" name="nome" value="{{ $forma['nome'] }}">
                                    <input type="hidden" name="ordem_exibicao" value="{{ $forma['ordem_exibicao'] }}">
                                    @unless ($forma['sistema'] ?? false)
                                        <input type="hidden" name="is_cartao" value="{{ ($forma['is_cartao'] ?? false) ? 1 : 0 }}">
                                    @endunless
                                    <input type="hidden" name="ativo" value="{{ ($forma['ativo'] ?? true) ? 0 : 1 }}">
                                    <button type="submit" class="btn btn-sm btn-outline-light" title="{{ ($forma['ativo'] ?? true) ? 'Desativar' : 'Ativar' }}">
                                        <i class="bi {{ ($forma['ativo'] ?? true) ? 'bi-toggle-on' : 'bi-toggle-off' }}"></i>
                                    </button>
                                </form>

                                @unless ($forma['sistema'] ?? false)
                                    <form method="post" action="{{ route('financeiro.configuracoes.formas.delete', $forma['id']) }}" data-confirm="Excluir esta forma de pagamento?" data-confirm-title="Excluir forma" data-confirm-button="Sim, excluir" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <section class="mt-4 {{ ($chavesPix ?? []) === [] ? 'd-none' : '' }}" data-pix-keys-panel aria-label="Chaves Pix">
                <h4 class="surface-title mb-1"><i class="bi bi-key me-2"></i>Chaves Pix</h4>
                <p class="surface-subtitle mb-3">
                    Cadastre aqui as chaves de recebimento. Elas aparecem automaticamente nas condições comerciais
                    do orçamento sempre que o Pix estiver entre as formas de pagamento aceitas — e no PDF enviado ao cliente.
                </p>

                <form method="post" action="{{ route('financeiro.configuracoes.pix.save') }}" class="desktop-grid desktop-grid-three mb-4">
                    @csrf
                    <div>
                        <label for="pixTipo">Tipo</label>
                        <select id="pixTipo" name="tipo" class="form-select" required>
                            @foreach (($chavesPixTipos ?? []) as $tipo)
                                <option value="{{ $tipo['value'] }}">{{ $tipo['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="pixChave">Chave</label>
                        <input type="text" id="pixChave" name="chave" class="form-control" maxlength="200" required placeholder="Ex.: 12.345.678/0001-90">
                    </div>
                    <div>
                        <label for="pixTitular">Titular</label>
                        <input type="text" id="pixTitular" name="titular" class="form-control" maxlength="160" placeholder="Nome que aparece para o cliente">
                    </div>
                    <div>
                        <label for="pixInstituicao">Instituição</label>
                        <input type="text" id="pixInstituicao" name="instituicao" class="form-control" maxlength="80" placeholder="Ex.: Banco Inter">
                    </div>
                    <div class="d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="principal" id="pixPrincipal" value="1">
                            <label class="form-check-label" for="pixPrincipal">Chave principal</label>
                        </div>
                    </div>
                    <div class="field-actions">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Cadastrar chave</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Chave</th>
                                <th>Titular</th>
                                <th>Instituição</th>
                                <th>Situação</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse (($chavesPix ?? []) as $chave)
                            <tr>
                                <td data-label="Tipo">{{ $chave['tipo_label'] ?? ucfirst((string) ($chave['tipo'] ?? '')) }}</td>
                                <td data-label="Chave"><code>{{ $chave['chave'] }}</code></td>
                                <td data-label="Titular">{{ ($chave['titular'] ?? '') !== '' ? $chave['titular'] : '—' }}</td>
                                <td data-label="Instituição">{{ ($chave['instituicao'] ?? '') !== '' ? $chave['instituicao'] : '—' }}</td>
                                <td data-label="Situação">
                                    @if ($chave['ativo'] ?? true)
                                        <span class="badge text-bg-success">Ativa</span>
                                    @else
                                        <span class="badge text-bg-secondary">Inativa</span>
                                    @endif
                                    @if ($chave['principal'] ?? false)
                                        <span class="badge text-bg-light border ms-1">Principal</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <form method="post" action="{{ route('financeiro.configuracoes.pix.save') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $chave['id'] }}">
                                        <input type="hidden" name="tipo" value="{{ $chave['tipo'] }}">
                                        <input type="hidden" name="chave" value="{{ $chave['chave'] }}">
                                        <input type="hidden" name="titular" value="{{ $chave['titular'] ?? '' }}">
                                        <input type="hidden" name="instituicao" value="{{ $chave['instituicao'] ?? '' }}">
                                        <input type="hidden" name="principal" value="{{ ($chave['principal'] ?? false) ? 1 : 0 }}">
                                        <input type="hidden" name="ativo" value="{{ ($chave['ativo'] ?? true) ? 0 : 1 }}">
                                        <button type="submit" class="btn btn-sm btn-outline-light" title="{{ ($chave['ativo'] ?? true) ? 'Desativar' : 'Ativar' }}">
                                            <i class="bi {{ ($chave['ativo'] ?? true) ? 'bi-toggle-on' : 'bi-toggle-off' }}"></i>
                                        </button>
                                    </form>

                                    @unless ($chave['principal'] ?? false)
                                        <form method="post" action="{{ route('financeiro.configuracoes.pix.save') }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $chave['id'] }}">
                                            <input type="hidden" name="tipo" value="{{ $chave['tipo'] }}">
                                            <input type="hidden" name="chave" value="{{ $chave['chave'] }}">
                                            <input type="hidden" name="titular" value="{{ $chave['titular'] ?? '' }}">
                                            <input type="hidden" name="instituicao" value="{{ $chave['instituicao'] ?? '' }}">
                                            <input type="hidden" name="principal" value="1">
                                            <input type="hidden" name="ativo" value="{{ ($chave['ativo'] ?? true) ? 1 : 0 }}">
                                            <button type="submit" class="btn btn-sm btn-outline-light" title="Tornar chave principal">
                                                <i class="bi bi-star"></i>
                                            </button>
                                        </form>
                                    @endunless

                                    <form method="post" action="{{ route('financeiro.configuracoes.pix.delete', $chave['id']) }}" data-confirm="Excluir esta chave Pix?" data-confirm-title="Excluir chave Pix" data-confirm-button="Sim, excluir" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-secondary">Nenhuma chave Pix cadastrada.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
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

            // O cadastro de chaves Pix mora dentro da forma "Pix": abre pelo
            // botão da própria linha, e já vem aberto quando há chaves.
            const pixToggle = document.querySelector('[data-pix-keys-toggle]');
            const pixPanel = document.querySelector('[data-pix-keys-panel]');

            if (pixToggle && pixPanel) {
                pixToggle.addEventListener('click', () => {
                    pixPanel.classList.toggle('d-none');

                    if (!pixPanel.classList.contains('d-none')) {
                        pixPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                });
            }

            // Edição de Categorias, Grupos DRE e Subgrupos DRE: os três só têm
            // cadastro "Novo X" + excluir na origem. Em vez de duplicar o form
            // por linha, o botão de editar preenche o form já existente (que
            // serve tanto para criar quanto atualizar — o controller decide
            // pelo campo "id" oculto) e o "Cancelar edição" devolve ao modo
            // de criação.
            const setupCatalogEdit = ({ editSelector, dataAttr, form, idField, submitButton, submitCreateHtml, submitEditHtml, cancelButton, title, titleCreateText, titleEditText, fields }) => {
                if (!form || !idField || !submitButton || !cancelButton) { return; }

                const enterEditMode = (data) => {
                    idField.value = data.id ?? '';

                    Object.entries(fields).forEach(([key, el]) => {
                        if (!el) { return; }
                        const value = data[key] ?? '';
                        if (el.type === 'checkbox') {
                            el.checked = Boolean(value);
                        } else {
                            el.value = String(value);
                        }
                    });

                    submitButton.innerHTML = submitEditHtml;
                    cancelButton.classList.remove('d-none');
                    if (title) { title.textContent = titleEditText; }
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                };

                const exitEditMode = () => {
                    form.reset();
                    idField.value = '';
                    submitButton.innerHTML = submitCreateHtml;
                    cancelButton.classList.add('d-none');
                    if (title) { title.textContent = titleCreateText; }
                };

                document.querySelectorAll(editSelector).forEach((button) => {
                    button.addEventListener('click', () => {
                        try {
                            enterEditMode(JSON.parse(button.getAttribute(dataAttr) || '{}'));
                        } catch (e) {
                            // Dado malformado no atributo: ignora silenciosamente,
                            // usuário continua podendo criar normalmente.
                        }
                    });
                });

                cancelButton.addEventListener('click', exitEditMode);
            };

            setupCatalogEdit({
                editSelector: '[data-categoria-edit]',
                dataAttr: 'data-categoria',
                form: document.getElementById('categoriaForm'),
                idField: document.getElementById('categoriaFormId'),
                submitButton: document.getElementById('categoriaFormSubmit'),
                submitCreateHtml: '<i class="bi bi-plus-lg me-1"></i>Criar categoria',
                submitEditHtml: '<i class="bi bi-save2 me-1"></i>Salvar categoria',
                cancelButton: document.getElementById('categoriaFormCancel'),
                title: document.getElementById('categoriaFormTitle'),
                titleCreateText: 'Nova categoria',
                titleEditText: 'Editar categoria',
                fields: {
                    nome: document.getElementById('categoriaFormNome'),
                    tipo: document.getElementById('categoriaFormTipo'),
                    ordem_exibicao: document.getElementById('categoriaFormOrdem'),
                    dre_grupo_id: document.getElementById('categoriaFormGrupo'),
                    dre_subgrupo_id: document.getElementById('categoriaFormSubgrupo'),
                    impacta_dre_padrao: document.getElementById('catImpactaDre'),
                    impacta_fluxo_caixa_padrao: document.getElementById('catImpactaFluxo'),
                    dre_fixo_mensal_padrao: document.getElementById('catFixoMensal'),
                },
            });

            setupCatalogEdit({
                editSelector: '[data-grupo-edit]',
                dataAttr: 'data-grupo',
                form: document.getElementById('grupoForm'),
                idField: document.getElementById('grupoFormId'),
                submitButton: document.getElementById('grupoFormSubmit'),
                submitCreateHtml: '<i class="bi bi-plus-lg me-1"></i>Criar grupo',
                submitEditHtml: '<i class="bi bi-save2 me-1"></i>Salvar grupo',
                cancelButton: document.getElementById('grupoFormCancel'),
                title: document.getElementById('grupoFormTitle'),
                titleCreateText: 'Novo grupo DRE',
                titleEditText: 'Editar grupo DRE',
                fields: {
                    nome: document.getElementById('grupoFormNome'),
                    descricao: document.getElementById('grupoFormDescricao'),
                    ordem_exibicao: document.getElementById('grupoFormOrdem'),
                },
            });

            setupCatalogEdit({
                editSelector: '[data-subgrupo-edit]',
                dataAttr: 'data-subgrupo',
                form: document.getElementById('subgrupoForm'),
                idField: document.getElementById('subgrupoFormId'),
                submitButton: document.getElementById('subgrupoFormSubmit'),
                submitCreateHtml: '<i class="bi bi-plus-lg me-1"></i>Criar subgrupo',
                submitEditHtml: '<i class="bi bi-save2 me-1"></i>Salvar subgrupo',
                cancelButton: document.getElementById('subgrupoFormCancel'),
                title: document.getElementById('subgrupoFormTitle'),
                titleCreateText: 'Novo subgrupo DRE',
                titleEditText: 'Editar subgrupo DRE',
                fields: {
                    grupo_id: document.getElementById('subgrupoFormGrupo'),
                    nome: document.getElementById('subgrupoFormNome'),
                    ordem_exibicao: document.getElementById('subgrupoFormOrdem'),
                },
            });
        })();
    </script>
@endsection
