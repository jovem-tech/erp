import { describe, expect, it } from 'vitest';
import { isSessionExpired, normalizeSession } from '@/lib/session';
import type { ChatSession } from '@/lib/types';

function buildSession(overrides: Partial<ChatSession> = {}): ChatSession {
  return {
    accessToken: 'token-123',
    tokenType: 'Bearer',
    expiresAt: new Date(Date.now() + 60 * 60 * 1000).toISOString(),
    user: {
      id: 1,
      nome: 'Atendente Teste',
      email: 'atendente@example.com',
      perfil: 'atendente',
      grupo_id: 3,
      foto: '',
      ativo: true,
      ultimo_acesso: null,
    },
    ...overrides,
  };
}

describe('isSessionExpired', () => {
  it('retorna true para sessao nula', () => {
    expect(isSessionExpired(null)).toBe(true);
  });

  it('retorna false para sessao com expiracao no futuro', () => {
    expect(isSessionExpired(buildSession())).toBe(false);
  });

  it('retorna true para sessao com expiracao no passado', () => {
    const expired = buildSession({ expiresAt: new Date(Date.now() - 1000).toISOString() });
    expect(isSessionExpired(expired)).toBe(true);
  });

  it('retorna true para expiresAt invalido', () => {
    const invalid = buildSession({ expiresAt: 'nao-e-uma-data' });
    expect(isSessionExpired(invalid)).toBe(true);
  });
});

describe('normalizeSession', () => {
  it('preenche valores ausentes do usuario com defaults seguros', () => {
    const normalized = normalizeSession({
      accessToken: 'abc',
      tokenType: 'Bearer',
      expiresAt: '2026-01-01T00:00:00Z',
      user: {} as ChatSession['user'],
    });

    expect(normalized.user.id).toBe(0);
    expect(normalized.user.nome).toBe('');
    expect(normalized.user.ativo).toBe(false);
    expect(normalized.tokenType).toBe('Bearer');
  });

  it('preserva valores validos', () => {
    const session = buildSession();
    const normalized = normalizeSession(session);

    expect(normalized.accessToken).toBe('token-123');
    expect(normalized.user.nome).toBe('Atendente Teste');
    expect(normalized.user.ativo).toBe(true);
  });
});
