import type { MobileSession } from '@/lib/types';

export const MOBILE_SESSION_STORAGE_KEY = 'sistema-erp.mobile.session';
export const MOBILE_SESSION_EVENT = 'sistema-erp:session-changed';

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

export function normalizeSession(session: MobileSession): MobileSession {
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

export function readStoredSession(): MobileSession | null {
  if (!hasWindow()) {
    return null;
  }

  try {
    const raw = window.localStorage.getItem(MOBILE_SESSION_STORAGE_KEY);
    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw) as MobileSession;
    const normalized = normalizeSession(parsed);

    if (normalized.accessToken === '' || normalized.expiresAt === '') {
      return null;
    }

    return normalized;
  } catch {
    return null;
  }
}

export function writeStoredSession(session: MobileSession): MobileSession {
  if (!hasWindow()) {
    return normalizeSession(session);
  }

  const normalized = normalizeSession(session);
  window.localStorage.setItem(MOBILE_SESSION_STORAGE_KEY, JSON.stringify(normalized));
  emitSessionChange();

  return normalized;
}

export function clearStoredSession(): void {
  if (!hasWindow()) {
    return;
  }

  window.localStorage.removeItem(MOBILE_SESSION_STORAGE_KEY);
  emitSessionChange();
}

export function emitSessionChange(): void {
  if (!hasWindow()) {
    return;
  }

  window.dispatchEvent(new Event(MOBILE_SESSION_EVENT));
}

export function isSessionExpired(session: MobileSession | null): boolean {
  if (!session) {
    return true;
  }

  const expiresAt = Date.parse(session.expiresAt);
  if (Number.isNaN(expiresAt)) {
    return true;
  }

  return expiresAt <= Date.now();
}

export function sessionExpiresInMinutes(session: MobileSession | null): number {
  if (!session) {
    return 0;
  }

  const expiresAt = Date.parse(session.expiresAt);
  if (Number.isNaN(expiresAt)) {
    return 0;
  }

  return Math.max(0, Math.floor((expiresAt - Date.now()) / 60000));
}

export function isSessionExpiringSoon(session: MobileSession | null, thresholdMinutes = 720): boolean {
  return sessionExpiresInMinutes(session) > 0 && sessionExpiresInMinutes(session) <= thresholdMinutes;
}

export function formatSessionExpiration(session: MobileSession | null): string {
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

// ============================================================================
// Auto-Refresh Token Management
// ============================================================================

let refreshTimer: NodeJS.Timeout | null = null;
const REFRESH_WARNING_MINUTES = 5; // Refresh 5 minutes before expiry

/**
 * Agendar renovação automática do token
 * 
 * O token será renovado 5 minutos antes de expirar
 */
export function scheduleTokenRefresh(session: MobileSession): void {
  // Limpar agendamento anterior se existir
  if (refreshTimer) {
    clearTimeout(refreshTimer);
    refreshTimer = null;
  }

  const expiresAt = Date.parse(session.expiresAt);
  if (Number.isNaN(expiresAt)) {
    return;
  }

  const now = Date.now();
  const msUntilExpire = expiresAt - now;

  // Não agendar se o token já expirou ou expira em menos de 1 minuto
  if (msUntilExpire < 60000) {
    return;
  }

  // Calcular tempo até refresh (5 minutos antes de expirar)
  const msUntilRefresh = msUntilExpire - (REFRESH_WARNING_MINUTES * 60 * 1000);

  if (msUntilRefresh > 0) {
    refreshTimer = setTimeout(() => {
      // Emitir evento para components fazerem o refresh
      window.dispatchEvent(
        new CustomEvent('sistema-erp:token-refresh-needed', {
          detail: { expiresInMinutes: REFRESH_WARNING_MINUTES },
        })
      );
    }, msUntilRefresh);
  }
}

/**
 * Cancelar agendamento de renovação
 */
export function cancelTokenRefresh(): void {
  if (refreshTimer) {
    clearTimeout(refreshTimer);
    refreshTimer = null;
  }
}

/**
 * Store da sessão que integra agendamento
 */
export function storeSessionWithAutoRefresh(session: MobileSession): MobileSession {
  const stored = writeStoredSession(session);
  scheduleTokenRefresh(stored);
  return stored;
}
