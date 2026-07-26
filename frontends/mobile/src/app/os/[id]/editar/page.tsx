'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { ApiError } from '@/lib/api';
import { fetchOrder } from '@/lib/orders';
import { hasPermission } from '@/lib/permissions';
import type { OrderDetail } from '@/lib/types';
import { useSession } from '@/components/session-provider';
import { OrderFormWizard } from '@/components/orders/order-form-wizard';

function EditOrderScreen() {
  const params = useParams<{ id?: string | string[] }>() ?? {};
  const router = useRouter();
  const { session } = useSession();
  const orderId = Array.isArray(params.id) ? params.id[0] : params.id;

  const [order, setOrder] = useState<OrderDetail | null>(null);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const canEdit = useMemo(() => hasPermission(session?.user, 'os', 'editar'), [session?.user]);

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
        setError('Não foi possível carregar a OS para edição.');
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
            <h1 className="hero__title">Editar OS</h1>
            <p className="hero__subtitle">
              {order ? `OS ${order.numero_os}` : 'Carregando informações da ordem de serviço.'}
            </p>
          </div>

          <button type="button" className="button button--ghost" onClick={() => router.back()}>
            Voltar
          </button>
        </div>
      </section>

      {error ? (
        <section className="surface section">
          <div className="notice notice--danger">{error}</div>
        </section>
      ) : null}

      {busy ? (
        <section className="surface section">
          <div className="muted-box">
            <span className="spinner" aria-hidden="true" /> Carregando OS...
          </div>
        </section>
      ) : null}

      {order ? (
        <section className="surface section">
          {order.is_encerrada ? (
            <div className="notice notice--warning">
              <span>Esta OS já está encerrada e não pode mais ser editada por aqui.</span>
            </div>
          ) : canEdit ? (
            <OrderFormWizard mode="edit" order={order} />
          ) : (
            <div className="notice notice--danger">
              <span>Seu perfil não tem permissão para editar Ordens de Serviço.</span>
            </div>
          )}
        </section>
      ) : null}
    </main>
  );
}

export default function EditOrderPage() {
  return <EditOrderScreen />;
}
