import { describe, expect, it, beforeEach } from 'vitest';
import {
  MOBILE_SESSION_STORAGE_KEY,
  clearStoredSession,
  isSessionExpired,
  isSessionExpiringSoon,
  normalizeSession,
  readStoredSession,
  sessionExpiresInMinutes,
  writeStoredSession,
} from '@/lib/session';
import type { MobileSession } from '@/lib/types';

function buildSession(overrides: Partial<MobileSession> = {}): MobileSession {
  return {
    accessToken: 'token-123',
    tokenType: 'Bearer',
    expiresAt: new Date(Date.now() + 60 * 60 * 1000).toISOString(),
    user: {
      id: 1,
      nome: 'Técnico Teste',
      email: 'tecnico@example.com',
      perfil: 'tecnico',
      grupo_id: 2,
      foto: '',
      ativo: true,
      ultimo_acesso: null,
    },
    ...overrides,
  };
}

beforeEach(() => {
  window.localStorage.clear();
});

describe('normalizeSession', () => {
  it('preserves valid fields and coerces types', () => {
    const session = buildSession({ user: { ...buildSession().user, id: '7' as unknown as number } });
    const normalized = normalizeSession(session);

    expect(normalized.user.id).toBe(7);
    expect(normalized.tokenType).toBe('Bearer');
  });

  it('falls back to empty/zero values for missing user fields', () => {
    const normalized = normalizeSession({
      accessToken: 'abc',
      tokenType: 'Bearer',
      expiresAt: '2026-01-01T00:00:00Z',
      user: undefined as unknown as MobileSession['user'],
    });

    expect(normalized.user.id).toBe(0);
    expect(normalized.user.nome).toBe('');
    expect(normalized.user.ativo).toBe(false);
  });
});

describe('readStoredSession / writeStoredSession / clearStoredSession', () => {
  it('round-trips a session through localStorage', () => {
    const session = buildSession();
    writeStoredSession(session);

    const stored = readStoredSession();
    expect(stored?.accessToken).toBe('token-123');
    expect(stored?.user.email).toBe('tecnico@example.com');
  });

  it('returns null when nothing is stored', () => {
    expect(readStoredSession()).toBeNull();
  });

  it('returns null when the stored session has no token or expiry', () => {
    window.localStorage.setItem(
      MOBILE_SESSION_STORAGE_KEY,
      JSON.stringify({ accessToken: '', tokenType: 'Bearer', expiresAt: '', user: buildSession().user })
    );

    expect(readStoredSession()).toBeNull();
  });

  it('returns null when the stored value is not valid JSON', () => {
    window.localStorage.setItem(MOBILE_SESSION_STORAGE_KEY, '{not-json');

    expect(readStoredSession()).toBeNull();
  });

  it('removes the session on clearStoredSession', () => {
    writeStoredSession(buildSession());
    clearStoredSession();

    expect(readStoredSession()).toBeNull();
    expect(window.localStorage.getItem(MOBILE_SESSION_STORAGE_KEY)).toBeNull();
  });
});

describe('isSessionExpired', () => {
  it('treats a null session as expired', () => {
    expect(isSessionExpired(null)).toBe(true);
  });

  it('treats an unparseable expiresAt as expired', () => {
    expect(isSessionExpired(buildSession({ expiresAt: 'not-a-date' }))).toBe(true);
  });

  it('treats a future expiresAt as not expired', () => {
    expect(isSessionExpired(buildSession())).toBe(false);
  });

  it('treats a past expiresAt as expired', () => {
    const session = buildSession({ expiresAt: new Date(Date.now() - 1000).toISOString() });
    expect(isSessionExpired(session)).toBe(true);
  });
});

describe('sessionExpiresInMinutes / isSessionExpiringSoon', () => {
  it('computes minutes remaining until expiry', () => {
    const session = buildSession({ expiresAt: new Date(Date.now() + 10 * 60 * 1000).toISOString() });
    expect(sessionExpiresInMinutes(session)).toBeGreaterThanOrEqual(9);
    expect(sessionExpiresInMinutes(session)).toBeLessThanOrEqual(10);
  });

  it('flags a session expiring within the threshold', () => {
    const session = buildSession({ expiresAt: new Date(Date.now() + 5 * 60 * 1000).toISOString() });
    expect(isSessionExpiringSoon(session, 10)).toBe(true);
  });

  it('does not flag a session expiring well beyond the threshold', () => {
    const session = buildSession({ expiresAt: new Date(Date.now() + 60 * 60 * 1000).toISOString() });
    expect(isSessionExpiringSoon(session, 10)).toBe(false);
  });
});
