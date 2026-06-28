'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { ApiError, apiLogout } from '@/lib/api';
import { useSession } from '@/components/session-provider';

type LogoutButtonProps = {
  className?: string;
};

export function LogoutButton({ className = 'button button--ghost' }: LogoutButtonProps) {
  const router = useRouter();
  const { clearSession } = useSession();
  const [busy, setBusy] = useState(false);

  const handleLogout = async (): Promise<void> => {
    if (busy) {
      return;
    }

    setBusy(true);

    try {
      await apiLogout();
    } catch (error) {
      if (!(error instanceof ApiError && error.status === 401)) {
        console.error('[Mobile] logout falhou', error);
      }
    } finally {
      clearSession();
      router.replace('/login');
      setBusy(false);
    }
  };

  return (
    <button type="button" className={className} onClick={handleLogout} disabled={busy}>
      {busy ? <span className="spinner" aria-hidden="true" /> : null}
      {busy ? 'Saindo...' : 'Sair'}
    </button>
  );
}
