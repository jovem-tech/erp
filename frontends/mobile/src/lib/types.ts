export interface MobileUser {
  id: number;
  nome: string;
  email: string;
  perfil: string;
  grupo_id: number;
  foto: string;
  ativo: boolean;
  ultimo_acesso?: string | null;
}

export interface MobileSession {
  accessToken: string;
  tokenType: 'Bearer';
  expiresAt: string;
  user: MobileUser;
}

export interface MobileNotification {
  id: string;
  tipo: string;
  titulo: string;
  corpo: string;
  rota_destino: string;
  icone: string;
  dados: Record<string, unknown>;
  lida_em: string | null;
  criada_em: string | null;
}

export interface MobileNotificationListPayload {
  items: MobileNotification[];
  unread_count: number;
}

export interface OrderSummary {
  id: number;
  numero_os: string;
  cliente_id: number;
  cliente_nome: string;
  equipamento_id: number;
  equipamento_resumo_tecnico: string;
  equipamento_numero_serie: string;
  tecnico_id: number;
  status: string;
  status_nome: string;
  status_cor: string;
  status_grupo_macro: string;
  estado_fluxo: string;
  status_atualizado_em: string | null;
}

export interface OrderClient {
  id: number;
  nome_razao: string;
  cpf_cnpj: string;
  email: string;
  telefone1: string;
  telefone2: string;
  nome_contato: string;
  telefone_contato: string;
  endereco: string;
  bairro: string;
  cidade: string;
  uf: string;
}

export interface OrderEquipment {
  id: number;
  cliente_id: number;
  tipo_id: number;
  marca_id: number;
  modelo_id: number;
  numero_serie: string;
  imei: string;
  desktop_modalidade: string;
  resumo_tecnico: string;
  observacoes: string;
}

export interface OrderStatusOption {
  codigo: string;
  nome: string;
  grupo_macro: string;
  cor: string;
  icone: string;
  ordem_fluxo: number;
  status_final: boolean;
  status_pausa: boolean;
  estado_fluxo_padrao: string;
}

export interface OrderUser {
  id: number;
  nome: string;
  email: string;
  perfil: string;
  foto: string;
  ativo: boolean;
}

export interface OrderHistoryItem {
  id: number;
  status_anterior: string;
  status_novo: string;
  estado_fluxo: string;
  observacao: string;
  created_at: string | null;
  usuario_id: number;
  usuario: OrderUser | null;
}

export interface OrderPhoto {
  id: number;
  tipo: string;
  tipo_label: string;
  arquivo: string;
  nome_arquivo: string;
  url: string;
  created_at: string | null;
}

export interface OrderDocument {
  id: number;
  tipo_documento: string;
  tipo_label: string;
  arquivo: string;
  nome_arquivo: string;
  versao: number;
  hash_sha1: string;
  url: string;
  created_at: string | null;
  updated_at: string | null;
  gerado_por: number;
  gerado_por_usuario: OrderUser | null;
}

export type OrderAttachment = OrderPhoto | OrderDocument;

export interface OrderDetail extends OrderSummary {
  cliente: OrderClient | null;
  equipamento: OrderEquipment | null;
  tecnico: OrderUser | null;
  relato_cliente: string;
  diagnostico_tecnico: string;
  solucao_aplicada: string;
  procedimentos_executados: string;
  data_abertura: string | null;
  data_entrada: string | null;
  data_previsao: string | null;
  data_conclusao: string | null;
  data_entrega: string | null;
  garantia_dias: number;
  garantia_validade: string | null;
  historico: OrderHistoryItem[];
  status_disponiveis: OrderStatusOption[];
  fotos: OrderPhoto[];
  documentos: OrderDocument[];
}

export interface OrderListPayload {
  orders: OrderSummary[];
  pagination: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number | null;
    to: number | null;
  };
}

export interface AttachmentBlob {
  blob: Blob;
  contentType: string;
  filename: string;
}
