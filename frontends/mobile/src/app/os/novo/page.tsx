'use client';

import { useMemo, useState } from 'react';
import { useSession } from '@/components/session-provider';
import { hasPermission } from '@/lib/permissions';
import { OrderFormWizard } from '@/components/orders/order-form-wizard';

function NewOrderScreen() {
  const { session } = useSession();
  const [idempotencyKey] = useState<string>(() => crypto.randomUUID());
  const canCreate = useMemo(() => hasPermission(session?.user, 'os', 'criar'), [session?.user]);

  return (
    <main className="app-shell">
      <section className="surface hero">
        <div>
          <p className="hero__eyebrow">Sistema ERP Mobile</p>
          <h1 className="hero__title">Nova OS</h1>
          <p className="hero__subtitle">Preencha as etapas para abrir uma nova ordem de serviço.</p>
        </div>
      </section>

      <section className="surface section">
        {canCreate ? (
          <OrderFormWizard mode="create" idempotencyKey={idempotencyKey} />
        ) : (
          <div className="notice notice--danger">
            <span>Seu perfil não tem permissão para criar Ordens de Serviço.</span>
          </div>
        )}
      </section>
    </main>
  );
}

export default function NewOrderPage() {
  return <NewOrderScreen />;
}
