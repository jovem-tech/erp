'use client';

import { useEffect } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useSession } from '@/components/session-provider';

type AuthGuardProps = {
  children: React.ReactNode;
};

export function AuthGuard({ children }: AuthGuardProps) {
  const router = useRouter();
  const pathname = usePathname();
  const { session, ready, booting } = useSession();

  useEffect(() => {
    if (!ready) {
      return;
    }

    if (!session) {
      const nextPath = pathname && pathname !== '/login' ? `?next=${encodeURIComponent(pathname)}` : '';
      router.replace(`/login${nextPath}`);
    }
  }, [pathname, ready, router, session]);

  if (!ready || booting) {
    return (
      <main className="auth-screen">
        <section className="surface auth-card">
          <p className="hero__eyebrow">Sistema ERP</p>
          <h1 className="auth-card__title">Sincronizando sessão</h1>
          <p className="auth-card__text">
            Estamos validando seu acesso e preparando o fluxo de trabalho.
          </p>
          <div className="auth-footer">
            <span className="badge badge--accent">
              <span className="spinner" aria-hidden="true" />
              Carregando
            </span>
          </div>
        </section>
      </main>
    );
  }

  if (!session) {
    return (
      <main className="auth-screen">
        <section className="surface auth-card">
          <p className="hero__eyebrow">Acesso protegido</p>
          <h1 className="auth-card__title">Redirecionando para login</h1>
          <p className="auth-card__text">
            Sua sessão não está mais disponível ou expirou.
          </p>
        </section>
      </main>
    );
  }

  return <>{children}</>;
}
