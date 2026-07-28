'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactElement,
  type ReactNode,
} from 'react';
import {
  ApiError,
  apiListNotifications,
  apiLogout,
  apiMarkAllNotificationsRead,
  apiMarkNotificationRead,
  apiUpdatePassword,
  apiUpdateProfile,
} from '@/lib/api';
import { formatDateTime, normalizeText } from '@/lib/format';
import { isSafeInternalPath } from '@/lib/navigation';
import type { MobileNotification } from '@/lib/types';
import { hasPermission } from '@/lib/permissions';
import { useSession } from '@/components/session-provider';
import { PwaInstallButton } from '@/components/pwa-install-button';
import {
  OrderCreationPlayer,
  OrderCreationPlayerProvider,
} from '@/components/orders/order-creation-player';
import {
  applyThemePreference,
  getPreferredTheme,
  resolveThemeToggle,
  THEME_DARK,
  THEME_LIGHT,
  type ThemeMode,
} from '@/lib/theme';

type IconComponent = () => ReactElement;

function IconBell() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M15 17H5l1.2-1.2c.5-.5.8-1.2.8-2V10a5 5 0 0 1 4-4.9V4a1 1 0 1 1 2 0v1.1A5 5 0 0 1 17 10v3.8c0 .8.3 1.5.8 2L19 17h-4"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path
        d="M9.5 17a2.5 2.5 0 0 0 5 0"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
      />
    </svg>
  );
}

function IconSun() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" strokeWidth="1.7" />
      <path
        d="M12 2.5v2.2M12 19.3v2.2M4.7 4.7l1.6 1.6M17.7 17.7l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.7 19.3l1.6-1.6M17.7 6.3l1.6-1.6"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
      />
    </svg>
  );
}

function IconMoon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M19.1 14.9A7.8 7.8 0 0 1 9.1 4.9 8.5 8.5 0 1 0 19.1 14.9Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconMenu() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M4 7h16M4 12h16M4 17h16"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.9"
        strokeLinecap="round"
      />
    </svg>
  );
}

function IconLogout() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M10 5H6.5A2.5 2.5 0 0 0 4 7.5v9A2.5 2.5 0 0 0 6.5 19H10"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path
        d="m14 8 4 4-4 4M18 12H9"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconEdit() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M4 20h4l10.5-10.5a1.8 1.8 0 0 0 0-2.6l-1.4-1.4a1.8 1.8 0 0 0-2.6 0L4 16v4Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinejoin="round"
      />
      <path
        d="m13.5 6.5 4 4"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
      />
    </svg>
  );
}

function IconLock() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <rect x="5" y="10" width="14" height="10" rx="2" fill="none" stroke="currentColor" strokeWidth="1.7" />
      <path
        d="M8 10V8a4 4 0 0 1 8 0v2"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
      />
    </svg>
  );
}

function IconCheck() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="m5 12 4 4L19 6"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.9"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconOrders() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M6 4h12v16l-3-2-3 2-3-2-3 2V4Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinejoin="round"
      />
      <path d="M9 9h6M9 13h6" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
    </svg>
  );
}

function IconPlus() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M12 5v14M5 12h14" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}

function IconHome() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="m4 10 8-6 8 6v9a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1v-9Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconBudget() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M5 4h14v16H5zM8 8h8M8 12h3M14 12h2M8 16h3M14 16h2"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconProfile() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="12" cy="8" r="3.5" fill="none" stroke="currentColor" strokeWidth="1.7" />
      <path
        d="M5 20a7 7 0 0 1 14 0"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
      />
    </svg>
  );
}

function Avatar({ name, size = 'md' }: { name: string; size?: 'md' | 'lg' }) {
  const initials = name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0] ?? '')
    .join('')
    .toUpperCase();

  return (
    <span className={`avatar avatar-${size}`} aria-hidden="true">
      {initials || 'ER'}
    </span>
  );
}

function ModalFrame({
  title,
  subtitle,
  onClose,
  children,
}: {
  title: string;
  subtitle: string;
  onClose: () => void;
  children: ReactNode;
}) {
  const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-');

  return (
    <div className="modal-backdrop" role="presentation" onMouseDown={onClose}>
      <section
        className="modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby={`${slug}-title`}
        aria-describedby={`${slug}-subtitle`}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="modal-header">
          <div>
            <h3 id={`${slug}-title`}>{title}</h3>
            <p id={`${slug}-subtitle`}>{subtitle}</p>
          </div>

          <button className="icon-button" type="button" onClick={onClose} aria-label="Fechar janela">
            <span aria-hidden="true">x</span>
          </button>
        </div>

        {children}
      </section>
    </div>
  );
}

function MenuAction({
  children,
  onClick,
  tone = 'default',
  icon: Icon,
  disabled = false,
}: {
  children: ReactNode;
  onClick: () => void;
  tone?: 'default' | 'danger';
  icon?: IconComponent;
  disabled?: boolean;
}) {
  return (
    <button
      className={`menu-action menu-action-${tone}`}
      type="button"
      role="menuitem"
      onClick={onClick}
      disabled={disabled}
    >
      {Icon ? (
        <span className="menu-action-icon">
          <Icon />
        </span>
      ) : null}
      <span>{children}</span>
    </button>
  );
}

function NotificationItem({
  item,
  busy,
  onMarkRead,
  onOpen,
}: {
  item: MobileNotification;
  busy: boolean;
  onMarkRead: (notificationId: string) => Promise<void>;
  onOpen: (item: MobileNotification) => Promise<void>;
}) {
  const read = Boolean(item.lida_em);
  const destination = normalizeText(item.rota_destino, '');

  return (
    <article className={`notification-item ${read ? 'notification-item--read' : 'notification-item--unread'}`}>
      <button className="notification-item-copy" type="button" onClick={() => void onOpen(item)} disabled={busy}>
        <div className="notification-topline">
          <span className={`chip ${read ? 'chip-neutral' : 'chip-warning'}`}>{read ? 'Lida' : 'Nova'}</span>
          <time>{formatDateTime(item.criada_em, 'Sem data')}</time>
        </div>

        <strong>{normalizeText(item.titulo, 'Notificacao')}</strong>
        <p>{normalizeText(item.corpo, 'Sem conteudo')}</p>
        {destination ? <span className="notification-route">Destino: {destination}</span> : null}
      </button>

      <div className="notification-item-actions">
        <button
          className="button button-secondary button-small"
          type="button"
          onClick={() => void onMarkRead(item.id)}
          disabled={read || busy}
        >
          {read ? 'Ja lida' : busy ? 'Salvando...' : 'Marcar como lida'}
        </button>
      </div>
    </article>
  );
}

function AuthenticatedShellContent({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname() ?? '';
  const { session, setSession, clearSession } = useSession();
  const [mounted, setMounted] = useState(false);
  const [theme, setTheme] = useState<ThemeMode>(THEME_DARK);
  const [mainMenuOpen, setMainMenuOpen] = useState(false);
  const [bellOpen, setBellOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [notifications, setNotifications] = useState<MobileNotification[]>([]);
  const [notificationsLoading, setNotificationsLoading] = useState(false);
  const [notificationsError, setNotificationsError] = useState('');
  const [notificationsBusyId, setNotificationsBusyId] = useState('');
  const [notificationsSyncedAt, setNotificationsSyncedAt] = useState('');
  const [unreadCount, setUnreadCount] = useState(0);
  const [profileDialogOpen, setProfileDialogOpen] = useState(false);
  const [passwordDialogOpen, setPasswordDialogOpen] = useState(false);
  const [profileName, setProfileName] = useState('');
  const [profileSaving, setProfileSaving] = useState(false);
  const [profileError, setProfileError] = useState('');
  const [passwordSaving, setPasswordSaving] = useState(false);
  const [passwordError, setPasswordError] = useState('');
  const [passwordForm, setPasswordForm] = useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  });

  const mainMenuRef = useRef<HTMLDivElement | null>(null);
  const bellMenuRef = useRef<HTMLDivElement | null>(null);
  const profileMenuRef = useRef<HTMLDivElement | null>(null);
  const profileInputRef = useRef<HTMLInputElement | null>(null);
  const currentPasswordRef = useRef<HTMLInputElement | null>(null);

  const currentUser = session?.user ?? null;
  const themeActionLabel = theme === THEME_LIGHT ? 'Tema escuro' : 'Tema claro';
  const themeActionIcon = theme === THEME_LIGHT ? IconMoon : IconSun;
  const canCreateOrder = hasPermission(currentUser, 'os', 'criar');
  const ordersActive = pathname.startsWith('/os') && pathname !== '/os/novo';

  useEffect(() => {
    const initialTheme = getPreferredTheme();
    setTheme(initialTheme);
    setMounted(true);
    applyThemePreference(initialTheme);
  }, []);

  useEffect(() => {
    if (!mounted) {
      return;
    }

    applyThemePreference(theme);
  }, [mounted, theme]);

  useEffect(() => {
    setProfileName(normalizeText(currentUser?.nome, ''));
  }, [currentUser?.nome]);

  const loadNotifications = useCallback(async () => {
    if (!session?.accessToken) {
      return;
    }

    setNotificationsLoading(true);
    setNotificationsError('');

    try {
      const [unreadResponse, listResponse] = await Promise.all([
        apiListNotifications({ onlyUnread: true, page: 1, perPage: 1 }),
        apiListNotifications({ page: 1, perPage: 6 }),
      ]);

      setNotifications(Array.isArray(listResponse.items) ? listResponse.items : []);
      setUnreadCount(Number(unreadResponse.unread_count ?? 0));
      setNotificationsSyncedAt(new Date().toISOString());
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        clearSession();
        router.replace('/login');
        return;
      }

      setNotificationsError(error instanceof Error ? error.message : 'Nao foi possivel carregar as notificacoes.');
    } finally {
      setNotificationsLoading(false);
    }
  }, [clearSession, router, session?.accessToken]);

  const markNotificationRead = useCallback(
    async (notificationId: string) => {
      if (!session?.accessToken || notificationId === '') {
        return;
      }

      const wasUnread = notifications.some((item) => item.id === notificationId && !item.lida_em);
      setNotificationsBusyId(notificationId);

      try {
        await apiMarkNotificationRead(notificationId);

        const now = new Date().toISOString();
        setNotifications((current) =>
          current.map((item) => (item.id === notificationId ? { ...item, lida_em: now } : item))
        );

        if (wasUnread) {
          setUnreadCount((current) => Math.max(0, current - 1));
        }

        void loadNotifications();
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          clearSession();
          router.replace('/login');
          return;
        }

        setNotificationsError(error instanceof Error ? error.message : 'Nao foi possivel marcar a notificacao como lida.');
      } finally {
        setNotificationsBusyId('');
      }
    },
    [clearSession, loadNotifications, notifications, router, session?.accessToken]
  );

  const markAllNotificationsRead = useCallback(async () => {
    if (!session?.accessToken) {
      return;
    }

    setNotificationsLoading(true);

    try {
      await apiMarkAllNotificationsRead();
      const now = new Date().toISOString();

      setNotifications((current) =>
        current.map((item) => (item.lida_em ? item : { ...item, lida_em: now }))
      );
      setUnreadCount(0);
      void loadNotifications();
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        clearSession();
        router.replace('/login');
        return;
      }

      setNotificationsError(error instanceof Error ? error.message : 'Nao foi possivel marcar as notificacoes como lidas.');
    } finally {
      setNotificationsLoading(false);
    }
  }, [clearSession, loadNotifications, router, session?.accessToken]);

  const openNotification = useCallback(
    async (item: MobileNotification) => {
      const route = normalizeText(item.rota_destino, '');

      if (item.id) {
        await markNotificationRead(item.id);
      }

      setBellOpen(false);
      setProfileOpen(false);

      if (isSafeInternalPath(route)) {
        router.push(route);
      }
    },
    [markNotificationRead, router]
  );

  const toggleTheme = useCallback(() => {
    const nextTheme = resolveThemeToggle(theme);
    setTheme(nextTheme);
    setProfileOpen(false);
    applyThemePreference(nextTheme);
  }, [theme]);

  const handleLogout = useCallback(async () => {
    try {
      await apiLogout();
    } catch (error) {
      if (!(error instanceof ApiError && error.status === 401)) {
        console.error('[Mobile] logout falhou', error);
      }
    } finally {
      clearSession();
      router.replace('/login');
    }
  }, [clearSession, router]);

  const openProfileDialog = useCallback(() => {
    setProfileOpen(false);
    setBellOpen(false);
    setProfileError('');
    setProfileName(normalizeText(currentUser?.nome, ''));
    setProfileDialogOpen(true);
  }, [currentUser?.nome]);

  const openPasswordDialog = useCallback(() => {
    setProfileOpen(false);
    setBellOpen(false);
    setPasswordError('');
    setPasswordForm({
      current_password: '',
      password: '',
      password_confirmation: '',
    });
    setPasswordDialogOpen(true);
  }, []);

  const closeProfileDialog = useCallback(() => {
    setProfileDialogOpen(false);
    setProfileError('');
  }, []);

  const closePasswordDialog = useCallback(() => {
    setPasswordDialogOpen(false);
    setPasswordError('');
  }, []);

  useEffect(() => {
    if (!session?.accessToken) {
      setNotifications([]);
      setUnreadCount(0);
      setNotificationsLoading(false);
      setNotificationsError('');
      setNotificationsSyncedAt('');
      return undefined;
    }

    let cancelled = false;

    const syncNotifications = async () => {
      if (cancelled) {
        return;
      }

      await loadNotifications();
    };

    void syncNotifications();

    const handleFocus = () => {
      void syncNotifications();
    };

    const handleVisibility = () => {
      if (document.visibilityState === 'visible') {
        void syncNotifications();
      }
    };

    window.addEventListener('focus', handleFocus);
    document.addEventListener('visibilitychange', handleVisibility);

    const intervalId = window.setInterval(() => {
      if (document.visibilityState === 'visible') {
        void syncNotifications();
      }
    }, 60000);

    return () => {
      cancelled = true;
      window.removeEventListener('focus', handleFocus);
      document.removeEventListener('visibilitychange', handleVisibility);
      window.clearInterval(intervalId);
    };
  }, [loadNotifications, session?.accessToken]);

  useEffect(() => {
    const handlePointerDown = (event: PointerEvent) => {
      const target = event.target;

      if (target instanceof Node) {
        if (mainMenuRef.current && !mainMenuRef.current.contains(target)) {
          setMainMenuOpen(false);
        }

        if (bellMenuRef.current && !bellMenuRef.current.contains(target)) {
          setBellOpen(false);
        }

        if (profileMenuRef.current && !profileMenuRef.current.contains(target)) {
          setProfileOpen(false);
        }
      }
    };

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setMainMenuOpen(false);
        setBellOpen(false);
        setProfileOpen(false);
        setProfileDialogOpen(false);
        setPasswordDialogOpen(false);
      }
    };

    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('keydown', handleEscape);

    return () => {
      document.removeEventListener('pointerdown', handlePointerDown);
      document.removeEventListener('keydown', handleEscape);
    };
  }, []);

  useEffect(() => {
    if (!profileDialogOpen) {
      return undefined;
    }

    profileInputRef.current?.focus();

    document.body.classList.add('profile-dialog-open');

    return () => {
      document.body.classList.remove('profile-dialog-open');
    };
  }, [profileDialogOpen]);

  useEffect(() => {
    if (!passwordDialogOpen) {
      return undefined;
    }

    currentPasswordRef.current?.focus();

    document.body.classList.add('password-dialog-open');

    return () => {
      document.body.classList.remove('password-dialog-open');
    };
  }, [passwordDialogOpen]);

  const handleProfileSubmit = useCallback(
    async (event: React.FormEvent<HTMLFormElement>) => {
      event.preventDefault();

      if (!session?.accessToken) {
        return;
      }

      setProfileSaving(true);
      setProfileError('');

      try {
        const updatedUser = await apiUpdateProfile({
          nome: profileName.trim(),
        });

        if (!updatedUser) {
          throw new Error('O backend nao retornou o usuario atualizado.');
        }

        if (session) {
          setSession({
            ...session,
            user: updatedUser,
          });
        }

        setProfileDialogOpen(false);
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          clearSession();
          router.replace('/login');
          return;
        }

        setProfileError(error instanceof Error ? error.message : 'Nao foi possivel salvar o nome de perfil.');
      } finally {
        setProfileSaving(false);
      }
    },
    [clearSession, profileName, router, session, setSession]
  );

  const handlePasswordSubmit = useCallback(
    async (event: React.FormEvent<HTMLFormElement>) => {
      event.preventDefault();

      if (!session?.accessToken) {
        return;
      }

      setPasswordSaving(true);
      setPasswordError('');

      try {
        const response = await apiUpdatePassword({
          current_password: passwordForm.current_password,
          password: passwordForm.password,
          password_confirmation: passwordForm.password_confirmation,
        });

        if (response.requires_relogin) {
          closePasswordDialog();
          clearSession();
          router.replace('/login');
          return;
        }

        closePasswordDialog();
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          clearSession();
          router.replace('/login');
          return;
        }

        setPasswordError(error instanceof Error ? error.message : 'Nao foi possivel alterar a senha.');
      } finally {
        setPasswordSaving(false);
      }
    },
    [clearSession, closePasswordDialog, passwordForm, router, session?.accessToken]
  );

  const handleBellToggle = useCallback(() => {
    const next = !bellOpen;
    setBellOpen(next);
    setMainMenuOpen(false);
    setProfileOpen(false);

    if (next) {
      void loadNotifications();
    }
  }, [bellOpen, loadNotifications]);

  const handleProfileToggle = useCallback(() => {
    const next = !profileOpen;
    setProfileOpen(next);
    setMainMenuOpen(false);
    setBellOpen(false);
  }, [profileOpen]);

  const handleMainMenuToggle = useCallback(() => {
    const next = !mainMenuOpen;
    setMainMenuOpen(next);
    setBellOpen(false);
    setProfileOpen(false);
  }, [mainMenuOpen]);

  if (!session?.accessToken) {
    return <>{children}</>;
  }

  return (
    <div className="authenticated-shell">
      <header className="app-navbar">
        <div className="app-navbar-inner">
          <div className="app-navbar-leading">
            <div className="nav-action-group" ref={mainMenuRef}>
              <button
                className="icon-button"
                type="button"
                onClick={handleMainMenuToggle}
                aria-controls="app-main-menu"
                aria-haspopup="menu"
                aria-expanded={mainMenuOpen}
                aria-label="Abrir menu principal"
              >
                <IconMenu />
              </button>

              {mainMenuOpen ? (
                <div
                  id="app-main-menu"
                  className="nav-popover nav-popover-menu"
                  role="menu"
                  aria-label="Menu principal"
                >
                  <div className="popover-header">
                    <div>
                      <span className="popover-kicker">Aplicativo</span>
                      <strong>Menu principal</strong>
                    </div>
                  </div>
                  <PwaInstallButton variant="menu" />
                </div>
              ) : null}
            </div>

            <Link className="app-brand" href="/" aria-label="Ir para a área de trabalho">
              <span className="app-brand-mark">ERP</span>
              <span className="app-brand-copy">
                <strong>Sistema ERP</strong>
                <span>Mobile operacional</span>
              </span>
            </Link>
          </div>

          <div className="app-navbar-actions">
            <div className="nav-action-group" ref={bellMenuRef}>
              <button
                className="icon-button icon-button-badge"
                type="button"
                onClick={handleBellToggle}
                aria-haspopup="menu"
                aria-expanded={bellOpen}
                aria-label={`Notificacoes${unreadCount > 0 ? `, ${unreadCount} novas` : ''}`}
              >
                <IconBell />
                {unreadCount > 0 ? (
                  <span className="nav-badge">{unreadCount > 99 ? '99+' : unreadCount}</span>
                ) : null}
              </button>

              {bellOpen ? (
                <div className="nav-popover nav-popover-bell" role="menu" aria-label="Notificacoes recentes">
                  <div className="popover-header">
                    <div>
                      <span className="popover-kicker">Notificacoes</span>
                      <strong>{unreadCount > 0 ? `${unreadCount} nao lidas` : 'Tudo em dia'}</strong>
                      <p>{formatDateTime(notificationsSyncedAt, 'Ainda nao sincronizado')}</p>
                    </div>
                    <button
                      className="button button-secondary button-small"
                      type="button"
                      onClick={() => void loadNotifications()}
                      disabled={notificationsLoading}
                    >
                      {notificationsLoading ? 'Atualizando...' : 'Atualizar'}
                    </button>
                  </div>

                  {notificationsError ? <div className="notice notice-error">{notificationsError}</div> : null}

                  {notificationsLoading && notifications.length === 0 ? (
                    <div className="empty-state">
                      <strong>Carregando notificacoes...</strong>
                      <p>Buscando os eventos mais recentes da sua conta.</p>
                    </div>
                  ) : notifications.length > 0 ? (
                    <div className="notification-stack">
                      {notifications.map((item, index) => (
                        <NotificationItem
                          key={item.id || `${index}-${item.titulo}`}
                          item={item}
                          busy={notificationsBusyId === item.id}
                          onMarkRead={markNotificationRead}
                          onOpen={openNotification}
                        />
                      ))}
                    </div>
                  ) : (
                    <div className="empty-state">
                      <strong>Sem notificacoes no momento.</strong>
                      <p>Quando surgirem novos eventos, eles vao aparecer aqui automaticamente.</p>
                    </div>
                  )}

                  <div className="popover-footer">
                    <button
                      className="button button-secondary button-small button-full"
                      type="button"
                      onClick={() => void markAllNotificationsRead()}
                      disabled={notificationsLoading || unreadCount === 0}
                    >
                      Marcar todas como lidas
                    </button>
                  </div>
                </div>
              ) : null}
            </div>

          </div>
        </div>
      </header>

      <div className="authenticated-content">{children}</div>

      {pathname === '/os/novo' ? (
        <OrderCreationPlayer />
      ) : (
        <nav className="app-bottom-nav" aria-label="Navegação principal">
        <div className="app-bottom-nav-inner">
          <Link
            href="/"
            className={`app-bottom-nav-item${pathname === '/' ? ' app-bottom-nav-item--active' : ''}`}
            aria-current={pathname === '/' ? 'page' : undefined}
          >
            <IconHome />
            <span>Início</span>
          </Link>

          <Link
            href="/os"
            className={`app-bottom-nav-item${ordersActive ? ' app-bottom-nav-item--active' : ''}`}
            aria-current={ordersActive ? 'page' : undefined}
          >
            <IconOrders />
            <span>OS</span>
          </Link>

          {canCreateOrder ? (
            <Link
              href="/os/novo"
              className={`app-bottom-nav-item app-bottom-nav-item--primary${pathname === '/os/novo' ? ' app-bottom-nav-item--active' : ''}`}
              aria-current={pathname === '/os/novo' ? 'page' : undefined}
              aria-label="Criar nova OS"
            >
              <IconPlus />
              <span>Nova OS</span>
            </Link>
          ) : (
            <button
              className="app-bottom-nav-item app-bottom-nav-item--primary app-bottom-nav-item--disabled"
              type="button"
              disabled
              aria-label="Criar nova OS, sem permissão"
              title="Seu perfil não possui permissão para criar OS"
            >
              <IconPlus />
              <span>Nova OS</span>
            </button>
          )}

          <button
            className="app-bottom-nav-item app-bottom-nav-item--disabled"
            type="button"
            disabled
            aria-label="Orçamentos, disponível futuramente"
            title="Orçamentos será disponibilizado em uma próxima implementação"
          >
            <IconBudget />
            <span>Orçamentos</span>
          </button>

          <div className="nav-action-group nav-action-group-profile-bottom" ref={profileMenuRef}>
            <button
              className={`app-bottom-nav-item${profileOpen ? ' app-bottom-nav-item--active' : ''}`}
              type="button"
              onClick={handleProfileToggle}
              aria-haspopup="menu"
              aria-expanded={profileOpen}
              aria-label="Abrir perfil do usuário"
            >
              <IconProfile />
              <span>Perfil</span>
            </button>

            {profileOpen ? (
              <div className="nav-popover nav-popover-profile nav-popover-profile-bottom" role="menu" aria-label="Menu de perfil">
                <div className="popover-header popover-header-profile">
                  <Avatar name={normalizeText(currentUser?.nome, 'Operador')} size="lg" />
                  <div>
                    <strong>{normalizeText(currentUser?.nome, 'Operador')}</strong>
                    <p>{normalizeText(currentUser?.email, 'Sem e-mail informado')}</p>
                  </div>
                </div>

                <div className="menu-stack">
                  <MenuAction onClick={toggleTheme} icon={themeActionIcon}>
                    {themeActionLabel}
                  </MenuAction>
                  <MenuAction onClick={openProfileDialog} icon={IconEdit}>
                    Editar nome
                  </MenuAction>
                  <MenuAction onClick={openPasswordDialog} icon={IconLock}>
                    Alterar senha
                  </MenuAction>
                  <MenuAction onClick={() => void handleLogout()} tone="danger" icon={IconLogout}>
                    Sair
                  </MenuAction>
                </div>
              </div>
            ) : null}
          </div>
        </div>
        </nav>
      )}

      {profileDialogOpen ? (
        <ModalFrame
          title="Editar nome"
          subtitle="Atualize o nome exibido no painel e nos menus do app."
          onClose={closeProfileDialog}
        >
          <form className="modal-form" onSubmit={handleProfileSubmit}>
            <label className="field">
              <span>Nome de perfil</span>
              <input
                ref={profileInputRef}
                type="text"
                value={profileName}
                onChange={(event) => setProfileName(event.target.value)}
                maxLength={120}
                placeholder="Seu nome de exibicao"
                autoComplete="name"
                required
              />
            </label>

            {profileError ? <div className="notice notice-error">{profileError}</div> : null}

            <div className="dialog-actions">
              <button className="button button-secondary" type="button" onClick={closeProfileDialog}>
                Cancelar
              </button>
              <button className="button button-primary" type="submit" disabled={profileSaving}>
                {profileSaving ? 'Salvando...' : 'Salvar nome'}
              </button>
            </div>
          </form>
        </ModalFrame>
      ) : null}

      {passwordDialogOpen ? (
        <ModalFrame
          title="Alterar senha"
          subtitle="A troca encerra as sessoes moveis e exige novo login."
          onClose={closePasswordDialog}
        >
          <form className="modal-form" onSubmit={handlePasswordSubmit}>
            <label className="field">
              <span>Senha atual</span>
              <input
                ref={currentPasswordRef}
                type="password"
                value={passwordForm.current_password}
                onChange={(event) =>
                  setPasswordForm((current) => ({ ...current, current_password: event.target.value }))
                }
                autoComplete="current-password"
                required
              />
            </label>

            <label className="field">
              <span>Nova senha</span>
              <input
                type="password"
                value={passwordForm.password}
                onChange={(event) => setPasswordForm((current) => ({ ...current, password: event.target.value }))}
                autoComplete="new-password"
                minLength={8}
                required
              />
            </label>

            <label className="field">
              <span>Confirmar nova senha</span>
              <input
                type="password"
                value={passwordForm.password_confirmation}
                onChange={(event) =>
                  setPasswordForm((current) => ({ ...current, password_confirmation: event.target.value }))
                }
                autoComplete="new-password"
                minLength={8}
                required
              />
            </label>

            {passwordError ? <div className="notice notice-error">{passwordError}</div> : null}

            <div className="dialog-actions">
              <button className="button button-secondary" type="button" onClick={closePasswordDialog}>
                Cancelar
              </button>
              <button className="button button-primary" type="submit" disabled={passwordSaving}>
                {passwordSaving ? 'Atualizando...' : 'Salvar senha'}
              </button>
            </div>
          </form>
        </ModalFrame>
      ) : null}
    </div>
  );
}

export function AuthenticatedShell({ children }: { children: ReactNode }) {
  return (
    <OrderCreationPlayerProvider>
      <AuthenticatedShellContent>{children}</AuthenticatedShellContent>
    </OrderCreationPlayerProvider>
  );
}

export default AuthenticatedShell;
