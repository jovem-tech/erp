export interface MobileUser {
  id: number;
  nome: string;
  email: string;
  perfil: string;
  grupo_id: number;
  foto: string;
  ativo: boolean;
  ultimo_acesso?: string | null;
  permissions?: Record<string, string[]>;
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
  is_encerrada: boolean;
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
  equipamento_tipo_nome: string;
  tecnico: OrderUser | null;
  relato_cliente: string;
  diagnostico_tecnico: string;
  solucao_aplicada: string;
  procedimentos_executados: string;
  prioridade: string;
  acessorios: string;
  observacoes_internas: string;
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

// --- Criação/edição de OS (wizard mobile) -----------------------------------

export interface ClientSearchResult {
  id: number;
  tipo_pessoa: string;
  nome_razao: string;
  cpf_cnpj: string;
  nome_contato: string;
  orders_count: number;
  equipments_count: number;
  email: string;
  telefone1: string;
  telefone_contato: string;
  cidade: string;
  uf: string;
  status_cadastro: string;
}

export interface EquipmentSearchResult {
  id: number;
  cliente_id: number;
  cliente_nome: string;
  tipo_id: number;
  tipo_nome: string;
  marca_nome: string;
  modelo_nome: string;
  resumo_tecnico: string;
  numero_serie: string;
  imei: string;
  desktop_modalidade: string;
  status_operacional: string;
  orders_count: number;
  primary_photo_id: number | null;
  primary_photo_url: string | null;
}

export interface EquipmentTypeCatalogItem {
  id: number;
  nome: string;
  slug: string;
  family: string;
}

export interface EquipmentBrandCatalogItem {
  id: number;
  nome: string;
  tipo_id?: number;
}

export interface EquipmentModelCatalogItem {
  id: number;
  marca_id: number;
  nome: string;
}

export interface PasswordModeOption {
  value: string;
  label: string;
}

export interface EquipmentFormData {
  types: EquipmentTypeCatalogItem[];
  brands: EquipmentBrandCatalogItem[];
  models: EquipmentModelCatalogItem[];
  catalog_relations?: unknown;
  desktop_defaults: { marca_id: number; modelo_id: number } | null;
  password_modes: PasswordModeOption[];
  max_photos: number;
}

export interface ReportedDefect {
  id: number;
  tipo_equipamento_id: number | null;
  tipo_equipamento_nome: string;
  categoria: string;
  subcategoria: string;
  texto_relato: string;
  icone: string;
  ordem_exibicao: number;
  ativo: boolean;
}

export interface EntryChecklistItem {
  id: number;
  descricao: string;
  ordem: number;
}

export interface EntryChecklistModel {
  id: number;
  checklist_tipo_id: number;
  tipo_equipamento_id: number;
  nome: string;
  descricao: string;
  itens: EntryChecklistItem[];
}

export interface LinkableBudget {
  id: number;
  numero: string;
  cliente_nome: string;
  valor_total: number;
  status: string;
}

export interface TeamMemberOption {
  value: number;
  label: string;
}

export type EntryChecklistResponseStatus = 'ok' | 'discrepancia' | 'nao_verificado' | 'nao_se_aplica';

export interface EntryChecklistAnswerPayload {
  checklist_item_id: number;
  status: EntryChecklistResponseStatus;
  observacao?: string | null;
}

export interface EntryChecklistPayload {
  observacoes_estado?: string | null;
  respostas: EntryChecklistAnswerPayload[];
}

export type OrderPriority = 'baixa' | 'normal' | 'alta' | 'urgente';

export interface NovoClientePayload {
  nome_razao: string;
  telefone1: string;
  email?: string;
  cpf_cnpj?: string;
  rg_ie?: string;
  telefone2?: string;
  nome_contato?: string;
  telefone_contato?: string;
  cep?: string;
  endereco?: string;
  numero?: string;
  complemento?: string;
  bairro?: string;
  cidade?: string;
  uf?: string;
  tipo_pessoa?: string;
  status_cadastro?: string;
}

export interface ClientUpdatePayload extends NovoClientePayload {
  tipo_pessoa: string;
  status_cadastro: string;
  referencia?: string;
  observacoes?: string;
  preferencia_contato?: string;
}

export interface ClientDetail extends ClientUpdatePayload {
  id: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface NovoEquipamentoPayload {
  tipo_id: number;
  marca_id: number;
  modelo_id: number;
  cor?: string;
  cor_hex?: string;
  cor_rgb?: string;
  numero_serie_visual?: string;
  imei?: string;
  senha_tipo?: 'desenho' | 'texto';
  senha_acesso?: string;
  senha_desenho?: string;
  estado_fisico?: string;
  observacoes?: string;
  desktop_modalidade?: 'montado' | 'oem';
  gabinete_tipo?: string;
  gabinete_identificacao_status?: 'a_confirmar' | 'manual' | 'detectado';
  gabinete_observacao?: string;
  placa_mae?: string;
  chipset?: string;
  processador?: string;
  memoria_ram?: string;
  armazenamento?: string;
  placa_video?: string;
  fonte_alimentacao?: string;
  foto_principal_index?: number;
}

export interface EquipmentUpdatePayload
  extends Omit<NovoEquipamentoPayload, 'numero_serie_visual' | 'foto_principal_index'> {
  numero_serie?: string;
  status_operacional?: string;
  status?: string;
}

export interface EquipmentDetail extends EquipmentUpdatePayload {
  id: number;
  cliente_id: number;
  tipo_nome: string;
  marca_nome: string;
  modelo_nome: string;
  resumo_tecnico: string;
  primary_photo_id: number | null;
  primary_photo_url: string | null;
  photos: Array<{ id: number; is_principal: boolean; url: string }>;
  created_at?: string | null;
  updated_at?: string | null;
}

export type DeliveryLeadDays = 1 | 3 | 7 | 15 | 30;

export interface CreateOrderPayload {
  idempotency_key: string;
  cliente_id?: number;
  novo_cliente?: NovoClientePayload;
  cliente_atualizacao?: ClientUpdatePayload;
  equipamento_id?: number;
  novo_equipamento?: NovoEquipamentoPayload;
  equipamento_atualizacao?: EquipmentUpdatePayload;
  orcamento_id?: number;
  tecnico_id?: number;
  prioridade?: OrderPriority;
  prazo_entrega_dias: DeliveryLeadDays;
  enviar_pdf_cliente: boolean;
  relato_cliente: string;
  acessorios?: string;
  observacoes_internas?: string;
  data_previsao?: string;
  checklist_entrada?: EntryChecklistPayload;
}

export interface UpdateOrderPayload {
  cliente_id?: number;
  equipamento_id?: number;
  tecnico_id?: number;
  prioridade?: OrderPriority;
  prazo_entrega_dias?: DeliveryLeadDays;
  relato_cliente?: string;
  acessorios?: string;
  observacoes_internas?: string;
  data_previsao?: string;
  checklist_entrada?: EntryChecklistPayload;
}

export interface CreateOrderResponse {
  order: OrderDetail;
  opening_document: unknown | null;
  opening_delivery: unknown | null;
  idempotent_replay: boolean;
  warnings: string[];
}
