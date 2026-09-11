/**
 * Equipamentos > Catálogo (tipos/marcas/modelos) — abre os modais de
 * novo/renomear já preenchidos e alterna required/disabled dos campos de
 * escopo (tipo/marca) que só fazem sentido na criação, nunca ao renomear.
 * Sem fetch: os forms são POST/PATCH clássicos, a página recarrega no submit
 * (mesmo idioma de servicos/estoque-categorias.js).
 */
(function () {
    'use strict';

    const resetScopeFields = (selector, enabled) => {
        document.querySelectorAll(selector).forEach((wrapper) => {
            wrapper.classList.toggle('d-none', !enabled);

            const field = wrapper.querySelector('select, input');
            if (field) {
                field.disabled = !enabled;
            }
        });
    };

    const setupCatalogModal = ({ newTrigger, editTrigger, form, idField, nameField, titleEl, submitEl, scopeSelector, titleCreateText, titleEditText, submitCreateText, submitEditText }) => {
        if (!form || !idField || !nameField) {
            return;
        }

        document.querySelectorAll(newTrigger).forEach((button) => {
            button.addEventListener('click', () => {
                form.reset();
                idField.value = '';
                if (scopeSelector) {
                    resetScopeFields(scopeSelector, true);
                }
                if (titleEl) {
                    titleEl.textContent = titleCreateText;
                }
                if (submitEl) {
                    submitEl.textContent = submitCreateText;
                }
            });
        });

        document.querySelectorAll(editTrigger).forEach((button) => {
            button.addEventListener('click', () => {
                let data = {};
                try {
                    data = JSON.parse(button.getAttribute('data-catalog-item') || '{}');
                } catch (error) {
                    // Dado malformado no atributo: ignora, operador continua podendo criar normalmente.
                    return;
                }

                form.reset();
                idField.value = data.id ?? '';
                nameField.value = data.nome ?? '';
                if (scopeSelector) {
                    resetScopeFields(scopeSelector, false);
                }
                if (titleEl) {
                    titleEl.textContent = titleEditText;
                }
                if (submitEl) {
                    submitEl.textContent = submitEditText;
                }
            });
        });
    };

    setupCatalogModal({
        newTrigger: '[data-catalog-new="modelos"]',
        editTrigger: '[data-catalog-edit="modelo"]',
        form: document.getElementById('catalogModeloForm'),
        idField: document.getElementById('catalogModeloFormId'),
        nameField: document.getElementById('catalogModeloFormNome'),
        titleEl: document.getElementById('catalogModeloModalTitle'),
        submitEl: document.getElementById('catalogModeloFormSubmit'),
        scopeSelector: '[data-catalog-model-scope]',
        titleCreateText: 'Novo modelo',
        titleEditText: 'Renomear modelo',
        submitCreateText: 'Salvar modelo',
        submitEditText: 'Salvar',
    });

    setupCatalogModal({
        newTrigger: '[data-catalog-new="marcas"]',
        editTrigger: '[data-catalog-edit="marca"]',
        form: document.getElementById('catalogMarcaForm'),
        idField: document.getElementById('catalogMarcaFormId'),
        nameField: document.getElementById('catalogMarcaFormNome'),
        titleEl: document.getElementById('catalogMarcaModalTitle'),
        submitEl: document.getElementById('catalogMarcaFormSubmit'),
        scopeSelector: '[data-catalog-brand-scope]',
        titleCreateText: 'Nova marca',
        titleEditText: 'Renomear marca',
        submitCreateText: 'Salvar marca',
        submitEditText: 'Salvar',
    });

    setupCatalogModal({
        newTrigger: '[data-catalog-new="tipos"]',
        editTrigger: '[data-catalog-edit="tipo"]',
        form: document.getElementById('catalogTipoForm'),
        idField: document.getElementById('catalogTipoFormId'),
        nameField: document.getElementById('catalogTipoFormNome'),
        titleEl: document.getElementById('catalogTipoModalTitle'),
        submitEl: document.getElementById('catalogTipoFormSubmit'),
        scopeSelector: null,
        titleCreateText: 'Novo tipo',
        titleEditText: 'Renomear tipo',
        submitCreateText: 'Salvar tipo',
        submitEditText: 'Salvar',
    });
})();
