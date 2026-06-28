import type {
  AttachmentBlob,
  MobileSession,
  MobileNotification,
  MobileNotificationListPayload,
  MobileUser,
  OrderDetail,
  OrderListPayload,
  OrderSummary,
} from '@/lib/types';
import {
  clearStoredSession,
  readStoredSession,
  storeSessionWithAutoRefresh,
} from '@/lib/session';

type ApiErrorPayload = {
  code?: string;
  message?: string;
  details?: unknown;
};

type ApiEnvelope<T> = {
  status: 'success' | 'error';
  data: T | null;
  error: ApiErrorPayload | null;
};

export class ApiError extends Error {
  status: number;

  code: string;

  details: unknown;

  constructor(message: string, status: number, code = 'API_ERROR', details: unknown = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

function getApiBaseUrl(): string {
  const baseUrl = process.env.NEXT_PUBLIC_API_BASE_URL ?? 'http://127.0.0.1:8000/api/v1';
  return baseUrl.replace(/\/+$/, '');
}

function buildApiUrl(path: string): string {
  if (/^https?:\/\//i.test(path)) {
    return path;
  }

  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const baseUrl = getApiBaseUrl();

  try {
    const parsedBaseUrl = new URL(baseUrl);
    const basePath = parsedBaseUrl.pathname.replace(/\/+$/, '');

    if (basePath !== '' && normalizedPath.startsWith(`${basePath}/`)) {
      return `${parsedBaseUrl.origin}${normalizedPath}`;
    }
  } catch {
    // Se a URL base estiver inválida, seguimos com a concatenação padrão.
  }

  return `${baseUrl}${normalizedPath}`;
}

function parseFilename(contentDisposition: string | null): string {
  if (!contentDisposition) {
    return 'arquivo';
  }

  const match = /filename\*?=(?:UTF-8''|")?([^";]+)/i.exec(contentDisposition);
  if (!match) {
    return 'arquivo';
  }

  try {
    return decodeURIComponent(match[1].replace(/"/g, ''));
  } catch {
    return match[1].replace(/"/g, '') || 'arquivo';
  }
}

function unwrapJson<T>(payload: ApiEnvelope<T>): T {
  if (payload.status !== 'success' || payload.error) {
    const message = payload.error?.message ?? 'Ocorreu uma falha inesperada.';
    throw new ApiError(message, 400, payload.error?.code ?? 'API_ERROR', payload.error?.details ?? null);
  }

  return payload.data as T;
}

async function tryReadJson(response: Response): Promise<ApiEnvelope<unknown> | null> {
  const contentType = response.headers.get('content-type') ?? '';
  if (!contentType.includes('application/json')) {
    return null;
  }

  try {
    return (await response.json()) as ApiEnvelope<unknown>;
  } catch {
    return null;
  }
}

function withAuthHeaders(headers: Headers, includeAuth: boolean): void {
  if (!includeAuth) {
    return;
  }

  // O canal mobile usa Bearer token armazenado localmente como mecanismo
  // principal. O backend tambem emite cookie httpOnly, mas o app nao depende
  // dele para autenticar as chamadas hoje.
  const session = readStoredSession();
  if (session?.accessToken) {
    headers.set('Authorization', `Bearer ${session.accessToken}`);
  }
}

function buildHeaders(init: RequestInit | undefined, includeAuth: boolean): Headers {
  const headers = new Headers(init?.headers ?? {});
  headers.set('Accept', headers.get('Accept') ?? 'application/json');
  withAuthHeaders(headers, includeAuth);

  if (init?.body && !headers.has('Content-Type') && !(init.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json');
  }

  return headers;
}

async function requestRaw(path: string, init: RequestInit = {}, includeAuth = true): Promise<Response> {
  const response = await fetch(buildApiUrl(path), {
    ...init,
    cache: 'no-store',
    credentials: 'include', // Mantido para acompanhar o cookie httpOnly emitido pelo backend, sem substituir o Bearer atual
    headers: buildHeaders(init, includeAuth),
  });

  if (includeAuth && response.status === 401) {
    clearStoredSession();
  }

  return response;
}

async function requestJson<T>(path: string, init: RequestInit = {}, includeAuth = true): Promise<T> {
  const response = await requestRaw(path, init, includeAuth);
  const payload = await tryReadJson(response);

  if (!response.ok || !payload) {
    if (response.status === 401) {
      throw new ApiError('Usuário não autenticado.', 401, 'AUTH_REQUIRED');
    }

    if (payload?.error) {
      throw new ApiError(
        payload.error.message ?? 'A requisição não pôde ser concluída.',
        response.status,
        payload.error.code ?? 'API_ERROR',
        payload.error.details ?? null
      );
    }

    throw new ApiError('A requisição não pôde ser concluída.', response.status, 'API_ERROR');
  }

  return unwrapJson<T>(payload as ApiEnvelope<T>);
}

export async function apiLogin(payload: {
  email: string;
  password: string;
  device_name?: string;
}): Promise<MobileSession> {
  type LoginResponse = {
    access_token: string;
    token_type: 'Bearer' | string;
    expires_at: string;
    user: MobileUser;
  };

  const data = await requestJson<LoginResponse>('/auth/login', {
    method: 'POST',
    body: JSON.stringify({
      email: payload.email,
      password: payload.password,
      device_name: payload.device_name ?? 'pwa-mobile',
    }),
  }, false);

  const session: MobileSession = {
    accessToken: data.access_token,
    tokenType: 'Bearer',
    expiresAt: data.expires_at,
    user: data.user,
  };

  // Store with auto-refresh scheduling
  storeSessionWithAutoRefresh(session);

  return session;
}

/**
 * Logout da aplicação
 */
export async function apiLogout(): Promise<void> {
  try {
    await requestJson('/auth/logout', {
      method: 'POST',
    });
  } finally {
    clearStoredSession();
  }
}

export async function apiMe(): Promise<MobileUser> {
  return requestJson<MobileUser>('/auth/me');
}

export async function apiUpdateProfile(payload: {
  nome: string;
}): Promise<MobileUser> {
  return requestJson<MobileUser>('/auth/me', {
    method: 'PATCH',
    body: JSON.stringify(payload),
  });
}

export async function apiUpdatePassword(payload: {
  current_password: string;
  password: string;
  password_confirmation: string;
}): Promise<{
  requires_relogin: boolean;
  revoked_tokens: number;
}> {
  return requestJson<{
    requires_relogin: boolean;
    revoked_tokens: number;
  }>('/auth/password', {
    method: 'PUT',
    body: JSON.stringify(payload),
  });
}

export async function apiListNotifications(filters: {
  onlyUnread?: boolean;
  page?: number;
  perPage?: number;
} = {}): Promise<MobileNotificationListPayload> {
  const params = new URLSearchParams();

  if (filters.onlyUnread) {
    params.set('only_unread', '1');
  }

  if (filters.page && filters.page > 0) {
    params.set('page', String(filters.page));
  }

  if (filters.perPage && filters.perPage > 0) {
    params.set('per_page', String(filters.perPage));
  }

  const query = params.toString();
  return requestJson<MobileNotificationListPayload>(`/notifications${query ? `?${query}` : ''}`);
}

export async function apiMarkNotificationRead(notificationId: string): Promise<{
  notification: MobileNotification;
}> {
  return requestJson<{
    notification: MobileNotification;
  }>(`/notifications/${notificationId}/read`, {
    method: 'PATCH',
  });
}

export async function apiMarkAllNotificationsRead(): Promise<{
  updated_count: number;
}> {
  return requestJson<{
    updated_count: number;
  }>('/notifications/read-all', {
    method: 'PATCH',
  });
}

export async function apiRefresh(): Promise<Pick<MobileSession, 'accessToken' | 'tokenType' | 'expiresAt'>> {
  type RefreshResponse = {
    access_token: string;
    token_type: 'Bearer' | string;
    expires_at: string;
  };

  const data = await requestJson<RefreshResponse>('/auth/refresh', {
    method: 'POST',
  });

  return {
    accessToken: data.access_token,
    tokenType: 'Bearer',
    expiresAt: data.expires_at,
  };
}

export async function apiListOrders(filters: {
  q?: string;
  status?: string;
  per_page?: number;
} = {}): Promise<OrderListPayload> {
  const params = new URLSearchParams();

  if (filters.q?.trim()) {
    params.set('q', filters.q.trim());
  }

  if (filters.status?.trim()) {
    params.set('status', filters.status.trim());
  }

  if (filters.per_page) {
    params.set('per_page', String(filters.per_page));
  }

  const query = params.toString();
  return requestJson<OrderListPayload>(`/orders${query ? `?${query}` : ''}`);
}

export async function apiOrderDetail(orderId: number | string): Promise<OrderDetail> {
  const payload = await requestJson<{ order: OrderDetail }>(`/orders/${orderId}`);
  return payload.order;
}

export async function apiUpdateOrderStatus(orderId: number | string, status: string, observacao: string | null = null): Promise<{
  order: OrderSummary | null;
  status_anterior: string;
  status_novo: string;
  estado_fluxo: string;
}> {
  return requestJson<{
    order: OrderSummary | null;
    status_anterior: string;
    status_novo: string;
    estado_fluxo: string;
  }>(`/orders/${orderId}/status`, {
    method: 'PATCH',
    body: JSON.stringify({
      status,
      observacao,
    }),
  });
}

export async function fetchAttachmentBlob(path: string): Promise<AttachmentBlob> {
  const response = await requestRaw(path, {
    method: 'GET',
  });

  if (!response.ok) {
    const payload = await tryReadJson(response);
    if (payload?.error) {
      throw new ApiError(
        payload.error.message ?? 'O arquivo não pôde ser carregado.',
        response.status,
        payload.error.code ?? 'API_ERROR',
        payload.error.details ?? null
      );
    }

    if (response.status === 401) {
      throw new ApiError('Usuário não autenticado.', 401, 'AUTH_REQUIRED');
    }

    throw new ApiError('O arquivo não pôde ser carregado.', response.status, 'API_ERROR');
  }

  const blob = await response.blob();

  return {
    blob,
    contentType: response.headers.get('content-type') ?? blob.type ?? 'application/octet-stream',
    filename: parseFilename(response.headers.get('content-disposition')),
  };
}
