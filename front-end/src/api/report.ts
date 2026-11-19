import apiClient from './client'
import type { ReportExportParams, ReportFormat, ReportType } from '@/types/report'

/**
 * Tải file về và trigger download trong browser.
 * Dùng link tạm thời + revoke để tránh memory leak.
 */
export async function downloadReport(
    params: ReportExportParams,
    filename?: string,
): Promise<{ filename: string; size: number }> {
    let blob: Blob
    try {
        const response = await apiClient.get<Blob>('/staff/reports/export', {
            params,
            responseType: 'blob',
            headers: { Accept: params.format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
        })
        blob = response.data
    } catch (error: any) {
        const data = error?.response?.data
        if (data instanceof Blob && data.type.includes('json')) {
            const json = JSON.parse(await data.text())
            throw new Error(json.message || json.error || error.message)
        }
        throw error
    }
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
