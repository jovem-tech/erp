import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, apiListOrders, apiLogout } from '@/lib/api';
import { MOBILE_SESSION_STORAGE_KEY, writeStoredSession } from '@/lib/session';

function jsonResponse(body: unknown, init: { ok?: boolean; status?: number } = {}): Response {
  return {
    ok: init.ok ?? true,
    status: init.status ?? 200,
    headers: new Headers({ 'content-type': 'application/json' }),
    json: async () => body,
  } as Response;
}

function storeFakeSession(): void {
  writeStoredSession({
    accessToken: 'token-abc',
    tokenType: 'Bearer',
    expiresAt: new Date(Date.now() + 60 * 60 * 1000).toISOString(),
    user: {
      id: 1,
      nome: 'Técnico',
      email: 'tecnico@example.com',
      perfil: 'tecnico',
      grupo_id: 1,
      foto: '',
      ativo: true,
      ultimo_acesso: null,
    },
  });
}

describe('ApiError', () => {
  it('carries status, code and details', () => {
    const error = new ApiError('Falha', 422, 'VALIDATION_ERROR', { campo: ['obrigatório'] });

    expect(error.message).toBe('Falha');
    expect(error.status).toBe(422);
    expect(error.code).toBe('VALIDATION_ERROR');
    expect(error.details).toEqual({ campo: ['obrigatório'] });
    expect(error).toBeInstanceOf(Error);
  });
});

describe('apiLogout', () => {
  beforeEach(() => {
    window.localStorage.clear();
    storeFakeSession();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('clears the stored session when the server call succeeds', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      jsonResponse({ status: 'success', data: null, error: null })
    ));

    await apiLogout();

    expect(window.localStorage.getItem(MOBILE_SESSION_STORAGE_KEY)).toBeNull();
  });

  it('still clears the stored session when the server call fails', async () => {
    // Regressão: antes da Fase 1 da auditoria de 2026-06-25, existiam duas
    // declarações de `apiLogout` no mesmo módulo — a que "vencia" em runtime
    // não limpava a sessão local. Este teste trava esse comportamento.
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      jsonResponse({ status: 'error', data: null, error: { code: 'SERVER_ERROR', message: 'Falha' } }, { ok: false, status: 500 })
    ));

    await expect(apiLogout()).rejects.toThrow('Falha');

    expect(window.localStorage.getItem(MOBILE_SESSION_STORAGE_KEY)).toBeNull();
  });

  it('still clears the stored session when the network request throws', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Network error')));

    await expect(apiLogout()).rejects.toThrow();

    expect(window.localStorage.getItem(MOBILE_SESSION_STORAGE_KEY)).toBeNull();
  });
});

describe('apiListOrders', () => {
  beforeEach(() => {
    window.localStorage.clear();
    storeFakeSession();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('normalizes pagination from the API envelope metadata', async () => {
    const pagination = {
      current_page: 1,
      per_page: 24,
      total: 37,
      last_page: 2,
      from: 1,
      to: 24,
    };
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        status: 'success',
        data: { orders: [{ id: 3648 }] },
        error: null,
        meta: { pagination },
      })
    );
    vi.stubGlobal('fetch', fetchMock);

    const result = await apiListOrders({ status: 'triagem', per_page: 24 });

    expect(result).toEqual({
      orders: [{ id: 3648 }],
      pagination,
    });
    expect(String(fetchMock.mock.calls[0][0])).toContain('/orders?status=triagem&per_page=24');
  });

  it('rejects a successful response that omits pagination metadata', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      jsonResponse({
        status: 'success',
        data: { orders: [] },
        error: null,
      })
    ));

    await expect(apiListOrders()).rejects.toMatchObject({
      status: 502,
      code: 'INVALID_API_RESPONSE',
    });
  });
});
