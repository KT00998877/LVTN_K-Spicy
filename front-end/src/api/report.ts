import apiClient from './client'
import type { ReportExportParams, ReportFormat, ReportType } from '@/types/report'

/**
 * Tạo URL tải file báo cáo (Excel/PDF).
 * Endpoint: GET /api/staff/reports/export
 */
export function buildReportUrl(params: ReportExportParams): string {
    const search = new URLSearchParams()
    search.set('type', params.type)
    search.set('format', params.format)
    if (params.from) search.set('from', params.from)
    if (params.to) search.set('to', params.to)
    if (params.status) search.set('status', params.status)
    if (params.order_type) search.set('order_type', params.order_type)
    // apiClient.defaults.baseURL luôn kết thúc bằng '/api' → trả về path trực tiếp
    const base = (apiClient.defaults.baseURL ?? '').replace(/\/+$/, '')
    return `${base}/staff/reports/export?${search.toString()}`
}

/**
 * Tải file về và trigger download trong browser.
 * Dùng link tạm thời + revoke để tránh memory leak.
 */
export async function downloadReport(
    params: ReportExportParams,
    filename?: string,
): Promise<{ filename: string; size: number }> {
    const url = buildReportUrl(params)
    const response = await fetch(url, {
        headers: { Accept: params.format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
        credentials: 'include',
    })
    if (!response.ok) {
        // Cố gắng parse JSON error để show message
        let message = `HTTP ${response.status}`
        try {
            const json = await response.json()
            message = json.message || json.error || message
        } catch {
            /* ignore */
        }
        throw new Error(message)
    }
    const blob = await response.blob()
    const finalName = filename ?? makeFilename(params)
    triggerDownload(blob, finalName)
    return { filename: finalName, size: blob.size }
}

function triggerDownload(blob: Blob, filename: string) {
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.style.display = 'none'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    setTimeout(() => URL.revokeObjectURL(url), 1000)
}

function makeFilename({ type, format, from, to }: ReportExportParams): string {
    const stamp = new Date().toISOString().slice(0, 10)
    const range = from && to ? `_${from}_${to}` : ''
    return `BaoCao_${type}${range}_${stamp}.${format}`
}

/** Kiểm tra user có quyền export loại report này không. */
export function canExport(type: ReportType, userRoles: string[]): boolean {
    const upper = userRoles.map((r) => r.toUpperCase())
    if (upper.includes('MANAGER')) return true
    if (upper.includes('CASHIER')) {
        return ['revenue', 'orders', 'shift'].includes(type)
    }
    return false
}