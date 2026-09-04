@php
    $gruposAdmin = is_array($gruposAdmin ?? null) ? $gruposAdmin : [];
    $estoqueCategoriasAdmin = is_array($estoqueCategoriasAdmin ?? null) ? $estoqueCategoriasAdmin : [];
    $estoqueSubcategoriasAdmin = is_array($estoqueSubcategoriasAdmin ?? null) ? $estoqueSubcategoriasAdmin : [];
    $gruposById = collect($gruposAdmin)->keyBy('id');
@endphp

{{--
    Gerenciar categorias (specs: taxonomia de estoque Grupo → Categoria →
    Subcategoria). Três abas, cada uma com o mesmo padrão de
    financeiro/configuracoes.blade.php: um form único que serve tanto para
    criar quanto editar (campo "id" oculto decide), tabela logo abaixo, editar
    preenche o form (setupCatalogEdit em estoque-categorias.js), excluir é
    sempre desativar — nunca DELETE de verdade, ver EstoqueCatalogController.
--}}
<div class="modal fade" id="estoqueCategoriasModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Gerenciar categorias</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="estoqueCategoriasTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="estoqueCategoriasTabGruposBtn" data-bs-toggle="tab"
                                data-bs-target="#estoqueCategoriasTabGrupos" type="button" role="tab">
                            <i class="bi bi-tag me-1"></i>Grupos
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="estoqueCategoriasTabCategoriasBtn" data-bs-toggle="tab"
                                data-bs-target="#estoqueCategoriasTabCategorias" type="button" role="tab">
                            <i class="bi bi-tags me-1"></i>Categorias
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="estoqueCategoriasTabSubcategoriasBtn" data-bs-toggle="tab"
                                data-bs-target="#estoqueCategoriasTabSubcategorias" type="button" role="tab">
                            <i class="bi bi-diagram-2 me-1"></i>Subcategorias
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="estoqueCategoriasTabsContent">
                    {{-- GRUPOS --}}
                    <div class="tab-pane fade show active" id="estoqueCategoriasTabGrupos" role="tabpanel">
                        <p class="text-secondary small mb-3">
                            Grupo é o mesmo catálogo "Tipo de equipamento" usado em OS, serviços e equipamentos de
                            cliente — desativar um grupo aqui também o remove dessas outras telas.
                        </p>

                        <h6 class="mb-3" id="estoqueGrupoFormTitle">Novo grupo</h6>
                        <form method="post" action="{{ route('estoque.grupos.save') }}" class="desktop-grid desktop-grid-two mb-4" id="estoqueGrupoForm">
                            @csrf
                            <input type="hidden" name="id" id="estoqueGrupoFormId" value="">
                            <div>
                                <label>Nome</label>
                                <input type="text" name="nome" class="form-control" id="estoqueGrupoFormNome" maxlength="120" required>
                            </div>
                            <div class="d-flex align-items-end gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="ativo" value="1" id="estoqueGrupoFormAtivo" checked>
                                    <label class="form-check-label" for="estoqueGrupoFormAtivo">Ativo</label>
                                </div>
                                <div class="field-actions">
                                    <button type="submit" class="btn btn-primary" id="estoqueGrupoFormSubmit"><i class="bi bi-plus-lg me-1"></i>Criar grupo</button>
                                    <button type="button" class="btn btn-outline-light d-none" id="estoqueGrupoFormCancel">Cancelar edição</button>
                                </div>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-stack align-middle">
                                <thead>
                                <tr><th>Nome</th><th>Status</th><th class="text-end">Ações</th></tr>
                                </thead>
                                <tbody>
                                @forelse ($gruposAdmin as $grupo)
                                    <tr>
                                        <td>{{ $grupo['nome'] ?? '' }}</td>
                                        <td>
                                            @if ($grupo['ativo'] ?? true)
                                                <span class="badge text-bg-light border">Ativo</span>
                                            @else
                                                <span class="badge text-bg-light border text-secondary">Inativo</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-light" title="Editar grupo"
                                                    data-estoque-grupo-edit
                                                    data-estoque-grupo="{{ json_encode(['id' => $grupo['id'] ?? 0, 'nome' => $grupo['nome'] ?? '', 'ativo' => (bool) ($grupo['ativo'] ?? true)]) }}">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            @if ($grupo['ativo'] ?? true)
                                                <form method="post" action="{{ route('estoque.grupos.delete', $grupo['id'] ?? 0) }}" data-confirm="Desativar este grupo? Ele some do cadastro de peças, OS, serviços e equipamentos." data-confirm-title="Desativar grupo" data-confirm-button="Sim, desativar" class="d-inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-secondary">Nenhum grupo cadastrado.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- CATEGORIAS --}}
                    <div class="tab-pane fade" id="estoqueCategoriasTabCategorias" role="tabpanel">
                        <h6 class="mb-3" id="estoqueCategoriaFormTitle">Nova categoria</h6>
                        <form method="post" action="{{ route('estoque.categorias.save') }}" class="desktop-grid desktop-grid-three mb-4" id="estoqueCategoriaForm">
                            @csrf
                            <input type="hidden" name="id" id="estoqueCategoriaFormId" value="">
                            <div>
                                <label>Grupo</label>
                                <select name="tipo_equipamento_id" class="form-select" id="estoqueCategoriaFormGrupo" required>
                                    <option value="">Selecione</option>
                                    @foreach ($gruposAdmin as $grupo)
                                        <option value="{{ $grupo['id'] }}">{{ $grupo['nome'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label>Nome</label>
                                <input type="text" name="nome" class="form-control" id="estoqueCategoriaFormNome" maxlength="120" required>
                            </div>
                            <div>
                                <label>Ordem de exibição</label>
                                <input type="number" name="ordem" class="form-control" id="estoqueCategoriaFormOrdem" value="0">
                            </div>
                            <div class="d-flex align-items-end gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="ativo" value="1" id="estoqueCategoriaFormAtivo" checked>
                                    <label class="form-check-label" for="estoqueCategoriaFormAtivo">Ativo</label>
                                </div>
                                <div class="field-actions">
                                    <button type="submit" class="btn btn-primary" id="estoqueCategoriaFormSubmit"><i class="bi bi-plus-lg me-1"></i>Criar categoria</button>
                                    <button type="button" class="btn btn-outline-light d-none" id="estoqueCategoriaFormCancel">Cancelar edição</button>
                                </div>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-stack align-middle">
                                <thead>
                                <tr><th>Nome</th><th>Grupo</th><th>Ordem</th><th>Status</th><th class="text-end">Ações</th></tr>
                                </thead>
                                <tbody>
                                @forelse ($estoqueCategoriasAdmin as $categoria)
                                    <tr>
                                        <td>{{ $categoria['nome'] ?? '' }}</td>
                                        <td>{{ $categoria['tipo_equipamento']['nome'] ?? '-' }}</td>
                                        <td>{{ $categoria['ordem'] ?? 0 }}</td>
                                        <td>
                                            @if ($categoria['ativo'] ?? true)
                                                <span class="badge text-bg-light border">Ativo</span>
                                            @else
                                                <span class="badge text-bg-light border text-secondary">Inativo</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-light" title="Editar categoria"
                                                    data-estoque-categoria-edit
                                                    data-estoque-categoria="{{ json_encode([
                                                        'id' => $categoria['id'] ?? 0,
                                                        'tipo_equipamento_id' => $categoria['tipo_equipamento_id'] ?? '',
                                                        'nome' => $categoria['nome'] ?? '',
                                                        'ordem' => $categoria['ordem'] ?? 0,
                                                        'ativo' => (bool) ($categoria['ativo'] ?? true),
                                                    ]) }}">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            @if ($categoria['ativo'] ?? true)
                                                <form method="post" action="{{ route('estoque.categorias.delete', $categoria['id'] ?? 0) }}" data-confirm="Desativar esta categoria?" data-confirm-title="Desativar categoria" data-confirm-button="Sim, desativar" class="d-inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-secondary">Nenhuma categoria cadastrada.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- SUBCATEGORIAS --}}
                    <div class="tab-pane fade" id="estoqueCategoriasTabSubcategorias" role="tabpanel">
                        <h6 class="mb-3" id="estoqueSubcategoriaFormTitle">Nova subcategoria</h6>
                        <form method="post" action="{{ route('estoque.subcategorias.save') }}" class="desktop-grid desktop-grid-three mb-4" id="estoqueSubcategoriaForm">
                            @csrf
                            <input type="hidden" name="id" id="estoqueSubcategoriaFormId" value="">
                            <div>
                                <label>Categoria</label>
                                <select name="categoria_id" class="form-select" id="estoqueSubcategoriaFormCategoria" required>
                                    <option value="">Selecione</option>
                                    @foreach ($estoqueCategoriasAdmin as $categoria)
                                        <option value="{{ $categoria['id'] }}">
                                            {{ $gruposById[$categoria['tipo_equipamento_id']]['nome'] ?? '?' }} / {{ $categoria['nome'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label>Nome</label>
                                <input type="text" name="nome" class="form-control" id="estoqueSubcategoriaFormNome" maxlength="120" required>
                            </div>
                            <div>
                                <label>Ordem de exibição</label>
                                <input type="number" name="ordem" class="form-control" id="estoqueSubcategoriaFormOrdem" value="0">
                            </div>
                            <div class="d-flex align-items-end gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="ativo" value="1" id="estoqueSubcategoriaFormAtivo" checked>
                                    <label class="form-check-label" for="estoqueSubcategoriaFormAtivo">Ativo</label>
                                </div>
                                <div class="field-actions">
                                    <button type="submit" class="btn btn-primary" id="estoqueSubcategoriaFormSubmit"><i class="bi bi-plus-lg me-1"></i>Criar subcategoria</button>
                                    <button type="button" class="btn btn-outline-light d-none" id="estoqueSubcategoriaFormCancel">Cancelar edição</button>
                                </div>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-stack align-middle">
                                <thead>
                                <tr><th>Nome</th><th>Categoria</th><th>Ordem</th><th>Status</th><th class="text-end">Ações</th></tr>
                                </thead>
                                <tbody>
                                @forelse ($estoqueSubcategoriasAdmin as $subcategoria)
                                    <tr>
                                        <td>{{ $subcategoria['nome'] ?? '' }}</td>
                                        <td>
                                            {{ $gruposById[$subcategoria['categoria']['tipo_equipamento_id'] ?? null]['nome'] ?? '?' }}
                                            / {{ $subcategoria['categoria']['nome'] ?? '-' }}
                                        </td>
                                        <td>{{ $subcategoria['ordem'] ?? 0 }}</td>
                                        <td>
                                            @if ($subcategoria['ativo'] ?? true)
                                                <span class="badge text-bg-light border">Ativo</span>
                                            @else
                                                <span class="badge text-bg-light border text-secondary">Inativo</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-light" title="Editar subcategoria"
                                                    data-estoque-subcategoria-edit
                                                    data-estoque-subcategoria="{{ json_encode([
                                                        'id' => $subcategoria['id'] ?? 0,
                                                        'categoria_id' => $subcategoria['categoria_id'] ?? '',
                                                        'nome' => $subcategoria['nome'] ?? '',
                                                        'ordem' => $subcategoria['ordem'] ?? 0,
                                                        'ativo' => (bool) ($subcategoria['ativo'] ?? true),
                                                    ]) }}">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            @if ($subcategoria['ativo'] ?? true)
                                                <form method="post" action="{{ route('estoque.subcategorias.delete', $subcategoria['id'] ?? 0) }}" data-confirm="Desativar esta subcategoria?" data-confirm-title="Desativar subcategoria" data-confirm-button="Sim, desativar" class="d-inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-secondary">Nenhuma subcategoria cadastrada.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
