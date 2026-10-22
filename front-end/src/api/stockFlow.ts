import api from './client'

/** ----- Types ----- */

export interface StockImportItem {
  id: number
  ingredient_id: number
  ingredient_code?: string | null
  ingredient_name?: string | null
  quantity: number
  ingredient_packaging_id: number | null
  purchase_quantity: number | null
  purchase_unit: string | null
  conversion_quantity: number | null
  unit: string
  unit_price: number
  purchase_unit_price: number | null
  subtotal: number
  expiry_date: string | null
  supplier_lot_code: string | null
  note: string | null
  expiry_status?: 'OK' | 'NEAR_EXPIRY' | 'EXPIRED' | 'NONE'
}

export interface StockImport {
  id: number
  code: string
  supplier_id: number | null
  supplier_name: string | null
  imported_by: number | null
  imported_by_name: string | null
  confirmed_by: number | null
  confirmed_by_name: string | null
  confirmed_at: string | null
  cancelled_by: number | null
  cancelled_at: string | null
  cancellation_reason: string | null
  imported_at: string
  supplier_document_code: string | null
  received_at: string | null
  status: 'DRAFT' | 'CONFIRMED' | 'CANCELLED'
  total_cost: number
  note: string | null
  items: StockImportItem[]
  created_at: string | null
  updated_at: string | null
}

export interface StockImportListResponse {
  data: StockImport[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

export interface StocktakeItem {
  id: number
  ingredient_id: number
  ingredient_code?: string | null
  ingredient_name?: string | null
  unit?: string | null
  system_qty: number
  actual_qty: number
  difference_qty: number
  unit_cost: number
  difference_value: number
  reason: string | null
  inventory_transaction_id: number | null
}

export interface Stocktake {
  id: number
  code: string
  counted_at: string
  status: 'OPEN' | 'COMPLETED' | 'CANCELLED'
  staff_id: number | null
  staff_name: string | null
  completed_by: number | null
  completed_by_name: string | null
  completed_at: string | null
  total_difference_value: number
  note: string | null
  cancellation_reason: string | null
  items: StocktakeItem[]
  created_at: string | null
  updated_at: string | null
}

export interface StocktakeListResponse {
  data: Stocktake[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

export interface StockImportItemPayload {
  ingredient_id: number
  quantity: number
  ingredient_packaging_id?: number | null
  purchase_quantity?: number | null
  unit_price: number
  purchase_unit_price?: number | null
  expiry_date?: string | null
  supplier_lot_code?: string | null
  note?: string | null
}

export interface StockImportPayload {
  code?: string | null
  supplier_id?: number | null
  imported_at: string
  supplier_document_code?: string | null
  note?: string | null
  items: StockImportItemPayload[]
}

export interface StocktakeItemPayload {
  ingredient_id: number
  actual_qty: number
  reason?: string | null
}

export interface StocktakePayload {
  counted_at: string
  note?: string | null
  items: StocktakeItemPayload[]
}

/** ----- API calls ----- */

export const stockImportsApi = {
  list: (params: Record<string, any> = {}) =>
    api.get<StockImportListResponse>('/staff/manager/stock-imports', { params }),
  get: (id: number) => api.get<{ data: StockImport }>(`/staff/manager/stock-imports/${id}`),
  create: (payload: StockImportPayload) =>
    api.post<{ data: StockImport }>('/staff/manager/stock-imports', payload),
  update: (id: number, payload: StockImportPayload) =>
    api.patch<{ data: StockImport }>(`/staff/manager/stock-imports/${id}`, payload),
  confirm: (id: number, received_at: string) =>
    api.post<{ data: StockImport }>(`/staff/manager/stock-imports/${id}/confirm`, { received_at }),
  cancel: (id: number) =>
    api.delete<{ data: StockImport }>(`/staff/manager/stock-imports/${id}`),
}

export const stocktakesApi = {
  list: (params: Record<string, any> = {}) =>
    api.get<StocktakeListResponse>('/staff/manager/stocktakes', { params }),
  get: (id: number) => api.get<{ data: Stocktake }>(`/staff/manager/stocktakes/${id}`),
  create: (payload: StocktakePayload) =>
    api.post<{ data: Stocktake }>('/staff/manager/stocktakes', payload),
  complete: (id: number) =>
    api.post<{ data: Stocktake }>(`/staff/manager/stocktakes/${id}/complete`),
  cancel: (id: number) =>
    api.post<{ data: Stocktake }>(`/staff/manager/stocktakes/${id}/cancel`),
}
