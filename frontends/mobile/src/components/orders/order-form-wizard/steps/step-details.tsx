'use client';

import { useEffect, useMemo, useState } from 'react';
import { searchReportedDefects } from '@/lib/orders';
import type { ReportedDefect } from '@/lib/types';
import { isWizardDetailsComplete } from '@/components/orders/order-form-wizard/wizard-state';

type StepDetailsProps = {
  tipoEquipamentoId: number | null;
  relatoCliente: string;
  acessorios: string;
  onChangeRelatoCliente: (value: string) => void;
  onChangeAcessorios: (value: string) => void;
  disabled?: boolean;
};

export function StepDetails({
  tipoEquipamentoId,
  relatoCliente,
  acessorios,
  onChangeRelatoCliente,
  onChangeAcessorios,
  disabled = false,
}: StepDetailsProps) {
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
          <span className="field__label">Relato do cliente *</span>
          <textarea
            className="textarea"
            value={relatoCliente}
            onChange={(event) => onChangeRelatoCliente(event.target.value)}
            placeholder="Descreva o que o cliente relatou sobre o problema"
            disabled={disabled}
          />
        </label>

        {groupedDefects.length > 0 ? (
          <div className="field">
            <span className="field__label">Defeitos comuns deste equipamento</span>
            {groupedDefects.map(([categoria, items]) => (
              <div key={categoria} style={{ marginBottom: 8 }}>
                <span className="muted">{categoria}</span>
                <div className="toolbar" style={{ marginTop: 6 }}>
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

        <label className="field">
          <span className="field__label">Acessórios entregues junto com o equipamento</span>
          <textarea
            className="textarea"
            value={acessorios}
            onChange={(event) => onChangeAcessorios(event.target.value)}
            placeholder="Ex.: carregador, capa, fone de ouvido"
            disabled={disabled}
          />
        </label>
      </div>
    </section>
  );
}

export function isStepDetailsValid(relatoCliente: string): boolean {
  return isWizardDetailsComplete(relatoCliente);
}
