import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AuthenticatedShell } from '@/components/authenticated-shell';

const mocks = vi.hoisted(() => ({
  push: vi.fn(),
  replace: vi.fn(),
  listNotifications: vi.fn(),
  logout: vi.fn(),
  markAllNotificationsRead: vi.fn(),
  markNotificationRead: vi.fn(),
  updatePassword: vi.fn(),
  updateProfile: vi.fn(),
  setSession: vi.fn(),
  clearSession: vi.fn(),
}));

vi.mock('next/link', () => ({
  default: ({
    href,
    children,
    ...props
  }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; children: ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

vi.mock('next/navigation', () => ({
  usePathname: () => '/os',
  useRouter: () => ({
    push: mocks.push,
    replace: mocks.replace,
  }),
}));

vi.mock('@/components/session-provider', () => ({
  useSession: () => ({
    ready: true,
    booting: false,
    session: {
      accessToken: 'token',
      tokenType: 'Bearer',
      expiresAt: '2099-01-01T00:00:00.000Z',
      user: {
        id: 7,
        nome: 'Otavio Silva',
        email: 'otavio@example.com',
        perfil: 'tecnico',
        grupo_id: 1,
        foto: '',
        ativo: true,
        ultimo_acesso: null,
        permissions: {
          os: ['visualizar', 'criar'],
        },
      },
    },
    setSession: mocks.setSession,
    clearSession: mocks.clearSession,
  }),
}));

vi.mock('@/lib/api', () => ({
  ApiError: class ApiError extends Error {
    status: number;

    constructor(message: string, status: number) {
      super(message);
      this.status = status;
    }
  },
  apiListNotifications: mocks.listNotifications,
  apiLogout: mocks.logout,
  apiMarkAllNotificationsRead: mocks.markAllNotificationsRead,
  apiMarkNotificationRead: mocks.markNotificationRead,
  apiUpdatePassword: mocks.updatePassword,
  apiUpdateProfile: mocks.updateProfile,
}));

describe('AuthenticatedShell navigation', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.listNotifications.mockResolvedValue({ items: [], unread_count: 0 });
    mocks.logout.mockResolvedValue(undefined);
    mocks.markAllNotificationsRead.mockResolvedValue(undefined);
    mocks.markNotificationRead.mockResolvedValue(undefined);

    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      value: vi.fn().mockImplementation((query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })),
    });
  });

  it('renders the five requested bottom navigation actions', () => {
    render(
      <AuthenticatedShell>
        <div>Conteudo protegido</div>
      </AuthenticatedShell>
    );

    const navigation = screen.getByRole('navigation', { name: 'Navegação principal' });
    expect(navigation.querySelectorAll('.app-bottom-nav-item')).toHaveLength(5);
    expect(within(navigation).getByRole('link', { name: 'Início' })).toHaveAttribute('href', '/');
    expect(within(navigation).getByRole('link', { name: 'OS' })).toHaveAttribute('href', '/os');
    expect(within(navigation).getByRole('link', { name: 'Criar nova OS' })).toHaveAttribute('href', '/os/novo');
    expect(within(navigation).getByRole('button', { name: 'Orçamentos, disponível futuramente' })).toBeDisabled();
    expect(within(navigation).getByRole('button', { name: 'Abrir perfil do usuário' })).toBeEnabled();
  });

  it('moves installation to the hamburger menu and profile to the bottom navigation', async () => {
    const user = userEvent.setup();

    render(
      <AuthenticatedShell>
        <div>Conteudo protegido</div>
      </AuthenticatedShell>
    );

    expect(screen.queryByRole('menuitem', { name: 'Instalar app' })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Abrir menu principal' }));

    expect(screen.getByRole('menu', { name: 'Menu principal' })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Instalar app' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Abrir perfil do usuário' }));

    expect(screen.queryByRole('menu', { name: 'Menu principal' })).not.toBeInTheDocument();
    expect(screen.getByRole('menu', { name: 'Menu de perfil' })).toBeInTheDocument();
    expect(screen.getByText('otavio@example.com')).toBeInTheDocument();
  });
});
