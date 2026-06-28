'use client';

import { usePathname } from 'next/navigation';
import { AuthGuard } from '@/components/auth-guard';
import { ConversationList } from '@/components/conversas/conversation-list';
import { StartConversationButton } from '@/components/conversas/start-conversation-button';
import { LogoutButton } from '@/components/logout-button';
import { PwaInstallButton } from '@/components/pwa-install-button';
import { useSession } from '@/components/session-provider';

function resolveLayoutMode(pathname: string): 'list' | 'thread' {
  return /^\/conversas\/\d+/.test(pathname) ? 'thread' : 'list';
}

export default function ConversasLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { session } = useSession();

  return (
    <AuthGuard>
      <div className="chat-shell">
        <header className="chat-topbar">
          <div className="chat-topbar__brand">
            <span className="badge badge--accent">ERP</span>
            <span>Central de Atendimento</span>
          </div>
          <div className="chat-topbar__actions">
            <StartConversationButton />
            <PwaInstallButton />
            <span className="muted chat-topbar__user">{session?.user.nome}</span>
            <LogoutButton />
          </div>
        </header>

        <div className={`chat-layout chat-layout--${resolveLayoutMode(pathname)}`}>
          <div className="chat-column chat-column--list">
            <ConversationList />
          </div>
          {children}
        </div>
      </div>
    </AuthGuard>
  );
}
