'use client';

import { searchLinkableBudgets } from '@/lib/orders';
import type { LinkableBudget } from '@/lib/types';
import { SearchSelect } from '@/components/orders/order-form-wizard/search-select';

type StepExtrasProps = {
  enviarPdfCliente: boolean;
  onChangeEnviarPdfCliente: (value: boolean) => void;
  orcamentoVinculado: LinkableBudget | null;
  onChangeOrcamentoVinculado: (budget: LinkableBudget | null) => void;
  canLinkBudget: boolean;
  disabled?: boolean;
};

export function StepExtras({
  enviarPdfCliente,
  onChangeEnviarPdfCliente,
  orcamentoVinculado,
  onChangeOrcamentoVinculado,
  canLinkBudget,
  disabled = false,
}: StepExtrasProps) {
  return (
    <section className="section">
      <div className="section__header">
        <h3 className="section__title">Extras</h3>
      </div>

      <div className="form">
        <div className="field">
          <span className="field__label">Enviar PDF ao cliente *</span>
          <div className="toolbar">
            <div className="toolbar__group">
              <button
                type="button"
                className={!enviarPdfCliente ? 'button button--primary button-small' : 'button button--soft button-small'}
                onClick={() => onChangeEnviarPdfCliente(false)}
                disabled={disabled}
              >
                Não
              </button>
              <button
                type="button"
                className={enviarPdfCliente ? 'button button--primary button-small' : 'button button--soft button-small'}
                onClick={() => onChangeEnviarPdfCliente(true)}
                disabled={disabled}
              >
                Sim
              </button>
            </div>
          </div>
        </div>

        {canLinkBudget ? (
          <SearchSelect<LinkableBudget>
            label="Vincular orçamento avulso (opcional)"
            placeholder="Buscar orçamento por número ou cliente"
            value={orcamentoVinculado}
            onSelect={onChangeOrcamentoVinculado}
            fetchOptions={searchLinkableBudgets}
            getOptionKey={(option) => option.id}
            getOptionLabel={(option) => option.numero}
            getOptionSubtitle={(option) => option.cliente_nome || null}
            changeLabel="Desvincular"
            disabled={disabled}
          />
        ) : null}
      </div>
    </section>
  );
}
