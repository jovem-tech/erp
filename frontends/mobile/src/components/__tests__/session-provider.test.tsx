import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  SESSION_BOOTSTRAP_TIMEOUT_MS,
  SessionProvider,
  useSession,
} from '@/components/session-provider';
import {
  clearStoredSession,
  MOBILE_SESSION_STORAGE_KEY,
  writeStoredSession,
} from '@/lib/session';
import type { MobileSession } from '@/lib/types';

const apiMocks = vi.hoisted(() => ({
  apiMe: vi.fn(),
  apiRefresh: vi.fn(),
}));

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>();

  return {
    ...actual,
    apiMe: apiMocks.apiMe,
    apiRefresh: apiMocks.apiRefresh,
  };
});

function buildSession(): MobileSession {
  return {
    accessToken: 'token-ios',
    tokenType: 'Bearer',
    expiresAt: new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString(),
    user: {
      id: 1,
      nome: 'Tecnico iOS',
      email: 'ios@example.com',
      perfil: 'tecnico',
      grupo_id: 2,
      foto: '',
      ativo: true,
      ultimo_acesso: null,
      permissions: {
        os: ['visualizar'],
      },
    },
  };
}

function SessionProbe() {
  const { booting, ready, session } = useSession();

  return (
    <div>
      <span data-testid="ready">{ready ? 'ready' : 'pending'}</span>
      <span data-testid="booting">{booting ? 'booting' : 'idle'}</span>
      <span data-testid="session">{session?.accessToken ?? 'anonymous'}</span>
    </div>
  );
}

describe('SessionProvider bootstrap', () => {
  beforeEach(() => {
    clearStoredSession();
    window.localStorage.clear();
    apiMocks.apiMe.mockReset();
    apiMocks.apiRefresh.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    clearStoredSession();
    window.localStorage.clear();
  });

  it('finishes bootstrap when WebKit storage is unavailable', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('Storage unavailable', 'SecurityError');
    });
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new DOMException('Storage unavailable', 'SecurityError');
    });

    render(
      <SessionProvider>
        <SessionProbe />
      </SessionProvider>
    );

    expect(await screen.findByText('ready')).toBeInTheDocument();
    expect(screen.getByTestId('booting')).toHaveTextContent('idle');
    expect(screen.getByTestId('session')).toHaveTextContent('anonymous');
    expect(apiMocks.apiMe).not.toHaveBeenCalled();
  });

  it('releases the UI after the validation deadline instead of syncing forever', async () => {
    vi.useFakeTimers();
    writeStoredSession(buildSession());

    let validationSignal: AbortSignal | undefined;
    apiMocks.apiMe.mockImplementation((signal?: AbortSignal) => {
      validationSignal = signal;
      return new Promise(() => undefined);
    });

    render(
      <SessionProvider>
        <SessionProbe />
      </SessionProvider>
    );

    await act(async () => {
      await Promise.resolve();
    });

    expect(screen.getByTestId('ready')).toHaveTextContent('pending');
    expect(screen.getByTestId('booting')).toHaveTextContent('booting');

    await act(async () => {
      vi.advanceTimersByTime(SESSION_BOOTSTRAP_TIMEOUT_MS);
      await Promise.resolve();
    });

    expect(validationSignal?.aborted).toBe(true);
    expect(screen.getByTestId('ready')).toHaveTextContent('ready');
    expect(screen.getByTestId('booting')).toHaveTextContent('idle');
    expect(screen.getByTestId('session')).toHaveTextContent('token-ios');
  });

  it('still synchronizes logout initiated in another browser context', async () => {
    const storedSession = buildSession();
    writeStoredSession(storedSession);
    apiMocks.apiMe.mockResolvedValue(storedSession.user);

    render(
      <SessionProvider>
        <SessionProbe />
      </SessionProvider>
    );

    expect(await screen.findByText('ready')).toBeInTheDocument();
    expect(screen.getByTestId('session')).toHaveTextContent('token-ios');

    act(() => {
      window.dispatchEvent(new StorageEvent('storage', {
        key: MOBILE_SESSION_STORAGE_KEY,
        newValue: null,
      }));
    });

    expect(screen.getByTestId('session')).toHaveTextContent('anonymous');
  });
});
