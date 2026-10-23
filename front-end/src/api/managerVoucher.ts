/**
 * API cho Manager — Voucher CRUD nâng cấp (Phase 3).
 *
 *  - GET    /api/staff/manager/vouchers             — list (search/filter)
 *  - GET    /api/staff/manager/vouchers/{id}        — chi tiết
 *  - POST   /api/staff/manager/vouchers             — tạo
 *  - PUT    /api/staff/manager/vouchers/{id}        — cập nhật
 *  - DELETE /api/staff/manager/vouchers/{id}        — xoá/soft-delete
 *  - POST   /api/staff/manager/vouchers/{id}/toggle — bật/tắt nhanh
 *  - GET    /api/staff/manager/vouchers/{id}/usages — lịch sử sử dụng
 *  - POST   /api/staff/manager/vouchers/preview     — preview trên subtotal tuỳ ý
 *
 * Mở rộng từ api/voucher.ts (public) — tách riêng để manager có payload đầy đủ
 * (audit, usage history, toggle) không lẫn vào API marketing cho khách.
 */
import apiClient from './client'
import type { ManagerVoucher, ManagerVoucherUsage, Paginated, VoucherPreview, VoucherPayload } from '@/types/voucher'
export type { ManagerVoucher, VoucherPayload } from '@/types/voucher'

/* ============================================================
 *  CRUD endpoints
 * ============================================================ */

export interface ListVouchersParams {
    search?: string
    is_active?: boolean
    type?: 'PERCENT' | 'FIXED'
    from?: string
    to?: string
    page?: number
    per_page?: number
}

export function listManagerVouchers(params: ListVouchersParams = {}): Promise<Paginated<ManagerVoucher>> {
    return apiClient
        .get<{ success: boolean; data: ManagerVoucher[]; meta: Paginated<ManagerVoucher>['meta'] }>(
            '/staff/manager/vouchers',
            { params },
        )
        .then((r) => ({
            data: r.data.data,
            meta: r.data.meta,
        }))
}

export function getManagerVoucher(id: number): Promise<ManagerVoucher> {
    return apiClient
        .get<{ success: boolean; data: ManagerVoucher }>(`/staff/manager/vouchers/${id}`)
        .then((r) => r.data.data)
}


export function createManagerVoucher(payload: VoucherPayload): Promise<ManagerVoucher> {
    return apiClient
        .post<{ success: boolean; data: ManagerVoucher }>('/staff/manager/vouchers', payload)
        .then((r) => r.data.data)
}

export function updateManagerVoucher(id: number, payload: VoucherPayload): Promise<ManagerVoucher> {
    return apiClient
        .put<{ success: boolean; data: ManagerVoucher }>(`/staff/manager/vouchers/${id}`, payload)
        .then((r) => r.data.data)
}

export function deleteManagerVoucher(id: number): Promise<{ message: string }> {
    return apiClient
        .delete<{ success: boolean; message: string }>(`/staff/manager/vouchers/${id}`)
        .then((r) => r.data)
}

export function toggleManagerVoucher(id: number): Promise<ManagerVoucher> {
    return apiClient
        .post<{ success: boolean; data: ManagerVoucher }>(`/staff/manager/vouchers/${id}/toggle`)
        .then((r) => r.data.data)
}

/* ============================================================
 *  Usage history (drawer)
 * ============================================================ */

export function listVoucherUsages(
    id: number,
    page = 1,
    perPage = 20,
): Promise<Paginated<ManagerVoucherUsage>> {
    return apiClient
        .get<{ success: boolean; data: ManagerVoucherUsage[]; meta: Paginated<ManagerVoucherUsage>['meta'] }>(
            `/staff/manager/vouchers/${id}/usages`,
            { params: { page, per_page: perPage } },
        )
        .then((r) => ({ data: r.data.data, meta: r.data.meta }))
}

/* ============================================================
 *  Manager preview (test trên subtotal tuỳ ý)
 * ============================================================ */

export function previewManagerVoucher(code: string, subtotal: number): Promise<VoucherPreview> {
    return apiClient
        .post<{ success: boolean; data: VoucherPreview }>('/staff/manager/vouchers/preview', { code, subtotal })
        .then((r) => r.data.data)
}
