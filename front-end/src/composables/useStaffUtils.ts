/**
 * Composable tiện ích dùng chung cho Staff UI.
 * - formatVND
 * - formatDate/Time/DateTime
 * - viết tắt tên
 * - toast helper
 */
import { ref, type Ref } from 'vue'

export interface Toast {
    id: number
    type: 'success' | 'error' | 'warning' | 'info'
    message: string
    duration: number
}

const toasts: Ref<Toast[]> = ref([])
let toastSeq = 1

export function useToast() {
    function show(type: Toast['type'], message: string, duration = 3000): void {
        const id = toastSeq++
        toasts.value.push({ id, type, message, duration })
        setTimeout(() => {
            const idx = toasts.value.findIndex(t => t.id === id)
            if (idx >= 0) toasts.value.splice(idx, 1)
        }, duration)
    }
    return {
        toasts,
        success: (msg: string, dur?: number) => show('success', msg, dur),
        error:   (msg: string, dur?: number) => show('error', msg, dur),
        warning: (msg: string, dur?: number) => show('warning', msg, dur),
        info:    (msg: string, dur?: number) => show('info', msg, dur),
    }
}

export function formatVND(amount: number): string {
    if (typeof amount !== 'number' || Number.isNaN(amount)) return '0 đ'
    return amount.toLocaleString('vi-VN') + ' đ'
}

export function formatDate(dateStr: string | null): string {
    if (!dateStr) return '—'
    try {
        const d = new Date(dateStr)
        return d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' })
    } catch {
        return dateStr
    }
}

export function formatTime(dateStr: string | null): string {
    if (!dateStr) return '—'
    try {
        const d = new Date(dateStr)
        return d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })
    } catch {
        return dateStr
    }
}

export function formatDateTime(dateStr: string | null): string {
    if (!dateStr) return '—'
    return `${formatDate(dateStr)} ${formatTime(dateStr)}`
}

export function timeAgo(dateStr: string | null): string {
    if (!dateStr) return ''
    const now = Date.now()
    const then = new Date(dateStr).getTime()
    const diffSec = Math.floor((now - then) / 1000)
    if (diffSec < 60) return `${diffSec} giây trước`
    const min = Math.floor(diffSec / 60)
    if (min < 60) return `${min} phút trước`
    const hr = Math.floor(min / 60)
    if (hr < 24) return `${hr} giờ trước`
    const d = Math.floor(hr / 24)
    return `${d} ngày trước`
}

export function shortName(fullName: string): string {
    if (!fullName) return '?'
    const parts = fullName.trim().split(/\s+/)
    if (parts.length === 1) return parts[0]!.slice(0, 2).toUpperCase()
    return (parts[0]![0]! + parts[parts.length - 1]![0]!).toUpperCase()
}
