'use client';

import { useEffect, useState } from 'react';
import { listTechnicians, searchLinkableBudgets } from '@/lib/orders';
import type {
  DeliveryLeadDays,
  LinkableBudget,
  OrderPriority,
  TeamMemberOption,
} from '@/lib/types';
import { isWizardOperationsComplete } from '@/components/orders/order-form-wizard/wizard-state';
import { SearchSelect } from '@/components/orders/order-form-wizard/search-select';

const PRIORITY_OPTIONS: Array<{ value: OrderPriority; label: string }> = [
  { value: 'baixa', label: 'Baixa' },
  { value: 'normal', label: 'Normal' },
  { value: 'alta', label: 'Alta' },
  { value: 'urgente', label: 'Urgente' },
];

const DELIVERY_LEAD_OPTIONS: DeliveryLeadDays[] = [1, 3, 7, 15, 30];

export function calculateDeliveryDate(days: DeliveryLeadDays, baseDate = new Date()): string {
  const deliveryDate = new Date(
    baseDate.getFullYear(),
    baseDate.getMonth(),
    baseDate.getDate() + days,
    12
  );

  const year = deliveryDate.getFullYear();
  const month = String(deliveryDate.getMonth() + 1).padStart(2, '0');
  const day = String(deliveryDate.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

type StepOperationsProps = {
  prioridade: OrderPriority;
  prazoEntregaDias: DeliveryLeadDays | null;
  dataPrevisao: string;
  tecnicoId: number | null;
  observacoesInternas: string;
  orcamentoVinculado: LinkableBudget | null;
  canLinkBudget: boolean;
  onChangePrioridade: (value: OrderPriority) => void;
  onChangePrazoEntrega: (days: DeliveryLeadDays | null, date: string) => void;
  onChangeTecnico: (id: number | null, label: string | null) => void;
  onChangeObservacoesInternas: (value: string) => void;
  onChangeOrcamentoVinculado: (budget: LinkableBudget | null) => void;
  disabled?: boolean;
};

export function StepOperations({
  prioridade,
  prazoEntregaDias,
  dataPrevisao,
  tecnicoId,
  observacoesInternas,
  orcamentoVinculado,
  canLinkBudget,
  onChangePrioridade,
  onChangePrazoEntrega,
  onChangeTecnico,
  onChangeObservacoesInternas,
  onChangeOrcamentoVinculado,
  disabled = false,
}: StepOperationsProps) {
  const [technicians, setTechnicians] = useState<TeamMemberOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setLoading(true);
    listTechnicians()
      .then(setTechnicians)
      .catch(() => setError('Não foi possível carregar a lista de técnicos.'))
      .finally(() => setLoading(false));
  }, []);

  return (
    <section className="section">
      <div className="section__header">
        <h3 className="section__title">Atendimento</h3>
      </div>

      <div className="form">
        <label className="field">
          <span className="field__label">Prioridade</span>
          <select className="select" value={prioridade} onChange={(event) => onChangePrioridade(event.target.value as OrderPriority)} disabled={disabled}>
            {PRIORITY_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>

        <label className="field">
          <span className="field__label">Prazo de entrega (dias corridos) *</span>
          <select
            className="select"
            value={prazoEntregaDias ?? ''}
            onChange={(event) => {
              if (event.target.value === '') {
                onChangePrazoEntrega(null, '');
                return;
              }

              const days = Number(event.target.value) as DeliveryLeadDays;
              onChangePrazoEntrega(days, calculateDeliveryDate(days));
            }}
            disabled={disabled}
          >
            <option value="">Selecione...</option>
            {DELIVERY_LEAD_OPTIONS.map((days) => (
              <option key={days} value={days}>
                {days} {days === 1 ? 'dia corrido' : 'dias corridos'}
              </option>
            ))}
          </select>
        </label>

        <label className="field">
          <span className="field__label">Previsão de entrega *</span>
          <input
            className="input"
            type="date"
            value={dataPrevisao}
            readOnly
            aria-readonly="true"
            disabled={disabled || prazoEntregaDias === null}
          />
          <span className="muted">Calculada automaticamente em dias corridos.</span>
        </label>

        <label className="field">
          <span className="field__label">Técnico responsável *</span>
          <select
            className="select"
            value={tecnicoId ?? ''}
            onChange={(event) => {
              const value = event.target.value ? Number(event.target.value) : null;
              const label = technicians.find((technician) => technician.value === value)?.label ?? null;
              onChangeTecnico(value, label);
            }}
            disabled={disabled || loading}
          >
            <option value="">Selecione...</option>
            {technicians.map((technician) => (
              <option key={technician.value} value={technician.value}>
                {technician.label}
              </option>
            ))}
          </select>
          {loading ? <span className="muted">Carregando técnicos...</span> : null}
          {error ? (
            <div className="notice notice--danger">
              <span>{error}</span>
            </div>
          ) : null}
        </label>

        <label className="field">
          <span className="field__label">Observações internas</span>
          <textarea
            className="textarea"
            value={observacoesInternas}
            onChange={(event) => onChangeObservacoesInternas(event.target.value)}
            disabled={disabled}
          />
        </label>

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

export function isStepOperationsValid(
  tecnicoId: number | null,
  prazoEntregaDias: DeliveryLeadDays | null,
  dataPrevisao: string
): boolean {
  return isWizardOperationsComplete(tecnicoId, prazoEntregaDias, dataPrevisao);
}
