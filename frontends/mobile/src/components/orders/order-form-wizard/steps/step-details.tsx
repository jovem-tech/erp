'use client';

import { useEffect, useId, useMemo, useState } from 'react';
import { searchReportedDefects } from '@/lib/orders';
import type { ReportedDefect } from '@/lib/types';
import { isWizardDetailsComplete } from '@/components/orders/order-form-wizard/wizard-state';
import { FieldLabel } from '@/components/ui/field-label';
import {
  ACCESSORY_CHIPS,
  NO_ACCESSORIES_LABEL,
  isAccessoryChipActive,
  isNoAccessoriesActive,
  toggleAccessoryChip,
  toggleNoAccessories,
} from '@/lib/order-accessories';

type StepDetailsProps = {
  tipoEquipamentoId: number | null;
  relatoCliente: string;
  acessorios: string;
  onChangeRelatoCliente: (value: string) => void;
  onChangeAcessorios: (value: string) => void;
  /** Só a criação de OS exige acessórios preenchidos — OS antigas em edição podem ter vindo vazias. */
  requireAcessorios?: boolean;
  disabled?: boolean;
};

export function StepDetails({
  tipoEquipamentoId,
  relatoCliente,
  acessorios,
  onChangeRelatoCliente,
  onChangeAcessorios,
  requireAcessorios = true,
  disabled = false,
}: StepDetailsProps) {
  const acessoriosLabelId = useId();
  const [defects, setDefects] = useState<ReportedDefect[]>([]);

  useEffect(() => {
    if (!tipoEquipamentoId) {
      setDefects([]);
      return;
    }

    let cancelled = false;

    searchReportedDefects(tipoEquipamentoId)
      .then((results) => {
        if (!cancelled) {
          setDefects(results);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setDefects([]);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [tipoEquipamentoId]);

  const groupedDefects = useMemo(() => {
    const groups = new Map<string, ReportedDefect[]>();
    defects.forEach((defect) => {
      const key = defect.categoria || 'Outros';
      groups.set(key, [...(groups.get(key) ?? []), defect]);
    });
    return Array.from(groups.entries());
  }, [defects]);

  const appendSuggestion = (texto: string): void => {
    const current = relatoCliente.trim();
    onChangeRelatoCliente(current ? `${current}\n${texto}` : texto);
  };

  return (
    <section className="section">
      <div className="section__header">
        <h3 className="section__title">Relato e defeito</h3>
      </div>

      <div className="form">
        <label className="field">
          <FieldLabel required>Relato do cliente</FieldLabel>
          <textarea
            className="textarea"
            value={relatoCliente}
            onChange={(event) => onChangeRelatoCliente(event.target.value)}
            placeholder="Descreva o que o cliente relatou sobre o problema"
            disabled={disabled}
            aria-required="true"
          />
        </label>

        {groupedDefects.length > 0 ? (
          <div className="field">
            <span className="field__label">Defeitos comuns deste equipamento</span>
            {groupedDefects.map(([categoria, items]) => (
              <div key={categoria} className="detail-category">
                <span className="muted">{categoria}</span>
                <div className="toolbar toolbar--compact-spaced">
                  <div className="toolbar__group">
                    {items.map((defect) => (
                      <button
                        key={defect.id}
                        type="button"
                        className="chip chip--suggestion"
                        onClick={() => appendSuggestion(defect.texto_relato)}
                        disabled={disabled}
                      >
                        {defect.texto_relato}
                      </button>
                    ))}
                  </div>
                </div>
              </div>
            ))}
          </div>
        ) : null}

        <div className="field">
          <FieldLabel required={requireAcessorios} id={acessoriosLabelId}>
            Acessórios entregues junto com o equipamento
          </FieldLabel>
          <div className="toolbar toolbar--compact-spaced">
            <div className="toolbar__group">
              {ACCESSORY_CHIPS.map((chip) => (
                <button
                  key={chip}
                  type="button"
                  className={
                    isAccessoryChipActive(acessorios, chip) ? 'chip chip--suggestion chip--selected' : 'chip chip--suggestion'
                  }
                  aria-pressed={isAccessoryChipActive(acessorios, chip)}
                  onClick={() => onChangeAcessorios(toggleAccessoryChip(acessorios, chip))}
                  disabled={disabled}
                >
                  {chip}
                </button>
              ))}
              <button
                type="button"
                className={
                  isNoAccessoriesActive(acessorios) ? 'chip chip--suggestion chip--selected' : 'chip chip--suggestion'
                }
                aria-pressed={isNoAccessoriesActive(acessorios)}
                onClick={() => onChangeAcessorios(toggleNoAccessories(acessorios))}
                disabled={disabled}
              >
                {NO_ACCESSORIES_LABEL}
              </button>
            </div>
          </div>
          <textarea
            className="textarea"
            value={acessorios}
            onChange={(event) => onChangeAcessorios(event.target.value)}
            placeholder="Ex.: carregador, capa, fone de ouvido"
            disabled={disabled}
            aria-required={requireAcessorios}
            aria-labelledby={acessoriosLabelId}
          />
        </div>
      </div>
    </section>
  );
}

export function isStepDetailsValid(relatoCliente: string, acessorios: string, requireAcessorios = true): boolean {
  return isWizardDetailsComplete(relatoCliente, acessorios, requireAcessorios);
}
