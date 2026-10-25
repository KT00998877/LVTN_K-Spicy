<script setup lang="ts">
/**
 * ShortageDialog — cảnh báo bếp khi thiếu nguyên liệu lúc bắt đầu nấu.
 *
 * Bếp là người quyết định, không phải hệ thống. Nên dialog đưa đúng thông tin
 * bếp cần để ra quyết định: thiếu bao nhiêu, còn bao nhiêu, nguyên liệu nào.
 *
 *   [Tiếp tục nấu] → trừ tới 0, tạo yêu cầu nhập kho, bếp vẫn nấu món này.
 *   [Hủy món]     → hủy món, ghi "Hết nguyên liệu", báo quản lý ẩn món khỏi menu.
 */
import { ref, watch } from 'vue'
import type { KitchenShortage } from '@/api/staff'

const props = defineProps<{
    open: boolean
    dishName: string
    orderCode: string
    shortages: KitchenShortage[]
    busy?: boolean
}>()

const emit = defineEmits<{
    (e: 'continue'): void
    (e: 'cancel'): void
}>()

const dialog = ref<HTMLDialogElement | null>(null)
const list = ref<KitchenShortage[]>([])

watch(() => props.open, async (open) => {
    list.value = props.shortages ?? []
    if (open) {
        await Promise.resolve()
        if (!dialog.value?.open) dialog.value?.showModal()
    } else if (dialog.value?.open) {
        dialog.value.close()
    }
})

/** Định dạng lượng bỏ số 0 thừa cho gọn (50 g, 0.5 kg). */
function qty(value: number): string {
    return Number(value).toLocaleString('vi-VN', { maximumFractionDigits: 2 })
}
</script>

<template>
    <dialog ref="dialog" class="shortage-dialog" aria-labelledby="shortage-title" @cancel.prevent>
        <header class="shortage-dialog__head">
            <span class="shortage-dialog__icon" aria-hidden="true">🌶</span>
            <div>
                <h2 id="shortage-title">Thiếu nguyên liệu</h2>
                <p>{{ dishName }} · Đơn {{ orderCode }}</p>
            </div>
        </header>

        <p class="shortage-dialog__lead">
            Không đủ nguyên liệu để bắt đầu nấu. Bếp quyết định nấu tiếp hay hủy món.
        </p>

        <ul class="shortage-list">
            <li v-for="s in list" :key="s.ingredient_id">
                <span class="shortage-list__name">{{ s.ingredient }}</span>
                <span class="shortage-list__qty">
                    Thiếu <strong>{{ qty(s.shortage) }} {{ s.unit }}</strong>
                    <span class="shortage-list__have">(còn {{ qty(s.available) }} {{ s.unit }})</span>
                </span>
            </li>
        </ul>

        <footer class="shortage-dialog__actions">
            <button type="button" class="btn btn--danger" :disabled="busy" @click="emit('cancel')">
                Hủy món
            </button>
            <button type="button" class="btn btn--primary" :disabled="busy" @click="emit('continue')">
                {{ busy ? 'Đang xử lý…' : 'Tiếp tục nấu' }}
            </button>
        </footer>

        <p class="shortage-dialog__hint">
            Chọn “Tiếp tục nấu” sẽ gửi yêu cầu nhập kho cho quản lý.
            Chọn “Hủy món” sẽ báo quản lý ẩn món khỏi thực đơn.
        </p>
    </dialog>
</template>

<style scoped>
.shortage-dialog {
    border: 0;
    border-radius: 16px;
    padding: 0;
    width: min(520px, calc(100vw - 32px));
    background: var(--color-surface, #fff);
    color: var(--charcoal, #151515);
    box-shadow: 0 24px 64px rgba(21, 21, 21, 0.24);
}
.shortage-dialog::backdrop {
    background: rgba(21, 21, 21, 0.55);
}
.shortage-dialog__head {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 20px 20px 0;
}
.shortage-dialog__icon {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    border-radius: 12px;
    background: #fdeceb;
    font-size: 20px;
}
.shortage-dialog h2 {
    margin: 0;
    font-size: 19px;
    font-weight: 800;
    letter-spacing: -0.01em;
}
.shortage-dialog__head p {
    margin: 2px 0 0;
    color: #6b6b6b;
    font-size: 13px;
}
.shortage-dialog__lead {
    margin: 14px 20px 0;
    font-size: 14px;
    line-height: 1.5;
    color: #3f3f46;
}
.shortage-list {
    list-style: none;
    margin: 14px 20px 0;
    padding: 0;
    display: grid;
    gap: 8px;
}
.shortage-list li {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #fdf6f2;
    border-left: 3px solid var(--chili-red, #e4263d);
}
.shortage-list__name {
    font-weight: 700;
    font-size: 14px;
}
.shortage-list__qty {
    font-size: 13px;
    color: #52525b;
    text-align: right;
}
.shortage-list__qty strong {
    color: var(--chili-red, #e4263d);
}
.shortage-list__have {
    display: block;
    font-size: 12px;
    color: #8a8a8a;
}
.shortage-dialog__actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    padding: 18px 20px 0;
}
.shortage-dialog__hint {
    margin: 12px 20px 20px;
    font-size: 12px;
    line-height: 1.5;
    color: #8a8a8a;
}
.btn {
    min-height: 46px;
    border: 0;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: filter 0.15s ease, transform 0.1s ease;
}
.btn:active:not(:disabled) {
    transform: translateY(1px);
}
.btn:disabled {
    opacity: 0.6;
    cursor: wait;
}
.btn--danger {
    background: #fdeceb;
    color: #b42318;
    border: 1px solid #f3c9c5;
}
.btn--primary {
    background: var(--chili-red, #e4263d);
    color: #fff;
}
.btn:focus-visible {
    outline: 3px solid #b91c1c;
    outline-offset: 2px;
}
@media (max-width: 420px) {
    .shortage-dialog__actions {
        grid-template-columns: 1fr;
    }
}
</style>
