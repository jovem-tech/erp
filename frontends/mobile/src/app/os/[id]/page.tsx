'use client';

import { useCallback, useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { ApiError } from '@/lib/api';
import { fetchOrder } from '@/lib/orders';
import type { OrderDetail } from '@/lib/types';
import { OrderAttachments } from '@/components/orders/order-attachments';
import { OrderStatusForm } from '@/components/orders/order-status-form';
import { useSession } from '@/components/session-provider';
import { orderStatusBadgeClass } from '@/lib/orders';
import { formatDateTime } from '@/lib/format';

function OrderDetailScreen() {
  const params = useParams<{ id?: string | string[] }>() ?? {};
  const router = useRouter();
  const { session } = useSession();
  const orderId = Array.isArray(params.id) ? params.id[0] : params.id;
  const [order, setOrder] = useState<OrderDetail | null>(null);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadOrder = useCallback(async (): Promise<void> => {
    if (!orderId) {
      return;
    }

    setBusy(true);
    setError(null);

    try {
      const payload = await fetchOrder(orderId);
      setOrder(payload);
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setError(requestError.message);
        if (requestError.status === 401) {
          router.replace('/login');
        }
      } else {
        setError('Nao foi possivel carregar o detalhe da OS.');
      }
    } finally {
      setBusy(false);
    }
  }, [orderId, router]);

  useEffect(() => {
    void loadOrder();
  }, [loadOrder]);

  return (
    <main className="app-shell">
      <section className="surface hero">
        <div className="toolbar">
          <div>
            <p className="hero__eyebrow">Sistema ERP Mobile</p>
            <h1 className="hero__title">Detalhe da OS</h1>
            <p className="hero__subtitle">
              {order ? `OS ${order.numero_os}` : 'Carregando informações da ordem de serviço.'}
            </p>
          </div>

          <div className="toolbar__group">
            <button type="button" className="button button--ghost" onClick={() => router.back()}>
              Voltar
            </button>
            <button type="button" className="button button--soft" onClick={() => void loadOrder()} disabled={busy}>
              {busy ? <span className="spinner" aria-hidden="true" /> : null}
              {busy ? 'Atualizando' : 'Recarregar'}
            </button>
          </div>
        </div>

        {order ? (
          <div className="stats">
            <div className="stat">
              <div className="stat__label">Cliente</div>
              <div className="stat__value">{order.cliente?.nome_razao || order.cliente_nome}</div>
            </div>
            <div className="stat">
              <div className="stat__label">Equipamento</div>
              <div className="stat__value">{order.equipamento?.resumo_tecnico || order.equipamento_resumo_tecnico}</div>
            </div>
            <div className="stat">
              <div className="stat__label">Status atual</div>
              <div className="stat__value">
                <span className={orderStatusBadgeClass(order.status_cor)}>
                  {order.status_nome || order.status}
                </span>
              </div>
              <div className="stat__hint">{formatDateTime(order.status_atualizado_em, 'Sem atualização recente')}</div>
            </div>
          </div>
        ) : null}
      </section>

      {error ? (
        <section className="surface section">
          <div className="notice notice--danger">{error}</div>
        </section>
      ) : null}

      {busy ? (
        <section className="surface section">
          <div className="muted-box">
            <span className="spinner" aria-hidden="true" /> Carregando detalhe da OS...
          </div>
        </section>
      ) : null}

      {order ? (
        <div className="layout-two-columns layout-two-columns--wide">
          <div className="list">
            <section className="surface section">
              <div className="section__header">
                <h2 className="section__title">Resumo da operação</h2>
                <span className={orderStatusBadgeClass(order.status_cor)}>
                  {order.estado_fluxo || 'Fluxo não informado'}
                </span>
              </div>

              <div className="split">
                <div className="kpi">
                  <span className="kpi__label">Relato do cliente</span>
                  <span className="kpi__value">{order.relato_cliente || 'Sem relato'}</span>
                </div>
                <div className="kpi">
                  <span className="kpi__label">Diagnóstico</span>
                  <span className="kpi__value">{order.diagnostico_tecnico || 'Sem diagnóstico'}</span>
                </div>
                <div className="kpi">
                  <span className="kpi__label">Garantia</span>
                  <span className="kpi__value">{order.garantia_dias} dias</span>
                </div>
                <div className="kpi">
                  <span className="kpi__label">Atualizado em</span>
                  <span className="kpi__value">
                    {order.status_atualizado_em ? new Date(order.status_atualizado_em).toLocaleString('pt-BR') : 'Sem data'}
                  </span>
                </div>
              </div>
            </section>

            <section className="surface section">
              <div className="section__header">
                <h2 className="section__title">Histórico recente</h2>
                <span className="muted">{order.historico.length} evento(s)</span>
              </div>

              {order.historico.length > 0 ? (
                <div className="timeline">
                  {order.historico.map((item) => (
                    <article key={item.id} className="timeline__item">
                      <div className="timeline__time">
                        {item.created_at ? new Date(item.created_at).toLocaleString('pt-BR') : 'Sem data'}
                      </div>
                      <p className="timeline__title">
                        {item.status_anterior || 'N/A'} → {item.status_novo}
                      </p>
                      <p className="timeline__text">{item.observacao || 'Sem observação.'}</p>
                    </article>
                  ))}
                </div>
              ) : (
                <div className="muted-box">Ainda não existe histórico para esta OS.</div>
              )}
            </section>

            <OrderAttachments order={order} />
          </div>

          <div className="list">
            <section className="surface section">
              <div className="section__header">
                <h2 className="section__title">Dados do atendimento</h2>
                <span className="badge badge--accent">{session?.user.nome}</span>
              </div>

              <div className="list list--tight">
                <div className="muted-box">
                  <strong>Cliente:</strong> {order.cliente?.nome_razao || order.cliente_nome}
                  <br />
                  <strong>Contato:</strong> {order.cliente?.nome_contato || 'Não informado'}
                  <br />
                  <strong>Telefone:</strong> {order.cliente?.telefone_contato || order.cliente?.telefone1 || 'Não informado'}
                </div>

                <div className="muted-box">
                  <strong>Equipamento:</strong> {order.equipamento?.resumo_tecnico || order.equipamento_resumo_tecnico}
                  <br />
                  <strong>Série:</strong> {order.equipamento?.numero_serie || order.equipamento_numero_serie}
                  <br />
                  <strong>Observações:</strong> {order.equipamento?.observacoes || 'Sem observações'}
                </div>
              </div>
            </section>

            <OrderStatusForm order={order} onUpdated={loadOrder} />
          </div>
        </div>
      ) : null}
    </main>
  );
}

export default function OrderDetailPage() {
  return <OrderDetailScreen />;
}
