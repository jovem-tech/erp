'use client';

import { useMemo, useState } from 'react';
import { ApiError } from '@/lib/api';
import type { OrderDetail, OrderStatusOption } from '@/lib/types';
import { saveOrderStatus } from '@/lib/orders';
import { orderStatusBadgeClass } from '@/lib/orders';

type OrderStatusFormProps = {
  order: OrderDetail;
  onUpdated: () => Promise<void> | void;
};

export function OrderStatusForm({ order, onUpdated }: OrderStatusFormProps) {
  const options = useMemo<OrderStatusOption[]>(() => order.status_disponiveis ?? [], [order.status_disponiveis]);
  const [status, setStatus] = useState(order.status);
  const [observacao, setObservacao] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [messageType, setMessageType] = useState<'success' | 'danger' | 'warning'>('success');

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    setBusy(true);
    setMessage(null);

    try {
      await saveOrderStatus(order.id, status, observacao.trim() || null);
      setMessageType('success');
      setMessage('Status atualizado com sucesso.');
      await onUpdated();
    } catch (error) {
      if (error instanceof ApiError) {
        setMessageType(error.status === 422 ? 'warning' : 'danger');
        setMessage(error.message);
      } else {
        setMessageType('danger');
        setMessage('Não foi possível atualizar o status.');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="card">
      <div className="section__header" style={{ marginBottom: 12 }}>
        <h3 className="section__title">Atualizar status</h3>
        <span className={orderStatusBadgeClass(order.status_cor)}>{order.status_nome || order.status}</span>
      </div>

      <form className="form" onSubmit={handleSubmit}>
        <label className="field">
          <span className="field__label">Novo status</span>
          <select className="select" value={status} onChange={(event) => setStatus(event.target.value)} disabled={busy}>
            {options.map((option) => (
              <option key={option.codigo} value={option.codigo}>
                {option.nome} ({option.codigo})
              </option>
            ))}
          </select>
        </label>

        <label className="field">
          <span className="field__label">Observação</span>
          <textarea
            className="textarea"
            value={observacao}
            onChange={(event) => setObservacao(event.target.value)}
            placeholder="Descreva o contexto da mudança"
            disabled={busy}
          />
        </label>

        {message ? (
          <div className={`notice notice--${messageType}`}>
            <span>{message}</span>
          </div>
        ) : null}

        <div className="toolbar">
          <div className="toolbar__group">
            {options.length > 0 ? (
              options.map((option) => (
                <button
                  key={option.codigo}
                  type="button"
                  className={status === option.codigo ? 'button button--primary' : 'button button--soft'}
                  onClick={() => setStatus(option.codigo)}
                  disabled={busy}
                >
                  {option.nome}
                </button>
              ))
            ) : (
              <span className="muted">Nenhuma opção de status disponível.</span>
            )}
          </div>

          <button type="submit" className="button button--primary" disabled={busy || options.length === 0}>
            {busy ? <span className="spinner" aria-hidden="true" /> : null}
            {busy ? 'Salvando...' : 'Salvar status'}
          </button>
        </div>
      </form>
    </section>
  );
}
