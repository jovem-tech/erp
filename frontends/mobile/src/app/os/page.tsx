'use client';

import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { ApiError } from '@/lib/api';
import { fetchOrders, orderStatusBadgeClass } from '@/lib/orders';
import { OrderCard } from '@/components/orders/order-card';
import { useSession } from '@/components/session-provider';
import type { OrderSummary } from '@/lib/types';
import { formatDateTime, normalizeText } from '@/lib/format';

type Filters = {
  q: string;
  status: string;
};

function filtersFromQuery(query: string): Filters {
  const params = new URLSearchParams(query);

  return {
    q: normalizeText(params.get('q'), ''),
    status: normalizeText(params.get('status'), ''),
  };
}

function filtersToQuery(filters: Filters): string {
  const params = new URLSearchParams();

  if (filters.q.trim()) {
    params.set('q', filters.q.trim());
  }

  if (filters.status.trim()) {
    params.set('status', filters.status.trim());
  }

  return params.toString();
}

function OrdersScreen() {
  const router = useRouter();
  const pathname = usePathname() ?? '/os';
  const searchParams = useSearchParams();
  const searchParamsString = searchParams?.toString() ?? '';
  const { session, ready } = useSession();
  const [orders, setOrders] = useState<OrderSummary[]>([]);
  const [total, setTotal] = useState(0);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [draftFilters, setDraftFilters] = useState<Filters>(() => filtersFromQuery(searchParamsString));
  const [syncedAt, setSyncedAt] = useState<string | null>(null);
  const requestIdRef = useRef(0);

  const queryFilters = useMemo(() => filtersFromQuery(searchParamsString), [searchParamsString]);

  useEffect(() => {
    setDraftFilters(queryFilters);
  }, [queryFilters]);

  const statusOptions = useMemo(() => {
    const unique = new Map<string, { codigo: string; nome: string; cor: string }>();

    for (const order of orders) {
      if (!unique.has(order.status)) {
        unique.set(order.status, {
          codigo: order.status,
          nome: order.status_nome || order.status,
          cor: order.status_cor,
        });
      }
    }

    return Array.from(unique.values());
  }, [orders]);

  const activeStatusLabel = useMemo(() => {
    if (!queryFilters.status) {
      return 'Todos';
    }

    const match = statusOptions.find((option) => option.codigo === queryFilters.status);
    return normalizeText(match?.nome, queryFilters.status);
  }, [queryFilters.status, statusOptions]);

  const activeSearchLabel = useMemo(() => {
    if (!queryFilters.q) {
      return 'Sem busca';
    }

    return queryFilters.q.length > 24 ? `${queryFilters.q.slice(0, 24)}...` : queryFilters.q;
  }, [queryFilters.q]);

  const hasActiveFilters = queryFilters.q !== '' || queryFilters.status !== '';

  const updateRouteFilters = useCallback(
    (nextFilters: Filters) => {
      const query = filtersToQuery(nextFilters);
      router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false });
    },
    [pathname, router]
  );

  const loadOrders = useCallback(
    async (nextFilters: Filters): Promise<void> => {
      if (!session?.accessToken) {
        return;
      }

      const requestId = requestIdRef.current + 1;
      requestIdRef.current = requestId;

      setBusy(true);
      setError(null);

      try {
        const payload = await fetchOrders({
          q: nextFilters.q,
          status: nextFilters.status,
          per_page: 24,
        });

        if (requestIdRef.current !== requestId) {
          return;
        }

        setOrders(payload.orders);
        setTotal(payload.pagination.total);
        setSyncedAt(new Date().toISOString());
      } catch (requestError) {
        if (requestIdRef.current !== requestId) {
          return;
        }

        if (requestError instanceof ApiError) {
          setError(requestError.message);
          if (requestError.status === 401) {
            router.replace('/login');
          }
        } else {
          setError('Nao foi possivel carregar as OS.');
        }
      } finally {
        if (requestIdRef.current === requestId) {
          setBusy(false);
        }
      }
    },
    [router, session?.accessToken]
  );

  useEffect(() => {
    if (!ready || !session) {
      return;
    }

    void loadOrders(queryFilters);
  }, [loadOrders, queryFilters, ready, session]);

  const handleSubmit = useCallback(
    (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      updateRouteFilters(draftFilters);
    },
    [draftFilters, updateRouteFilters]
  );

  const handleClear = useCallback(() => {
    const nextFilters = { q: '', status: '' };
    setDraftFilters(nextFilters);
    updateRouteFilters(nextFilters);
  }, [updateRouteFilters]);

  return (
    <main className="app-shell">
      <section className="surface hero">
        <div className="toolbar">
          <div>
            <p className="hero__eyebrow">Sistema ERP Mobile</p>
            <h1 className="hero__title">Ordens de serviço</h1>
            <p className="hero__subtitle">
              Foco total em operação. A nav bar agora guarda perfil, tema e notificações, deixando a fila mais limpa.
            </p>
          </div>

          <button
            type="button"
            className="button button--ghost"
            onClick={() => void loadOrders(queryFilters)}
            disabled={busy}
          >
            {busy ? <span className="spinner" aria-hidden="true" /> : null}
            {busy ? 'Atualizando' : 'Recarregar'}
          </button>
        </div>

        <div className="stats">
          <div className="stat">
            <div className="stat__label">OS encontradas</div>
            <div className="stat__value">{total}</div>
            <div className="stat__hint">{busy ? 'Sincronizando...' : `${orders.length} exibidas na tela`}</div>
          </div>
          <div className="stat">
            <div className="stat__label">Filtro ativo</div>
            <div className="stat__value">{activeStatusLabel}</div>
            <div className="stat__hint">{activeSearchLabel}</div>
          </div>
          <div className="stat">
            <div className="stat__label">Última sync</div>
            <div className="stat__value">{formatDateTime(syncedAt, 'Ainda não sincronizado')}</div>
            <div className="stat__hint">Dados consumidos da API central</div>
          </div>
        </div>
      </section>

      <section className="surface section">
        <div className="section__header">
          <div>
            <h2 className="section__title">Pesquisa e filtros</h2>
            <span className="muted">{hasActiveFilters ? 'Filtros aplicados na URL' : 'Visualização padrão'}</span>
          </div>

          <span className="badge badge--accent">{hasActiveFilters ? 'Modo refinado' : 'Modo base'}</span>
        </div>

        <form className="form" onSubmit={handleSubmit}>
          <div className="split">
            <label className="field">
              <span className="field__label">Pesquisar</span>
              <input
                className="input"
                value={draftFilters.q}
                onChange={(event) => setDraftFilters((current) => ({ ...current, q: event.target.value }))}
                placeholder="OS, cliente ou equipamento"
                disabled={busy}
              />
            </label>

            <label className="field">
              <span className="field__label">Status</span>
              <select
                className="select"
                value={draftFilters.status}
                onChange={(event) => setDraftFilters((current) => ({ ...current, status: event.target.value }))}
                disabled={busy}
              >
                <option value="">Todos</option>
                {statusOptions.map((option) => (
                  <option key={option.codigo} value={option.codigo}>
                    {option.nome} ({option.codigo})
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className="toolbar">
            <div className="toolbar__group">
              <button type="submit" className="button button--primary" disabled={busy}>
                {busy ? <span className="spinner" aria-hidden="true" /> : null}
                {busy ? 'Buscando...' : 'Aplicar filtros'}
              </button>

              <button type="button" className="button button--soft" onClick={handleClear} disabled={busy}>
                Limpar
              </button>
            </div>

            <div className="toolbar__group">
              {statusOptions.map((option) => (
                <button
                  key={option.codigo}
                  type="button"
                  className={orderStatusBadgeClass(option.cor)}
                  onClick={() => {
                    const nextFilters = { ...draftFilters, status: option.codigo };
                    setDraftFilters(nextFilters);
                    updateRouteFilters(nextFilters);
                  }}
                  disabled={busy}
                >
                  {option.nome}
                </button>
              ))}
            </div>
          </div>
        </form>

        {error ? (
          <div className="notice notice--danger" style={{ marginTop: 14 }}>
            {error}
          </div>
        ) : null}
      </section>

      <section className="surface section">
        <div className="section__header">
          <h2 className="section__title">Fila operacional</h2>
          <span className="muted">{busy ? 'Atualizando...' : `${orders.length} registro(s)`}</span>
        </div>

        {orders.length === 0 && !busy ? (
          <div className="muted-box">Nenhuma OS encontrada para os filtros atuais.</div>
        ) : null}

        <div className="grid grid--orders">
          {orders.map((order) => (
            <OrderCard key={order.id} order={order} />
          ))}
        </div>
      </section>
    </main>
  );
}

export default function OrdersPage() {
  return <OrdersScreen />;
}
