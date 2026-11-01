import api from './client'

export type PurchaseOrderStatus =
  | 'DRAFT'
  | 'SENT'
  | 'SUPPLIER_ACCEPTED'
  | 'SUPPLIER_REJECTED'
  | 'SHIPPED'
  | 'RECEIVED'
  | 'CANCELLED'

export interface PurchaseOrderItem {
  id: number
  ingredient_id: number
  ingredient_code: string | null
  ingredient_name: string | null
  ingredient_packaging_id: number | null
  purchase_unit: string
  conversion_quantity: number
  requested_purchase_quantity: number
  supplier_accepted_quantity: number | null
  shipped_purchase_quantity: number | null
  purchase_unit_price: number | null
  supplier_lot_code: string | null
  expiry_date: string | null
  supplier_note: string | null
}

export interface PurchaseOrderReceiptItem {
  id: number
  purchase_order_item_id: number
  ingredient_id: number
  ingredient_code: string | null
  ingredient_name: string | null
  purchase_unit: string
  conversion_quantity: number
  delivered_purchase_quantity: number
  accepted_purchase_quantity: number
  rejected_purchase_quantity: number
  rejection_reason: string | null
  quantity: number
  returned_quantity: number
  returnable_quantity: number
  unit: string
  purchase_unit_price: number
  subtotal: number
  supplier_lot_code: string | null
  expiry_date: string | null
}

export interface SupplierReturnItem {
  id: number
  stock_import_item_id: number
  ingredient_id: number
  ingredient_code: string | null
  ingredient_name: string | null
  quantity: number
  unit_price: number
  subtotal: number
}

export interface SupplierReturn {
  id: number
  code: string
  stock_import_id: number
  reason: string
  returned_by: number
  returned_by_name: string | null
  returned_at: string | null
  items: SupplierReturnItem[]
  transactions: Array<{
    id: number
    ingredient_id: number
    quantity: number
    stock_before: number
    stock_after: number
  }>
}

export interface SupplierReturnNotice {
  code: string
  reason: string
  returned_at: string | null
  items: Array<{ ingredient_name: string | null; quantity: number; unit: string | null }>
}

export interface PurchaseOrderReceipt {
  id: number
  code: string
  status: 'DRAFT' | 'CONFIRMED' | 'CANCELLED'
  total_cost: number
  note: string | null
  received_at: string | null
  confirmed_at: string | null
  items: PurchaseOrderReceiptItem[]
}

export interface PurchaseOrder {
  id: number
  code: string
  status: PurchaseOrderStatus
  supplier_id: number
  supplier_name: string | null
  created_by: number
  created_by_name: string | null
  requested_delivery_date: string | null
  supplier_delivery_date: string | null
  manager_note: string | null
  supplier_note: string | null
  rejection_reason: string | null
  supplier_document_code: string | null
  sent_by: number | null
  sent_at: string | null
  supplier_responded_by: number | null
  supplier_responded_at: string | null
  shipped_by: number | null
  shipped_at: string | null
  received_by: number | null
  received_at: string | null
  cancelled_by: number | null
  cancelled_at: string | null
  items: PurchaseOrderItem[]
  stock_import?: PurchaseOrderReceipt | null
  supplier_returns?: SupplierReturn[]
  return_count?: number
  return_notices?: SupplierReturnNotice[]
  created_at: string | null
  updated_at: string | null
}

export interface PurchaseOrderPayload {
  supplier_id: number
  requested_delivery_date?: string | null
  manager_note?: string | null
  items: Array<{
    ingredient_id: number
    ingredient_packaging_id?: number | null
    requested_purchase_quantity: number
  }>
}

export interface ReceiptPayload {
  note?: string | null
  items: Array<{
    purchase_order_item_id: number
    delivered_purchase_quantity: number
    accepted_purchase_quantity: number
    rejection_reason?: string | null
    supplier_lot_code?: string | null
    expiry_date?: string | null
  }>
}

export interface SupplierAcceptPayload {
  supplier_delivery_date: string
  supplier_note?: string | null
  items: Array<{
    purchase_order_item_id: number
    supplier_accepted_quantity: number
    purchase_unit_price: number
    supplier_note?: string | null
  }>
}

export interface SupplierShipPayload {
  supplier_document_code: string
  supplier_note?: string | null
  items: Array<{
    purchase_order_item_id: number
    shipped_purchase_quantity: number
    supplier_lot_code?: string | null
    expiry_date?: string | null
    supplier_note?: string | null
  }>
}

interface PurchaseOrderListResponse {
  data: PurchaseOrder[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

export const purchaseOrdersApi = {
  list: (params: Record<string, unknown> = {}) =>
    api.get<PurchaseOrderListResponse>('/staff/manager/purchase-orders', { params }),
  get: (id: number) =>
    api.get<{ data: PurchaseOrder }>(`/staff/manager/purchase-orders/${id}`),
  create: (payload: PurchaseOrderPayload) =>
    api.post<{ data: PurchaseOrder }>('/staff/manager/purchase-orders', payload),
  update: (id: number, payload: PurchaseOrderPayload) =>
    api.patch<{ data: PurchaseOrder }>(`/staff/manager/purchase-orders/${id}`, payload),
  send: (id: number) =>
    api.post<{ data: PurchaseOrder }>(`/staff/manager/purchase-orders/${id}/send`),
  cancel: (id: number) =>
    api.post<{ data: PurchaseOrder }>(`/staff/manager/purchase-orders/${id}/cancel`),
  createReceipt: (id: number) =>
    api.post<{ data: PurchaseOrder }>(`/staff/manager/purchase-orders/${id}/receipt`),
  updateReceipt: (id: number, payload: ReceiptPayload) =>
    api.patch<{ data: PurchaseOrder }>(`/staff/manager/purchase-orders/${id}/receipt`, payload),
  receive: (id: number, receivedAt: string) =>
    api.post<{ data: PurchaseOrder }>(`/staff/manager/purchase-orders/${id}/receive`, { received_at: receivedAt }),
}

export const supplierPurchaseOrdersApi = {
  list: (params: Record<string, unknown> = {}) =>
    api.get<PurchaseOrderListResponse>('/staff/supplier/purchase-orders', { params }),
  get: (id: number) =>
    api.get<{ data: PurchaseOrder }>(`/staff/supplier/purchase-orders/${id}`),
  accept: (id: number, payload: SupplierAcceptPayload) =>
    api.post<{ data: PurchaseOrder }>(`/staff/supplier/purchase-orders/${id}/accept`, payload),
  reject: (id: number, rejectionReason: string) =>
    api.post<{ data: PurchaseOrder }>(`/staff/supplier/purchase-orders/${id}/reject`, { rejection_reason: rejectionReason }),
  ship: (id: number, payload: SupplierShipPayload) =>
    api.post<{ data: PurchaseOrder }>(`/staff/supplier/purchase-orders/${id}/ship`, payload),
}

export function purchaseOrderError(error: unknown): string {
  const response = (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response
  return Object.values(response?.data?.errors ?? {}).flat().join(' ')
    || response?.data?.message
    || 'Không xử lý được yêu cầu. Vui lòng thử lại.'
}
