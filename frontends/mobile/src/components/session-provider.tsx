'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { apiMe, apiRefresh, ApiError } from '@/lib/api';
import {
  clearStoredSession,
  emitSessionChange,
  isSessionExpired,
  isSessionExpiringSoon,
  MOBILE_SESSION_EVENT,
  readStoredSession,
  writeStoredSession,
} from '@/lib/session';
import type { MobileSession } from '@/lib/types';

type SessionContextValue = {
  session: MobileSession | null;
  ready: boolean;
  booting: boolean;
  setSession: (session: MobileSession | null) => void;
  clearSession: () => void;
  refreshSession: () => Promise<MobileSession | null>;
};

const SessionContext = createContext<SessionContextValue | null>(null);

export const SESSION_BOOTSTRAP_TIMEOUT_MS = 8_000;

class SessionBootstrapTimeoutError extends Error {
  constructor() {
    super('A validacao da sessao excedeu o tempo limite.');
    this.name = 'SessionBootstrapTimeoutError';
  }
}

async function runWithBootstrapDeadline<T>(request: (signal: AbortSignal) => Promise<T>): Promise<T> {
  const controller = new AbortController();
  let timeoutId: ReturnType<typeof setTimeout> | null = null;

  const timeout = new Promise<never>((_, reject) => {
    timeoutId = setTimeout(() => {
      controller.abort();
      reject(new SessionBootstrapTimeoutError());
    }, SESSION_BOOTSTRAP_TIMEOUT_MS);
  });

  try {
    return await Promise.race([request(controller.signal), timeout]);
  } finally {
    if (timeoutId !== null) {
      clearTimeout(timeoutId);
    }
  }
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [session, setSessionState] = useState<MobileSession | null>(null);
  const [ready, setReady] = useState(false);
  const [booting, setBooting] = useState(false);

  const setSession = useCallback((nextSession: MobileSession | null) => {
    if (!nextSession) {
      clearStoredSession();
      setSessionState(null);
      return;
    }

    setSessionState(writeStoredSession(nextSession));
  }, []);

  const clearSession = useCallback(() => {
    clearStoredSession();
    setSessionState(null);
  }, []);

  const refreshSession = useCallback(async (): Promise<MobileSession | null> => {
    if (!session) {
      return null;
    }

    const refreshed = await apiRefresh();
    const nextSession: MobileSession = {
      ...session,
      accessToken: refreshed.accessToken,
      tokenType: refreshed.tokenType,
      expiresAt: refreshed.expiresAt,
    };

    setSession(nextSession);
    return nextSession;
  }, [session, setSession]);

  useEffect(() => {
    let cancelled = false;

    const bootstrap = async (): Promise<void> => {
      setBooting(true);

      try {
        const storedSession = readStoredSession();
        if (!storedSession || isSessionExpired(storedSession)) {
          clearStoredSession();
          if (!cancelled) {
            setSessionState(null);
          }
          return;
        }

        if (!cancelled) {
          setSessionState(storedSession);
        }

        try {
          const currentUser = await runWithBootstrapDeadline((signal) => apiMe(signal));
          if (cancelled) {
            return;
          }

          const normalizedSession: MobileSession = {
            ...storedSession,
            user: currentUser,
          };

          setSessionState(writeStoredSession(normalizedSession));

          if (isSessionExpiringSoon(normalizedSession)) {
            try {
              const refreshed = await runWithBootstrapDeadline((signal) => apiRefresh(signal));
              if (cancelled) {
                return;
              }

              setSessionState(writeStoredSession({
                ...normalizedSession,
                accessToken: refreshed.accessToken,
                tokenType: refreshed.tokenType,
                expiresAt: refreshed.expiresAt,
              }));
            } catch (refreshError) {
              if (refreshError instanceof ApiError && refreshError.status === 401) {
                clearStoredSession();
                setSessionState(null);
              }
            }
          }
        } catch (error) {
          if (cancelled) {
            return;
          }

          if (error instanceof ApiError && error.status === 401) {
            clearStoredSession();
            setSessionState(null);
          }
        }
      } catch {
        if (!cancelled) {
          setSessionState(null);
        }
      } finally {
        if (!cancelled) {
          setReady(true);
          setBooting(false);
        }
      }
    };

    void bootstrap();

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    const syncFromStorage = (): void => {
      const storedSession = readStoredSession();
      if (!storedSession) {
        setSessionState(null);
        return;
      }

      if (isSessionExpired(storedSession)) {
        clearStoredSession();
        setSessionState(null);
        return;
      }

      setSessionState(storedSession);
    };

    const onStorage = (event: StorageEvent): void => {
      if (
        event.key === null ||
        (event.key === 'sistema-erp.mobile.session' && event.newValue === null)
      ) {
        clearStoredSession();
        setSessionState(null);
        return;
      }

      if (event.key === 'sistema-erp.mobile.session') {
        syncFromStorage();
      }
    };

    window.addEventListener('storage', onStorage);
    window.addEventListener(MOBILE_SESSION_EVENT, syncFromStorage);

    return () => {
      window.removeEventListener('storage', onStorage);
      window.removeEventListener(MOBILE_SESSION_EVENT, syncFromStorage);
    };
  }, []);

  const value = useMemo<SessionContextValue>(() => ({
    session,
    ready,
    booting,
    setSession,
    clearSession,
    refreshSession,
  }), [booting, clearSession, ready, refreshSession, session, setSession]);

  return (
    <SessionContext.Provider value={value}>
      {children}
    </SessionContext.Provider>
  );
}

export function useSession(): SessionContextValue {
  const context = useContext(SessionContext);
  if (!context) {
    throw new Error('useSession deve ser usado dentro de SessionProvider.');
  }

  return context;
}

export function notifySessionSync(): void {
  emitSessionChange();
}
