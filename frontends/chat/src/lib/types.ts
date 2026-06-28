export type ChatUser = {
  id: number;
  nome: string;
  email: string;
  perfil: string;
  grupo_id: number;
  foto: string;
  ativo: boolean;
  ultimo_acesso: string | null;
};

export type ChatSession = {
  accessToken: string;
  tokenType: 'Bearer';
  expiresAt: string;
  user: ChatUser;
};

export type ChatClientSummary = {
  id: number;
  nome_razao: string;
  cpf_cnpj: string;
  cidade: string;
  uf: string;
  telefone1: string;
  telefone2: string;
  telefone_contato: string;
  nome_contato: string;
};

export type ConversationContact = {
  id: number | null;
  nome: string | null;
  telefone: string | null;
  cliente_id?: number | null;
  cliente_nome?: string | null;
  client?: ChatClientSummary | null;
};

export type ConversationStatus = 'open' | 'resolved' | 'pending' | 'snoozed';

export type MessageType = 'incoming' | 'outgoing' | 'activity';

export type MessageStatus = 'pending' | 'sent' | 'delivered' | 'read' | 'failed';

export type MessageContentType = 'text' | 'image' | 'audio' | 'video' | 'document' | 'mixed' | 'unknown';

export type ChatAttachment = {
  id: number;
  attachment_type: 'image' | 'audio' | 'video' | 'document' | 'unknown';
  transfer_status: 'available' | 'failed';
  original_name: string | null;
  mime_type: string | null;
  byte_size: number | null;
  available: boolean;
  url: string;
  provider_url: string | null;
  metadata: Record<string, unknown> | null;
};

export type ChatMessage = {
  id: number;
  conversa_id: number;
  message_type: MessageType;
  content_type: MessageContentType;
  conteudo: string | null;
  status: MessageStatus;
  sender_type: string | null;
  sender_id: number | null;
  created_at: string | null;
  attachments: ChatAttachment[];
};

export type ConversationMessagePreview = {
  id: number;
  message_type: MessageType;
  content_type: MessageContentType;
  sender_type: string | null;
  status: MessageStatus;
  created_at: string | null;
  preview: string;
  attachment_count: number;
};

export type ConversationSummary = {
  id: number;
  display_id: number;
  status: ConversationStatus;
  status_label: string;
  last_activity_at: string | null;
  unread: boolean;
  unread_count: number;
  contact: ConversationContact;
  last_message: ConversationMessagePreview | null;
};

export type ConversationDetail = ConversationSummary & {
  messages: ChatMessage[];
};

export type ConversationListPayload = {
  items: ConversationSummary[];
};

export type ChatClientSearchResult = {
  id: number;
  nome_razao: string;
  cpf_cnpj: string;
  cidade: string;
  uf: string;
  telefone_principal: string | null;
  telefones: string[];
  nome_contato: string;
  can_start_conversation: boolean;
};
