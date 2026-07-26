'use client';

import { useEffect, useState } from 'react';
import { listTechnicians } from '@/lib/orders';
import type { OrderPriority, TeamMemberOption } from '@/lib/types';
import { isWizardOperationsComplete } from '@/components/orders/order-form-wizard/wizard-state';

const PRIORITY_OPTIONS: Array<{ value: OrderPriority; label: string }> = [
  { value: 'baixa', label: 'Baixa' },
  { value: 'normal', label: 'Normal' },
  { value: 'alta', label: 'Alta' },
  { value: 'urgente', label: 'Urgente' },
];

type StepOperationsProps = {
  prioridade: OrderPriority;
  dataPrevisao: string;
  tecnicoId: number | null;
  observacoesInternas: string;
  onChangePrioridade: (value: OrderPriority) => void;
  onChangeDataPrevisao: (value: string) => void;
  onChangeTecnico: (id: number | null, label: string | null) => void;
  onChangeObservacoesInternas: (value: string) => void;
  disabled?: boolean;
};

export function StepOperations({
  prioridade,
  dataPrevisao,
  tecnicoId,
  observacoesInternas,
  onChangePrioridade,
  onChangeDataPrevisao,
  onChangeTecnico,
  onChangeObservacoesInternas,
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
          <span className="field__label">Previsão de entrega</span>
          <input
            className="input"
            type="date"
            value={dataPrevisao}
            onChange={(event) => onChangeDataPrevisao(event.target.value)}
            disabled={disabled}
          />
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
      </div>
    </section>
  );
}

export function isStepOperationsValid(tecnicoId: number | null): boolean {
  return isWizardOperationsComplete(tecnicoId);
}
