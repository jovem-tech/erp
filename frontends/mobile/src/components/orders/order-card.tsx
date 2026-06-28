import Link from 'next/link';
import type { OrderSummary } from '@/lib/types';
import { orderStatusBadgeClass } from '@/lib/orders';

type OrderCardProps = {
  order: OrderSummary;
};

export function OrderCard({ order }: OrderCardProps) {
  return (
    <Link href={`/os/${order.id}`} className="card card--interactive">
      <div className="toolbar">
        <div>
          <p className="card__title">OS {order.numero_os}</p>
          <p className="muted" style={{ margin: '8px 0 0' }}>
            {order.cliente_nome || 'Cliente não informado'}
          </p>
        </div>
        <span className={orderStatusBadgeClass(order.status_cor)}>
          {order.status_nome || order.status || 'Sem status'}
        </span>
      </div>

      <div className="card__meta">
        <span className="badge badge--accent">{order.estado_fluxo || 'Fluxo não informado'}</span>
        <span className="badge">{order.equipamento_numero_serie || 'Sem série'}</span>
      </div>

      <div className="split" style={{ marginTop: '14px' }}>
        <div className="kpi">
          <span className="kpi__label">Equipamento</span>
          <span className="kpi__value">{order.equipamento_resumo_tecnico || 'Não informado'}</span>
        </div>
        <div className="kpi">
          <span className="kpi__label">Atualizado em</span>
          <span className="kpi__value">
            {order.status_atualizado_em ? new Date(order.status_atualizado_em).toLocaleString('pt-BR') : 'Sem data'}
          </span>
        </div>
      </div>
    </Link>
  );
}
