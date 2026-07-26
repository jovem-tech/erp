'use client';

import Link from 'next/link';
import { AuthGuard } from '@/components/auth-guard';
import { AuthenticatedShell } from '@/components/authenticated-shell';
import { useSession } from '@/components/session-provider';
import { firstWord } from '@/lib/format';
import { hasPermission } from '@/lib/permissions';

function WorkspaceScreen() {
  const { session } = useSession();
  const canCreateOrder = hasPermission(session?.user, 'os', 'criar');
  const operatorName = firstWord(session?.user.nome, 'Operador');

  return (
    <main className="app-shell">
      <section className="surface hero workspace-hero">
        <div>
          <p className="hero__eyebrow">Área de trabalho</p>
          <h1 className="hero__title">Olá, {operatorName}</h1>
          <p className="hero__subtitle">
            Acesse rapidamente as rotinas principais do atendimento técnico.
          </p>
        </div>
      </section>

      <section className="surface section">
        <div className="section__header">
          <div>
            <h2 className="section__title">Atalhos</h2>
            <span className="muted">Escolha por onde deseja começar</span>
          </div>
        </div>

        <div className="workspace-grid">
          <Link className="workspace-card" href="/os">
            <span className="workspace-card__index">01</span>
            <strong>Ordens de serviço</strong>
            <p>Consulte, pesquise e acompanhe a fila operacional.</p>
            <span className="workspace-card__action">Abrir lista de OS</span>
          </Link>

          {canCreateOrder ? (
            <Link className="workspace-card workspace-card--accent" href="/os/novo">
              <span className="workspace-card__index">02</span>
              <strong>Nova OS</strong>
              <p>Inicie um novo atendimento com o fluxo guiado.</p>
              <span className="workspace-card__action">Criar ordem de serviço</span>
            </Link>
          ) : (
            <article className="workspace-card workspace-card--disabled">
              <span className="workspace-card__index">02</span>
              <strong>Nova OS</strong>
              <p>Seu perfil não possui permissão para criar ordens de serviço.</p>
              <span className="workspace-card__action">Acesso indisponível</span>
            </article>
          )}

          <article className="workspace-card workspace-card--disabled">
            <span className="workspace-card__index">03</span>
            <strong>Orçamentos</strong>
            <p>Este módulo será disponibilizado em uma implementação futura.</p>
            <span className="workspace-card__action">Em breve</span>
          </article>
        </div>
      </section>
    </main>
  );
}

export default function HomePage() {
  return (
    <AuthGuard>
      <AuthenticatedShell>
        <WorkspaceScreen />
      </AuthenticatedShell>
    </AuthGuard>
  );
}
