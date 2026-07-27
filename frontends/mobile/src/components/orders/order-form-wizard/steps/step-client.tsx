'use client';

import { useState } from 'react';
import { ApiError } from '@/lib/api';
import { getClientDetail, searchClients } from '@/lib/orders';
import type { ClientDetail, ClientSearchResult, ClientUpdatePayload, NovoClientePayload } from '@/lib/types';
import {
  isWizardClientComplete,
  type WizardMode,
} from '@/components/orders/order-form-wizard/wizard-state';
import { SearchSelect } from '@/components/orders/order-form-wizard/search-select';

type StepClientProps = {
  mode: WizardMode;
  cliente: ClientSearchResult | null;
  pendingNewClient: NovoClientePayload | null;
  pendingClientUpdate?: ClientUpdatePayload | null;
  onSelectCliente: (cliente: ClientSearchResult | null) => void;
  onChangePendingNewClient: (payload: NovoClientePayload | null) => void;
  onChangePendingClientUpdate?: (payload: ClientUpdatePayload | null) => void;
  canEditExisting?: boolean;
  disabled?: boolean;
};

const EMPTY_NEW_CLIENT: NovoClientePayload = { nome_razao: '', telefone1: '' };

function clientDetailToUpdate(detail: ClientDetail): ClientUpdatePayload {
  return {
    tipo_pessoa: detail.tipo_pessoa || 'fisica',
    nome_razao: detail.nome_razao,
    cpf_cnpj: detail.cpf_cnpj,
    rg_ie: detail.rg_ie,
    email: detail.email,
    telefone1: detail.telefone1,
    telefone2: detail.telefone2,
    nome_contato: detail.nome_contato,
    telefone_contato: detail.telefone_contato,
    cep: detail.cep,
    endereco: detail.endereco,
    numero: detail.numero,
    complemento: detail.complemento,
    referencia: detail.referencia,
    bairro: detail.bairro,
    cidade: detail.cidade,
    uf: detail.uf,
    observacoes: detail.observacoes,
    status_cadastro: detail.status_cadastro || 'completo',
    preferencia_contato: detail.preferencia_contato,
  };
}

export function StepClient({
  mode,
  cliente,
  pendingNewClient,
  pendingClientUpdate = null,
  onSelectCliente,
  onChangePendingNewClient,
  onChangePendingClientUpdate = () => undefined,
  canEditExisting = false,
  disabled = false,
}: StepClientProps) {
  const [view, setView] = useState<'buscar' | 'novo'>(cliente ? 'buscar' : pendingNewClient ? 'novo' : 'buscar');
  const [editingExisting, setEditingExisting] = useState(false);
  const [loadingExisting, setLoadingExisting] = useState(false);
  const [existingError, setExistingError] = useState<string | null>(null);

  const switchToSearch = (): void => {
    setView('buscar');
    setEditingExisting(false);
    onChangePendingNewClient(null);
  };

  const switchToNew = (): void => {
    setView('novo');
    setEditingExisting(false);
    onSelectCliente(null);
    if (!pendingNewClient) {
      onChangePendingNewClient(EMPTY_NEW_CLIENT);
    }
  };

  const updateField = (field: keyof ClientUpdatePayload, value: string): void => {
    if (editingExisting && cliente) {
      const base = pendingClientUpdate ?? {
        ...EMPTY_NEW_CLIENT,
        tipo_pessoa: cliente.tipo_pessoa || 'fisica',
        status_cadastro: cliente.status_cadastro || 'completo',
      };
      onChangePendingClientUpdate({ ...base, [field]: value });
      return;
    }

    onChangePendingNewClient({ ...(pendingNewClient ?? EMPTY_NEW_CLIENT), [field]: value });
  };

  const openExistingEditor = async (): Promise<void> => {
    if (!cliente || !canEditExisting || disabled) {
      return;
    }

    setExistingError(null);
    if (pendingClientUpdate) {
      setEditingExisting(true);
      return;
    }

    setLoadingExisting(true);
    try {
      const detail = await getClientDetail(cliente.id);
      onChangePendingClientUpdate(clientDetailToUpdate(detail));
      setEditingExisting(true);
    } catch (error) {
      setExistingError(error instanceof ApiError ? error.message : 'Não foi possível carregar os dados do cliente.');
    } finally {
      setLoadingExisting(false);
    }
  };

  const activePayload = editingExisting ? pendingClientUpdate : pendingNewClient;

  return (
    <section className="section">
      <div className="section__header">
        <h3 className="section__title">Cliente</h3>
      </div>

      {mode === 'create' ? (
        <div className="toolbar" style={{ marginBottom: 16 }}>
          <div className="toolbar__group">
            <button
              type="button"
              className={view === 'buscar' ? 'button button--primary button-small' : 'button button--soft button-small'}
              onClick={switchToSearch}
              disabled={disabled}
            >
              Cliente já cadastrado
            </button>
            <button
              type="button"
              className={view === 'novo' ? 'button button--primary button-small' : 'button button--soft button-small'}
              onClick={switchToNew}
              disabled={disabled}
            >
              Cliente novo
            </button>
          </div>
        </div>
      ) : null}

      {(view === 'buscar' || mode === 'edit') && !editingExisting ? (
        <>
          <SearchSelect<ClientSearchResult>
            label="Buscar cliente"
            placeholder="Nome, telefone, e-mail ou CPF/CNPJ"
            value={cliente}
            onSelect={(value) => {
              setEditingExisting(false);
              onSelectCliente(value);
            }}
            fetchOptions={searchClients}
            getOptionKey={(option) => option.id}
            getOptionLabel={(option) => option.nome_razao}
            getOptionSubtitle={(option) => [option.telefone1, option.cidade].filter(Boolean).join(' • ') || null}
            renderSelectedActions={
              mode === 'create'
                ? () => (
                    <button
                      type="button"
                      className="button button--ghost button-small"
                      onClick={() => void openExistingEditor()}
                      disabled={disabled || loadingExisting || !canEditExisting}
                      title={!canEditExisting ? 'Sem permissão para editar clientes.' : undefined}
                    >
                      {loadingExisting ? 'Carregando...' : 'Editar'}
                    </button>
                  )
                : undefined
            }
            disabled={disabled}
          />
          {existingError ? (
            <div className="notice notice--danger">
              <span>{existingError}</span>
            </div>
          ) : null}
        </>
      ) : (
        <div className="form">
          {editingExisting ? (
            <div className="toolbar">
              <div>
                <strong>Editar cliente selecionado</strong>
                <div className="muted">As alterações serão aplicadas somente ao salvar a OS.</div>
              </div>
              <button
                type="button"
                className="button button--soft button-small"
                onClick={() => setEditingExisting(false)}
                disabled={disabled}
              >
                Concluir edição
              </button>
            </div>
          ) : null}

          <label className="field">
            <span className="field__label">Nome / razão social *</span>
            <input
              className="input"
              value={activePayload?.nome_razao ?? ''}
              onChange={(event) => updateField('nome_razao', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Telefone *</span>
            <input
              className="input"
              value={activePayload?.telefone1 ?? ''}
              onChange={(event) => updateField('telefone1', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Telefone secundário</span>
            <input
              className="input"
              value={activePayload?.telefone2 ?? ''}
              onChange={(event) => updateField('telefone2', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">E-mail</span>
            <input
              className="input"
              type="email"
              value={activePayload?.email ?? ''}
              onChange={(event) => updateField('email', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">CPF/CNPJ</span>
            <input
              className="input"
              value={activePayload?.cpf_cnpj ?? ''}
              onChange={(event) => updateField('cpf_cnpj', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Nome do contato</span>
            <input
              className="input"
              value={activePayload?.nome_contato ?? ''}
              onChange={(event) => updateField('nome_contato', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Telefone do contato</span>
            <input
              className="input"
              value={activePayload?.telefone_contato ?? ''}
              onChange={(event) => updateField('telefone_contato', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">CEP</span>
            <input
              className="input"
              value={activePayload?.cep ?? ''}
              onChange={(event) => updateField('cep', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Endereço</span>
            <input
              className="input"
              value={activePayload?.endereco ?? ''}
              onChange={(event) => updateField('endereco', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Número</span>
            <input
              className="input"
              value={activePayload?.numero ?? ''}
              onChange={(event) => updateField('numero', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Complemento</span>
            <input
              className="input"
              value={activePayload?.complemento ?? ''}
              onChange={(event) => updateField('complemento', event.target.value)}
              disabled={disabled}
            />
          </label>

          {editingExisting ? (
            <label className="field">
              <span className="field__label">Referência</span>
              <input
                className="input"
                value={pendingClientUpdate?.referencia ?? ''}
                onChange={(event) => updateField('referencia', event.target.value)}
                disabled={disabled}
              />
            </label>
          ) : null}

          <label className="field">
            <span className="field__label">Bairro</span>
            <input
              className="input"
              value={activePayload?.bairro ?? ''}
              onChange={(event) => updateField('bairro', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Cidade</span>
            <input
              className="input"
              value={activePayload?.cidade ?? ''}
              onChange={(event) => updateField('cidade', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">UF</span>
            <input
              className="input"
              maxLength={2}
              value={activePayload?.uf ?? ''}
              onChange={(event) => updateField('uf', event.target.value.toUpperCase())}
              disabled={disabled}
            />
          </label>

          {editingExisting ? (
            <label className="field">
              <span className="field__label">Observações</span>
              <textarea
                className="textarea"
                value={pendingClientUpdate?.observacoes ?? ''}
                onChange={(event) => updateField('observacoes', event.target.value)}
                disabled={disabled}
              />
            </label>
          ) : null}

          <div className="notice notice--warning">
            <span>
              {editingExisting
                ? 'Nenhuma alteração será gravada antes do salvamento final da OS.'
                : 'Este cliente será cadastrado somente ao criar a OS.'}
            </span>
          </div>
        </div>
      )}
    </section>
  );
}

export function isStepClientValid(
  cliente: ClientSearchResult | null,
  pendingNewClient: NovoClientePayload | null,
  pendingClientUpdate: ClientUpdatePayload | null = null
): boolean {
  if (pendingClientUpdate) {
    return Boolean(pendingClientUpdate.nome_razao.trim() && pendingClientUpdate.telefone1.trim());
  }

  return isWizardClientComplete(cliente, pendingNewClient);
}
