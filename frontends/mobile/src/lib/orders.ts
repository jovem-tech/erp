import type {
  CepAddress,
  ClientDetail,
  ClientSearchResult,
  CreateOrderPayload,
  CreateOrderResponse,
  EntryChecklistModel,
  EquipmentDetail,
  EquipmentFormData,
  EquipmentSearchResult,
  EquipmentBrandCatalogItem,
  EquipmentModelCatalogItem,
  LinkableBudget,
  OrderAttachment,
  OrderDetail,
  OrderDocument,
  OrderListPayload,
  OrderPhoto,
  OrderSummary,
  ReportedDefect,
  TeamMemberOption,
  UpdateOrderPayload,
} from '@/lib/types';
import {
  apiClientDetail,
  apiCreateEquipmentBrand,
  apiCreateEquipmentModel,
  apiCreateOrder,
  apiEntryChecklistModel,
  apiEquipmentDetail,
  apiEquipmentFormData,
  apiListOrders,
  apiLookupCep,
  apiOrderDetail,
  apiSearchClients,
  apiSearchAvulsoBudgetContacts,
  apiSearchEquipments,
  apiSearchLinkableBudgets,
  apiSearchReportedDefects,
  apiTechnicians,
  apiUpdateOrder,
  apiUpdateOrderStatus,
} from '@/lib/api';

export type { OrderAttachment, OrderDetail, OrderDocument, OrderPhoto, OrderSummary, OrderListPayload };

export async function fetchOrders(filters: {
  q?: string;
  status?: string;
  per_page?: number;
} = {}): Promise<OrderListPayload> {
  return apiListOrders(filters);
}

export async function fetchOrder(orderId: number | string): Promise<OrderDetail> {
  return apiOrderDetail(orderId);
}

export async function saveOrderStatus(orderId: number | string, status: string, observacao: string | null = null) {
  return apiUpdateOrderStatus(orderId, status, observacao);
}

export function orderPhotoPath(orderId: number | string, photoId: number | string): string {
  return `/orders/${orderId}/photos/${photoId}`;
}

export function orderDocumentPath(orderId: number | string, documentId: number | string): string {
  return `/orders/${orderId}/documents/${documentId}`;
}

export async function createOrder(
  payload: CreateOrderPayload,
  photos: File[] = [],
  newEquipmentPhotos: File[] = []
): Promise<CreateOrderResponse> {
  return apiCreateOrder(payload, photos, newEquipmentPhotos);
}

export async function updateOrder(
  orderId: number | string,
  payload: UpdateOrderPayload,
  newPhotos: File[] = []
): Promise<{ order: OrderDetail }> {
  return apiUpdateOrder(orderId, payload, newPhotos);
}

export async function searchClients(search: string): Promise<ClientSearchResult[]> {
  const { clients } = await apiSearchClients({ search });
  return clients;
}

export async function getClientDetail(clientId: number): Promise<ClientDetail> {
  const { client } = await apiClientDetail(clientId);
  return client;
}

export async function searchEquipments(params: {
  clientId: number;
  search?: string;
  perPage?: number;
}): Promise<EquipmentSearchResult[]> {
  const { equipments } = await apiSearchEquipments(params);
  return equipments;
}

export async function getEquipmentDetail(equipmentId: number): Promise<EquipmentDetail> {
  const { equipment } = await apiEquipmentDetail(equipmentId);
  return equipment;
}

export async function getEquipmentFormData(): Promise<EquipmentFormData> {
  return apiEquipmentFormData();
}

export async function createEquipmentBrand(nome: string, tipoId: number): Promise<EquipmentBrandCatalogItem> {
  const { brand } = await apiCreateEquipmentBrand({ nome, tipo_id: tipoId });
  return brand;
}

export async function createEquipmentModel(marcaId: number, nome: string, tipoId: number): Promise<EquipmentModelCatalogItem> {
  const { model } = await apiCreateEquipmentModel({ marca_id: marcaId, nome, tipo_id: tipoId });
  return model;
}

export async function searchReportedDefects(tipoEquipamentoId: number): Promise<ReportedDefect[]> {
  const { defeitos_relatados: defeitosRelatados } = await apiSearchReportedDefects({ tipoEquipamentoId });
  return defeitosRelatados;
}

export async function getEntryChecklistModel(tipoEquipamentoId: number): Promise<EntryChecklistModel | null> {
  const { modelo } = await apiEntryChecklistModel(tipoEquipamentoId);
  return modelo;
}

export async function searchLinkableBudgets(q: string): Promise<LinkableBudget[]> {
  const { budgets } = await apiSearchLinkableBudgets({ q });
  return budgets;
}

export async function lookupCepAddress(cep: string): Promise<CepAddress> {
  const { address } = await apiLookupCep(cep);
  return address;
}

export async function searchAvulsoBudgetContacts(q: string): Promise<LinkableBudget[]> {
  const { budgets } = await apiSearchAvulsoBudgetContacts({ q, per_page: 8 });
  return budgets;
}

export async function listTechnicians(search?: string): Promise<TeamMemberOption[]> {
  const { team_members: teamMembers } = await apiTechnicians({ search });
  return teamMembers;
}

export function orderStatusBadgeClass(statusColor: string): string {
  const normalized = statusColor.trim().toLowerCase();
  if (normalized.includes('danger') || normalized.includes('red')) {
    return 'badge badge--danger';
  }
  if (normalized.includes('warning') || normalized.includes('orange') || normalized.includes('yellow')) {
    return 'badge badge--warm';
  }
  if (normalized.includes('success') || normalized.includes('green')) {
    return 'badge badge--success';
  }
  return 'badge badge--accent';
}
