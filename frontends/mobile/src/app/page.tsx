'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useSession } from '@/components/session-provider';

export default function HomePage() {
  const router = useRouter();
  const { ready, session } = useSession();

  useEffect(() => {
    if (!ready) {
      return;
    }

    router.replace(session ? '/os' : '/login');
  }, [ready, router, session]);

  return (
    <main className="auth-screen">
      <section className="surface auth-card">
        <p className="hero__eyebrow">Sistema ERP Mobile</p>
        <h1 className="auth-card__title">Preparando a navegação</h1>
        <p className="auth-card__text">
          Estamos verificando sua sessão para abrir o fluxo correto.
        </p>
        <div className="auth-footer">
          <span className="badge badge--accent">
            <span className="spinner" aria-hidden="true" />
            Direcionando
          </span>
        </div>
      </section>
    </main>
  );
}
