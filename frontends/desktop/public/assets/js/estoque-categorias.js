/**
 * Estoque > Mais ações > Gerenciar categorias — CRUD de Grupo → Categoria →
 * Subcategoria, e o cascateamento dos 3 filtros da listagem.
 */
(function () {
    'use strict';

    const config = window.__DESKTOP_ESTOQUE_INDEX || {};

    // --- Cascata dos filtros da listagem (Grupo → Categoria → Subcategoria) ---
    const cascade = config.filterCascade || {};
    const grupoSelect = document.getElementById(cascade.grupoSelect || 'tipo_equipamento_id');
    const categoriaSelect = document.getElementById(cascade.categoriaSelect || 'estoque_categoria_id');
    const subcategoriaSelect = document.getElementById(cascade.subcategoriaSelect || 'estoque_subcategoria_id');

    if (window.DesktopUi && typeof window.DesktopUi.bindOptionCascade === 'function') {
        window.DesktopUi.bindOptionCascade(grupoSelect, categoriaSelect);
        window.DesktopUi.bindOptionCascade(categoriaSelect, subcategoriaSelect);
    }

    // --- CRUD do modal "Gerenciar categorias" ---------------------------------
    // Mesmo idioma de financeiro/configuracoes.blade.php: um form único por
    // nível serve tanto para criar quanto editar (campo "id" oculto decide no
    // controller), o botão de editar preenche o form a partir do JSON na
    // própria linha da tabela, e "Cancelar edição" devolve ao modo de criação.
    const setupCatalogEdit = ({ editSelector, dataAttr, form, idField, submitButton, submitCreateHtml, submitEditHtml, cancelButton, title, titleCreateText, titleEditText, fields }) => {
        if (!form || !idField || !submitButton || !cancelButton) {
            return;
        }

        const enterEditMode = (data) => {
            idField.value = data.id ?? '';

            Object.entries(fields).forEach(([key, el]) => {
                if (!el) {
                    return;
                }

                const value = data[key] ?? '';
                if (el.type === 'checkbox') {
                    el.checked = Boolean(value);
                } else {
                    el.value = String(value);
                }
            });

            submitButton.innerHTML = submitEditHtml;
            cancelButton.classList.remove('d-none');
            if (title) {
                title.textContent = titleEditText;
            }

            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        const exitEditMode = () => {
            form.reset();
            idField.value = '';
            submitButton.innerHTML = submitCreateHtml;
            cancelButton.classList.add('d-none');
            if (title) {
                title.textContent = titleCreateText;
            }
        };

        document.querySelectorAll(editSelector).forEach((button) => {
            button.addEventListener('click', () => {
                try {
                    enterEditMode(JSON.parse(button.getAttribute(dataAttr) || '{}'));
                } catch (error) {
                    // Dado malformado no atributo: ignora, operador continua
                    // podendo criar normalmente.
                }
            });
        });

        cancelButton.addEventListener('click', exitEditMode);
    };

    setupCatalogEdit({
        editSelector: '[data-estoque-grupo-edit]',
        dataAttr: 'data-estoque-grupo',
        form: document.getElementById('estoqueGrupoForm'),
        idField: document.getElementById('estoqueGrupoFormId'),
        submitButton: document.getElementById('estoqueGrupoFormSubmit'),
        submitCreateHtml: '<i class="bi bi-plus-lg me-1"></i>Criar grupo',
        submitEditHtml: '<i class="bi bi-save2 me-1"></i>Salvar grupo',
        cancelButton: document.getElementById('estoqueGrupoFormCancel'),
        title: document.getElementById('estoqueGrupoFormTitle'),
        titleCreateText: 'Novo grupo',
        titleEditText: 'Editar grupo',
        fields: {
            nome: document.getElementById('estoqueGrupoFormNome'),
            ativo: document.getElementById('estoqueGrupoFormAtivo'),
        },
    });

    setupCatalogEdit({
        editSelector: '[data-estoque-categoria-edit]',
        dataAttr: 'data-estoque-categoria',
        form: document.getElementById('estoqueCategoriaForm'),
        idField: document.getElementById('estoqueCategoriaFormId'),
        submitButton: document.getElementById('estoqueCategoriaFormSubmit'),
        submitCreateHtml: '<i class="bi bi-plus-lg me-1"></i>Criar categoria',
        submitEditHtml: '<i class="bi bi-save2 me-1"></i>Salvar categoria',
        cancelButton: document.getElementById('estoqueCategoriaFormCancel'),
        title: document.getElementById('estoqueCategoriaFormTitle'),
        titleCreateText: 'Nova categoria',
        titleEditText: 'Editar categoria',
        fields: {
            tipo_equipamento_id: document.getElementById('estoqueCategoriaFormGrupo'),
            nome: document.getElementById('estoqueCategoriaFormNome'),
            ordem: document.getElementById('estoqueCategoriaFormOrdem'),
            ativo: document.getElementById('estoqueCategoriaFormAtivo'),
        },
    });

    setupCatalogEdit({
        editSelector: '[data-estoque-subcategoria-edit]',
        dataAttr: 'data-estoque-subcategoria',
        form: document.getElementById('estoqueSubcategoriaForm'),
        idField: document.getElementById('estoqueSubcategoriaFormId'),
        submitButton: document.getElementById('estoqueSubcategoriaFormSubmit'),
        submitCreateHtml: '<i class="bi bi-plus-lg me-1"></i>Criar subcategoria',
        submitEditHtml: '<i class="bi bi-save2 me-1"></i>Salvar subcategoria',
        cancelButton: document.getElementById('estoqueSubcategoriaFormCancel'),
        title: document.getElementById('estoqueSubcategoriaFormTitle'),
        titleCreateText: 'Nova subcategoria',
        titleEditText: 'Editar subcategoria',
        fields: {
            categoria_id: document.getElementById('estoqueSubcategoriaFormCategoria'),
            nome: document.getElementById('estoqueSubcategoriaFormNome'),
            ordem: document.getElementById('estoqueSubcategoriaFormOrdem'),
            ativo: document.getElementById('estoqueSubcategoriaFormAtivo'),
        },
    });
})();
