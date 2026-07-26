'use client';

import { useState } from 'react';
import { searchClients } from '@/lib/orders';
import type { ClientSearchResult, NovoClientePayload } from '@/lib/types';
import type { WizardMode } from '@/components/orders/order-form-wizard/wizard-state';
import { SearchSelect } from '@/components/orders/order-form-wizard/search-select';

type StepClientProps = {
  mode: WizardMode;
  cliente: ClientSearchResult | null;
  pendingNewClient: NovoClientePayload | null;
  onSelectCliente: (cliente: ClientSearchResult | null) => void;
  onChangePendingNewClient: (payload: NovoClientePayload | null) => void;
  disabled?: boolean;
};

const EMPTY_NEW_CLIENT: NovoClientePayload = { nome_razao: '', telefone1: '' };

export function StepClient({
  mode,
  cliente,
  pendingNewClient,
  onSelectCliente,
  onChangePendingNewClient,
  disabled = false,
}: StepClientProps) {
  const [view, setView] = useState<'buscar' | 'novo'>(cliente ? 'buscar' : pendingNewClient ? 'novo' : 'buscar');

  const switchToSearch = (): void => {
    setView('buscar');
    onChangePendingNewClient(null);
  };

  const switchToNew = (): void => {
    setView('novo');
    onSelectCliente(null);
    if (!pendingNewClient) {
      onChangePendingNewClient(EMPTY_NEW_CLIENT);
    }
  };

  const updateField = (field: keyof NovoClientePayload, value: string): void => {
    onChangePendingNewClient({ ...(pendingNewClient ?? EMPTY_NEW_CLIENT), [field]: value });
  };

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

      {view === 'buscar' || mode === 'edit' ? (
        <SearchSelect<ClientSearchResult>
          label="Buscar cliente"
          placeholder="Nome, telefone, e-mail ou CPF/CNPJ"
          value={cliente}
          onSelect={onSelectCliente}
          fetchOptions={searchClients}
          getOptionKey={(option) => option.id}
          getOptionLabel={(option) => option.nome_razao}
          getOptionSubtitle={(option) => [option.telefone1, option.cidade].filter(Boolean).join(' • ') || null}
          disabled={disabled}
        />
      ) : (
        <div className="form">
          <label className="field">
            <span className="field__label">Nome / razão social *</span>
            <input
              className="input"
              value={pendingNewClient?.nome_razao ?? ''}
              onChange={(event) => updateField('nome_razao', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Telefone *</span>
            <input
              className="input"
              value={pendingNewClient?.telefone1 ?? ''}
              onChange={(event) => updateField('telefone1', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">E-mail</span>
            <input
              className="input"
              type="email"
              value={pendingNewClient?.email ?? ''}
              onChange={(event) => updateField('email', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">CPF/CNPJ</span>
            <input
              className="input"
              value={pendingNewClient?.cpf_cnpj ?? ''}
              onChange={(event) => updateField('cpf_cnpj', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Nome do contato</span>
            <input
              className="input"
              value={pendingNewClient?.nome_contato ?? ''}
              onChange={(event) => updateField('nome_contato', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Telefone do contato</span>
            <input
              className="input"
              value={pendingNewClient?.telefone_contato ?? ''}
              onChange={(event) => updateField('telefone_contato', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">CEP</span>
            <input
              className="input"
              value={pendingNewClient?.cep ?? ''}
              onChange={(event) => updateField('cep', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Endereço</span>
            <input
              className="input"
              value={pendingNewClient?.endereco ?? ''}
              onChange={(event) => updateField('endereco', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Número</span>
            <input
              className="input"
              value={pendingNewClient?.numero ?? ''}
              onChange={(event) => updateField('numero', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Bairro</span>
            <input
              className="input"
              value={pendingNewClient?.bairro ?? ''}
              onChange={(event) => updateField('bairro', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Cidade</span>
            <input
              className="input"
              value={pendingNewClient?.cidade ?? ''}
              onChange={(event) => updateField('cidade', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">UF</span>
            <input
              className="input"
              maxLength={2}
              value={pendingNewClient?.uf ?? ''}
              onChange={(event) => updateField('uf', event.target.value.toUpperCase())}
              disabled={disabled}
            />
          </label>

          <div className="notice notice--warning">
            <span>Este cliente será cadastrado somente ao criar a OS.</span>
          </div>
        </div>
      )}
    </section>
  );
}

export function isStepClientValid(cliente: ClientSearchResult | null, pendingNewClient: NovoClientePayload | null): boolean {
  if (cliente) {
    return true;
  }

  return Boolean(pendingNewClient?.nome_razao.trim() && pendingNewClient?.telefone1.trim());
}
