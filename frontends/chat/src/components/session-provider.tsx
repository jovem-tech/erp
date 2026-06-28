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
  CHAT_SESSION_EVENT,
  readStoredSession,
  writeStoredSession,
} from '@/lib/session';
import type { ChatSession } from '@/lib/types';

type SessionContextValue = {
  session: ChatSession | null;
  ready: boolean;
  booting: boolean;
  setSession: (session: ChatSession | null) => void;
  clearSession: () => void;
  refreshSession: () => Promise<ChatSession | null>;
};

const SessionContext = createContext<SessionContextValue | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [session, setSessionState] = useState<ChatSession | null>(null);
  const [ready, setReady] = useState(false);
  const [booting, setBooting] = useState(false);

  const setSession = useCallback((nextSession: ChatSession | null) => {
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

  const refreshSession = useCallback(async (): Promise<ChatSession | null> => {
    if (!session) {
      return null;
    }

    const refreshed = await apiRefresh();
    const nextSession: ChatSession = {
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

      const storedSession = readStoredSession();
      if (!storedSession || isSessionExpired(storedSession)) {
        clearStoredSession();
        if (!cancelled) {
          setSessionState(null);
          setReady(true);
          setBooting(false);
        }
        return;
      }

      if (!cancelled) {
        setSessionState(storedSession);
      }

      try {
        const currentUser = await apiMe();
        if (cancelled) {
          return;
        }

        const normalizedSession: ChatSession = {
          ...storedSession,
          user: currentUser,
        };

        setSessionState(writeStoredSession(normalizedSession));

        if (isSessionExpiringSoon(normalizedSession)) {
          try {
            const refreshed = await apiRefresh();
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
      if (event.key === null || event.key === 'sistema-erp.chat.session') {
        syncFromStorage();
      }
    };

    window.addEventListener('storage', onStorage);
    window.addEventListener(CHAT_SESSION_EVENT, syncFromStorage);

    return () => {
      window.removeEventListener('storage', onStorage);
      window.removeEventListener(CHAT_SESSION_EVENT, syncFromStorage);
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
