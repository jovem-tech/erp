'use client';

import type { EntryChecklistModel, EntryChecklistResponseStatus } from '@/lib/types';
import type { ChecklistAnswerState } from '@/components/orders/order-form-wizard/wizard-state';
import { FieldLabel } from '@/components/ui/field-label';

const STATUS_OPTIONS: Array<{ value: EntryChecklistResponseStatus; label: string }> = [
  { value: 'nao_verificado', label: 'Não verificado' },
  { value: 'ok', label: 'OK' },
  { value: 'discrepancia', label: 'Discrepância' },
  { value: 'nao_se_aplica', label: 'Não se aplica' },
];

type StepChecklistProps = {
  model: EntryChecklistModel;
  answers: Record<number, ChecklistAnswerState>;
  observacoesEstado: string;
  onChangeAnswer: (itemId: number, answer: ChecklistAnswerState) => void;
  onChangeObservacoesEstado: (value: string) => void;
  onMarkAllOk: () => void;
  onUnmarkAll?: () => void;
  disabled?: boolean;
};

export function StepChecklist({
  model,
  answers,
  observacoesEstado,
  onChangeAnswer,
  onChangeObservacoesEstado,
  onMarkAllOk,
  onUnmarkAll = () => undefined,
  disabled = false,
}: StepChecklistProps) {
  const items = [...model.itens].sort((a, b) => a.ordem - b.ordem);

  return (
    <section className="section">
      <div className="section__header">
        <h3 className="section__title">Checklist de entrada</h3>
        <span className="muted">{model.nome}</span>
      </div>

      <div className="toolbar toolbar--section-leading">
        <button type="button" className="button button--soft button-small" onClick={onMarkAllOk} disabled={disabled}>
          Marcar tudo OK
        </button>
        <button type="button" className="button button--soft button-small" onClick={onUnmarkAll} disabled={disabled}>
          Desmarcar tudo
        </button>
      </div>

      <div className="list">
        {items.map((item) => {
          const answer = answers[item.id];
          const status = answer?.status ?? 'nao_verificado';

          return (
            <div className="card checklist-item" key={item.id}>
              <div className="checklist-item__row">
                <strong>{item.descricao}</strong>
                <label className="checklist-item__status">
                  <select
                    className="select"
                    value={status}
                    onChange={(event) =>
                      onChangeAnswer(item.id, {
                        status: event.target.value as EntryChecklistResponseStatus,
                        observacao: answer?.observacao ?? '',
                      })
                    }
                    disabled={disabled}
                  >
                    {STATUS_OPTIONS.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </select>
                </label>
              </div>

              {status === 'discrepancia' ? (
                <div className="field">
                  <FieldLabel required id={`discrepancia-label-${item.id}`}>
                    Descreva a discrepância
                  </FieldLabel>
                  <input
                    className="input"
                    aria-labelledby={`discrepancia-label-${item.id}`}
                    placeholder="Ex.: tela trincada, botão não funciona"
                    value={answer?.observacao ?? ''}
                    onChange={(event) => onChangeAnswer(item.id, { status, observacao: event.target.value })}
                    disabled={disabled}
                    aria-required="true"
                  />
                </div>
              ) : null}
            </div>
          );
        })}
      </div>

      <label className="field field--spaced">
        <span className="field__label">Observações gerais do estado do equipamento</span>
        <textarea
          className="textarea"
          value={observacoesEstado}
          onChange={(event) => onChangeObservacoesEstado(event.target.value)}
          disabled={disabled}
        />
      </label>
    </section>
  );
}
