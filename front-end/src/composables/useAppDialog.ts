import { computed, ref } from 'vue'

export type DialogTone = 'default' | 'danger' | 'warning'

export interface ConfirmDialogOptions {
    title: string
    message: string
    confirmLabel?: string
    cancelLabel?: string
    tone?: DialogTone
}

export interface PromptDialogOptions extends ConfirmDialogOptions {
    label: string
    initialValue?: string
    placeholder?: string
}

interface DialogRequest {
    id: number
    kind: 'confirm' | 'prompt'
    title: string
    message: string
    confirmLabel: string
    cancelLabel: string
    tone: DialogTone
    label?: string
    placeholder?: string
    value: string
    resolve: (value: boolean | string | null) => void
}

const queue = ref<DialogRequest[]>([])
let nextId = 1

export function useAppDialog() {
    const current = computed(() => queue.value[0] ?? null)

    function enqueue(options: Omit<DialogRequest, 'id' | 'resolve'>): Promise<boolean | string | null> {
        return new Promise((resolve) => {
            queue.value.push({ ...options, id: nextId++, resolve })
        })
    }

    function confirm(options: ConfirmDialogOptions): Promise<boolean> {
        return enqueue({
            kind: 'confirm',
            title: options.title,
            message: options.message,
            confirmLabel: options.confirmLabel ?? 'Xác nhận',
            cancelLabel: options.cancelLabel ?? 'Quay lại',
            tone: options.tone ?? 'default',
            value: '',
        }) as Promise<boolean>
    }

    function prompt(options: PromptDialogOptions): Promise<string | null> {
        return enqueue({
            kind: 'prompt',
            title: options.title,
            message: options.message,
            confirmLabel: options.confirmLabel ?? 'Tiếp tục',
            cancelLabel: options.cancelLabel ?? 'Hủy',
            tone: options.tone ?? 'default',
            label: options.label,
            placeholder: options.placeholder,
            value: options.initialValue ?? '',
        }) as Promise<string | null>
    }

    function resolveCurrent(value: boolean | string | null): void {
        const request = queue.value[0]
        if (!request) return
        request.resolve(value)
        queue.value.shift()
    }

    return { current, confirm, prompt, resolveCurrent }
}
