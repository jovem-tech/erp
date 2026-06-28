import type {
  OrderAttachment,
  OrderDetail,
  OrderDocument,
  OrderListPayload,
  OrderPhoto,
  OrderSummary,
} from '@/lib/types';
import {
  apiListOrders,
  apiOrderDetail,
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
