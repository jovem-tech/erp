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

    router.replace(session ? '/conversas' : '/login');
  }, [ready, router, session]);

  return (
    <main className="auth-screen">
      <p className="muted">Carregando...</p>
    </main>
  );
}
