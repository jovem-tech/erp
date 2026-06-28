import type { ChatSession } from '@/lib/types';

export const CHAT_SESSION_STORAGE_KEY = 'sistema-erp.chat.session';
export const CHAT_SESSION_EVENT = 'sistema-erp:chat-session-changed';

function hasWindow(): boolean {
  return typeof window !== 'undefined';
}

function toStringValue(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function toNumberValue(value: unknown): number {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : 0;
}

export function normalizeSession(session: ChatSession): ChatSession {
  return {
    accessToken: toStringValue(session.accessToken).trim(),
    tokenType: 'Bearer',
    expiresAt: toStringValue(session.expiresAt),
    user: {
      id: toNumberValue(session.user?.id),
      nome: toStringValue(session.user?.nome),
      email: toStringValue(session.user?.email),
      perfil: toStringValue(session.user?.perfil),
      grupo_id: toNumberValue(session.user?.grupo_id),
      foto: toStringValue(session.user?.foto),
      ativo: Boolean(session.user?.ativo),
      ultimo_acesso: session.user?.ultimo_acesso ?? null,
    },
  };
}

export function readStoredSession(): ChatSession | null {
  if (!hasWindow()) {
    return null;
  }

  try {
    const raw = window.localStorage.getItem(CHAT_SESSION_STORAGE_KEY);
    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw) as ChatSession;
    const normalized = normalizeSession(parsed);

    if (normalized.accessToken === '' || normalized.expiresAt === '') {
      return null;
    }

    return normalized;
  } catch {
    return null;
  }
}

export function writeStoredSession(session: ChatSession): ChatSession {
  if (!hasWindow()) {
    return normalizeSession(session);
  }

  const normalized = normalizeSession(session);
  window.localStorage.setItem(CHAT_SESSION_STORAGE_KEY, JSON.stringify(normalized));
  emitSessionChange();

  return normalized;
}

export function clearStoredSession(): void {
  if (!hasWindow()) {
    return;
  }

  window.localStorage.removeItem(CHAT_SESSION_STORAGE_KEY);
  emitSessionChange();
}

export function emitSessionChange(): void {
  if (!hasWindow()) {
    return;
  }

  window.dispatchEvent(new Event(CHAT_SESSION_EVENT));
}

export function isSessionExpired(session: ChatSession | null): boolean {
  if (!session) {
    return true;
  }

  const expiresAt = Date.parse(session.expiresAt);
  if (Number.isNaN(expiresAt)) {
    return true;
  }

  return expiresAt <= Date.now();
}

export function sessionExpiresInMinutes(session: ChatSession | null): number {
  if (!session) {
    return 0;
  }

  const expiresAt = Date.parse(session.expiresAt);
  if (Number.isNaN(expiresAt)) {
    return 0;
  }

  return Math.max(0, Math.floor((expiresAt - Date.now()) / 60000));
}

export function isSessionExpiringSoon(session: ChatSession | null, thresholdMinutes = 720): boolean {
  return sessionExpiresInMinutes(session) > 0 && sessionExpiresInMinutes(session) <= thresholdMinutes;
}

export function formatSessionExpiration(session: ChatSession | null): string {
  if (!session) {
    return '';
  }

  const expiresAt = Date.parse(session.expiresAt);
  if (Number.isNaN(expiresAt)) {
    return '';
  }

  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(expiresAt));
}

let refreshTimer: ReturnType<typeof setTimeout> | null = null;
const REFRESH_WARNING_MINUTES = 5;

export function scheduleTokenRefresh(session: ChatSession): void {
  if (refreshTimer) {
    clearTimeout(refreshTimer);
    refreshTimer = null;
  }

  const expiresAt = Date.parse(session.expiresAt);
  if (Number.isNaN(expiresAt)) {
    return;
  }

  const msUntilExpire = expiresAt - Date.now();
  if (msUntilExpire < 60000) {
    return;
  }

  const msUntilRefresh = msUntilExpire - REFRESH_WARNING_MINUTES * 60 * 1000;

  if (msUntilRefresh > 0) {
    refreshTimer = setTimeout(() => {
      window.dispatchEvent(
        new CustomEvent('sistema-erp:chat-token-refresh-needed', {
          detail: { expiresInMinutes: REFRESH_WARNING_MINUTES },
        })
      );
    }, msUntilRefresh);
  }
}

export function cancelTokenRefresh(): void {
  if (refreshTimer) {
    clearTimeout(refreshTimer);
    refreshTimer = null;
  }
}

export function storeSessionWithAutoRefresh(session: ChatSession): ChatSession {
  const stored = writeStoredSession(session);
  scheduleTokenRefresh(stored);
  return stored;
}
