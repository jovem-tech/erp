'use client';

import { Suspense, useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ApiError, apiLogin } from '@/lib/api';
import { useSession } from '@/components/session-provider';
import { formatSessionExpiration } from '@/lib/session';
import { PwaInstallButton } from '@/components/pwa-install-button';

function LoginScreen() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { ready, session, setSession } = useSession();
  const destination = searchParams?.get('next') || '/os';

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [deviceName] = useState('pwa-mobile');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (ready && session) {
      router.replace(destination);
    }
  }, [destination, ready, router, session]);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const nextSession = await apiLogin({
        email,
        password,
        device_name: deviceName,
      });

      setSession(nextSession);
      router.replace(destination);
    } catch (loginError) {
      if (loginError instanceof ApiError) {
        setError(loginError.message);
      } else {
        setError('Não foi possível entrar no sistema.');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="auth-screen">
      <section className="surface auth-card">
        <div className="auth-card__logo">
          <span className="badge badge--accent">ERP</span>
          <span>Canal móvel</span>
        </div>

        <h1 className="auth-card__title">Acesso seguro ao atendimento</h1>
        <p className="auth-card__text">
          Entre com suas credenciais do ERP para continuar com o fluxo de OS, fotos e documentos.
        </p>

        <form className="form" onSubmit={handleSubmit} style={{ marginTop: '18px' }}>
          <label className="field">
            <span className="field__label">E-mail</span>
            <input
              className="input"
              type="email"
              inputMode="email"
              autoComplete="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="tecnico@empresa.com"
              required
              disabled={busy}
            />
          </label>

          <label className="field">
            <span className="field__label">Senha</span>
            <input
              className="input"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              placeholder="Sua senha"
              required
              disabled={busy}
            />
          </label>

          <input type="hidden" value={deviceName} readOnly />

          {error ? <div className="notice notice--danger">{error}</div> : null}

          <button type="submit" className="button button--primary" disabled={busy}>
            {busy ? <span className="spinner" aria-hidden="true" /> : null}
            {busy ? 'Entrando...' : 'Entrar'}
          </button>
        </form>

        <div className="auth-footer">
          <span className="badge badge--accent">Token Bearer</span>
          <span className="badge">Expiração controlada</span>
          <span className="badge">Sessão persistente</span>
        </div>

        <div style={{ marginTop: '16px', display: 'flex', justifyContent: 'center' }}>
          <PwaInstallButton />
        </div>

        {ready && session ? (
          <p className="muted" style={{ marginTop: '14px' }}>
            Sessão ativa até {formatSessionExpiration(session)}
          </p>
        ) : null}
      </section>
    </main>
  );
}

export default function LoginPage() {
  return (
    <Suspense
      fallback={
        <main className="auth-screen">
          <section className="surface auth-card">
            <p className="hero__eyebrow">Sistema ERP Mobile</p>
            <h1 className="auth-card__title">Carregando acesso</h1>
            <p className="auth-card__text">Estamos preparando a tela de login.</p>
          </section>
        </main>
      }
    >
      <LoginScreen />
    </Suspense>
  );
}
