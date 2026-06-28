import type {
  ChatClientSearchResult,
  ChatMessage,
  ChatSession,
  ChatUser,
  ConversationDetail,
  ConversationListPayload,
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
  const baseUrl = process.env.NEXT_PUBLIC_API_BASE_URL ?? 'http://localhost:8000/api/v1';
  return baseUrl.replace(/\/+$/, '');
}

function buildApiUrl(path: string): string {
  if (/^https?:\/\//i.test(path)) {
    return path;
  }

  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  return `${getApiBaseUrl()}${normalizedPath}`;
}

function withAuthHeaders(headers: Headers, includeAuth: boolean): void {
  if (!includeAuth) {
    return;
  }

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

async function requestRaw(path: string, init: RequestInit = {}, includeAuth = true): Promise<Response> {
  const response = await fetch(buildApiUrl(path), {
    ...init,
    cache: 'no-store',
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

function appendFiles(formData: FormData, files: File[], fieldName = 'attachments'): void {
  files.forEach((file) => {
    formData.append(fieldName, file);
  });
}

export async function apiLogin(payload: {
  email: string;
  password: string;
  device_name?: string;
}): Promise<ChatSession> {
  type LoginResponse = {
    access_token: string;
    token_type: 'Bearer' | string;
    expires_at: string;
    user: ChatUser;
  };

  const data = await requestJson<LoginResponse>('/auth/login', {
    method: 'POST',
    body: JSON.stringify({
      email: payload.email,
      password: payload.password,
      device_name: payload.device_name ?? 'pwa-chat',
    }),
  }, false);

  const session: ChatSession = {
    accessToken: data.access_token,
    tokenType: 'Bearer',
    expiresAt: data.expires_at,
    user: data.user,
  };

  storeSessionWithAutoRefresh(session);

  return session;
}

export async function apiLogout(): Promise<void> {
  try {
    await requestJson('/auth/logout', { method: 'POST' });
  } finally {
    clearStoredSession();
  }
}

export async function apiMe(): Promise<ChatUser> {
  return requestJson<ChatUser>('/auth/me');
}

export async function apiRefresh(): Promise<Pick<ChatSession, 'accessToken' | 'tokenType' | 'expiresAt'>> {
  type RefreshResponse = {
    access_token: string;
    token_type: 'Bearer' | string;
    expires_at: string;
  };

  const data = await requestJson<RefreshResponse>('/auth/refresh', { method: 'POST' });

  return {
    accessToken: data.access_token,
    tokenType: 'Bearer',
    expiresAt: data.expires_at,
  };
}

export async function apiListConversations(filters: {
  status?: string;
  per_page?: number;
  search?: string;
  unread_only?: boolean;
} = {}): Promise<ConversationListPayload> {
  const params = new URLSearchParams();

  if (filters.status?.trim()) {
    params.set('status', filters.status.trim());
  }

  if (filters.search?.trim()) {
    params.set('search', filters.search.trim());
  }

  if (filters.unread_only) {
    params.set('unread_only', '1');
  }

  if (filters.per_page) {
    params.set('per_page', String(filters.per_page));
  }

  const query = params.toString();
  return requestJson<ConversationListPayload>(`/conversas${query ? `?${query}` : ''}`);
}

export async function apiConversationDetail(conversationId: number | string): Promise<ConversationDetail> {
  const payload = await requestJson<{ conversation: ConversationDetail }>(`/conversas/${conversationId}`);
  return payload.conversation;
}

export async function apiSearchChatClients(query: string): Promise<ChatClientSearchResult[]> {
  const trimmed = query.trim();
  if (!trimmed) {
    return [];
  }

  const payload = await requestJson<{ clients: ChatClientSearchResult[] }>(
    `/chat/clientes/search?q=${encodeURIComponent(trimmed)}`
  );

  return payload.clients;
}

export async function apiStartConversation(payload: {
  client_id?: number;
  telefone?: string;
  nome?: string;
  mensagem?: string;
  attachments?: File[];
}): Promise<ConversationDetail> {
  const formData = new FormData();

  if (payload.client_id) {
    formData.append('client_id', String(payload.client_id));
  }

  if (payload.telefone?.trim()) {
    formData.append('telefone', payload.telefone.trim());
  }

  if (payload.nome?.trim()) {
    formData.append('nome', payload.nome.trim());
  }

  if (payload.mensagem?.trim()) {
    formData.append('mensagem', payload.mensagem.trim());
  }

  appendFiles(formData, payload.attachments ?? []);

  const data = await requestJson<{ conversation: ConversationDetail }>('/conversas', {
    method: 'POST',
    body: formData,
  });

  return data.conversation;
}

export async function apiSendMessage(
  conversationId: number | string,
  payload: { conteudo?: string; attachments?: File[] }
): Promise<ChatMessage> {
  const formData = new FormData();

  if (payload.conteudo?.trim()) {
    formData.append('conteudo', payload.conteudo.trim());
  }

  appendFiles(formData, payload.attachments ?? []);

  const response = await requestJson<{ message: ChatMessage }>(`/conversas/${conversationId}/mensagens`, {
    method: 'POST',
    body: formData,
  });

  return response.message;
}

export async function apiFetchAttachmentBlob(pathOrUrl: string): Promise<Blob> {
  const response = await requestRaw(pathOrUrl);

  if (!response.ok) {
    throw new ApiError('Não foi possível carregar o anexo.', response.status, 'ATTACHMENT_FETCH_FAILED');
  }

  return response.blob();
}
