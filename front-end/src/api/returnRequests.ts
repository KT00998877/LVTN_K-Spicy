import api from './client'

export type ReturnRequestStatus = 'REQUESTED' | 'ACCEPTED' | 'REJECTED' | 'DISPATCHED' | 'RECEIVED'

export interface ReturnRequest {
  id: number
  code: string
  purchase_order_id: number
  purchase_order_code: string
  status: ReturnRequestStatus
  reason: string
  rejection_reason: string | null
  supplier_return_id: number | null
  requested_at: string | null
  supplier_responded_at: string | null
  dispatched_at: string | null
  received_at: string | null
  items: Array<{ stock_import_item_id: number; ingredient_name: string | null; quantity: number; unit: string | null }>
}

export const managerReturnRequestsApi = {
  list: () => api.get<{ data: ReturnRequest[] }>('/staff/manager/return-requests'),
  create: (orderId: number, payload: { request_key: string; reason: string; items: Array<{ stock_import_item_id: number; quantity: number }> }) =>
    api.post<{ data: ReturnRequest }>(`/staff/manager/return-requests/purchase-orders/${orderId}`, payload),
  dispatch: (id: number) => api.post<{ data: ReturnRequest }>(`/staff/manager/return-requests/${id}/dispatch`),
  receive: (id: number) => api.post<{ data: ReturnRequest }>(`/staff/manager/return-requests/${id}/receive`),
}
