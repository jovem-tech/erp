import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type { ChannelAuthorizationData } from 'pusher-js/types/src/core/auth/options';
import { readStoredSession } from '@/lib/session';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

// Fase 1: uma unica conta (contas_atendimento). Sem selecao de conta no frontend ainda —
// ver specs/010-inbox-whatsapp-tempo-real/spec.md, Assumptions.
export const ACCOUNT_ID = 1;

let echoInstance: Echo<'reverb'> | null = null;

function getApiOrigin(): string {
  const baseUrl = process.env.NEXT_PUBLIC_API_BASE_URL ?? 'http://localhost:8000/api/v1';
  try {
    return new URL(baseUrl).origin;
  } catch {
    return 'http://localhost:8000';
  }
}

/**
 * Cliente Echo singleton, autenticado via Bearer (nao cookie) — o backend so' aceita
 * Sanctum no endpoint /broadcasting/auth (ver
 * specs/010-inbox-whatsapp-tempo-real/plan.md, "Ponto critico de autenticacao").
 */
export function getEcho(): Echo<'reverb'> | null {
  if (typeof window === 'undefined') {
    return null;
  }

  if (echoInstance) {
    return echoInstance;
  }

  window.Pusher = Pusher;
  const apiOrigin = getApiOrigin();

  echoInstance = new Echo({
    broadcaster: 'reverb',
    key: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
    wsHost: process.env.NEXT_PUBLIC_REVERB_HOST ?? 'localhost',
    wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8090),
    wssPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8090),
    forceTLS: (process.env.NEXT_PUBLIC_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
      authorize: (
        socketId: string,
        callback: (error: Error | null, data: ChannelAuthorizationData | null) => void
      ) => {
        const session = readStoredSession();

        fetch(`${apiOrigin}/broadcasting/auth`, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(session?.accessToken ? { Authorization: `Bearer ${session.accessToken}` } : {}),
          },
          body: JSON.stringify({
            socket_id: socketId,
            channel_name: channel.name,
          }),
        })
          .then(async (response) => {
            if (!response.ok) {
              throw new Error(`Autorizacao do canal falhou (HTTP ${response.status}).`);
            }

            return (await response.json()) as ChannelAuthorizationData;
          })
          .then((data) => callback(null, data))
          .catch((error: Error) => callback(error, null));
      },
    }),
  });

  return echoInstance;
}

export function disconnectEcho(): void {
  echoInstance?.disconnect();
  echoInstance = null;
}
