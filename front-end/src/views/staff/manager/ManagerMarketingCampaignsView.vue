<script setup lang="ts">
/**
 * ManagerMarketingCampaignsView — quản lý chiến dịch ưu đãi gửi khách (Phase 1).
 *
 * Manager đặt lịch ưu đãi theo khung giờ vàng, gắn mã voucher sẵn có, chọn
 * hạng thành viên được nhận và theo dõi số khách đã gửi.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useAppDialog } from '@/composables/useAppDialog'
import {
    cancelMarketingCampaign,
    createMarketingCampaign,
    deleteMarketingCampaign,
    fetchMarketingCampaigns,
    fetchTierOptions,
    fetchVoucherOptions,
    sendMarketingCampaignNow,
    updateMarketingCampaign,
    type MarketingCampaign,
    type MarketingCampaignPayload,
    type MarketingCampaignStatus,
    type TierOption,
    type VoucherOption,
} from '@/api/marketingCampaigns'

const campaigns = ref<MarketingCampaign[]>([])
const appDialog = useAppDialog()
const vouchers = ref<VoucherOption[]>([])
const tiers = ref<TierOption[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const statusFilter = ref<MarketingCampaignStatus | ''>('')

const modalOpen = ref(false)
const editingId = ref<number | null>(null)

const form = reactive<MarketingCampaignPayload>({
    name: '',
    message: '',
    voucher_code: '',
    banner_image_url: null,
    accent_color: '#E83A14',
    cta_text: 'Nhận ưu đãi',
    landing_url: null,
    target_tier_ids: [],
    send_at: '',
    window_start: '11:00',
    window_end: '13:30',
})

const STATUS_LABELS: Record<MarketingCampaignStatus, string> = {
    SCHEDULED: 'Chờ gửi',
    SENT: 'Đã gửi',
    SKIPPED: 'Bỏ qua',
    CANCELLED: 'Đã huỷ',
}

const isEditing = computed(() => editingId.value !== null)

const filtered = computed(() => {
    if (!statusFilter.value) return campaigns.value
    return campaigns.value.filter(c => c.status === statusFilter.value)
})

onMounted(async () => {
    await Promise.all([loadCampaigns(), loadOptions()])
})

async function loadCampaigns() {
    loading.value = true
    error.value = ''
    try {
        campaigns.value = await fetchMarketingCampaigns()
    } catch {
        error.value = 'Chưa tải được danh sách chiến dịch. Vui lòng thử lại.'
    } finally {
        loading.value = false
    }
}

async function loadOptions() {
    try {
        const [v, t] = await Promise.all([fetchVoucherOptions(), fetchTierOptions()])
        vouchers.value = v
        tiers.value = t
    } catch {
        // Không chặn form — người dùng vẫn gõ tay được.
    }
}

function openCreate() {
    editingId.value = null
    Object.assign(form, {
        name: '',
        message: '',
        voucher_code: vouchers.value[0]?.code ?? '',
        banner_image_url: null,
        accent_color: '#E83A14',
        cta_text: 'Nhận ưu đãi',
        landing_url: null,
        target_tier_ids: [],
        send_at: defaultSendAt(),
        window_start: '11:00',
        window_end: '13:30',
    })
    error.value = ''
    modalOpen.value = true
}

function openEdit(campaign: MarketingCampaign) {
    editingId.value = campaign.id
    Object.assign(form, {
        name: campaign.name,
        message: campaign.message,
        voucher_code: campaign.voucher_code,
        banner_image_url: campaign.banner_image_url,
        accent_color: campaign.accent_color,
        cta_text: campaign.cta_text,
        landing_url: campaign.landing_url,
        target_tier_ids: campaign.target_tier_ids ?? [],
        send_at: toLocalInput(campaign.send_at),
        window_start: campaign.window_start,
        window_end: campaign.window_end,
    })
    error.value = ''
    modalOpen.value = true
}

/** Mặc định gửi trong 10 phút tới (đủ để cron 10 phút bắt được). */
function defaultSendAt(): string {
    const d = new Date(Date.now() + 10 * 60 * 1000)
    d.setSeconds(0, 0)
    return toLocalInput(d.toISOString())
}

/** ISO → giá trị cho input[type=datetime-local]. */
function toLocalInput(iso: string): string {
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return ''
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function toggleTier(id: number) {
    const current = form.target_tier_ids ?? []
    form.target_tier_ids = current.includes(id)
        ? current.filter(t => t !== id)
        : [...current, id]
}

async function save() {
    saving.value = true
    error.value = ''
    try {
        const payload: MarketingCampaignPayload = {
            ...form,
            send_at: new Date(form.send_at).toISOString(),
        }
        if (isEditing.value) {
            await updateMarketingCampaign(editingId.value!, payload)
            notice.value = 'Đã cập nhật chiến dịch.'
        } else {
            await createMarketingCampaign(payload)
            notice.value = 'Đã tạo chiến dịch. Hệ thống sẽ tự gửi trong khung giờ vàng.'
        }
        modalOpen.value = false
        await loadCampaigns()
    } catch (e) {
        error.value = (e as { response?: { data?: { message?: string } } }).response?.data?.message
            ?? 'Không lưu được chiến dịch. Vui lòng thử lại.'
    } finally {
        saving.value = false
    }
}

async function sendNow(campaign: MarketingCampaign) {
    if (!await appDialog.confirm({ title: 'Gửi ưu đãi ngay?', message: `“${campaign.name}” sẽ được gửi tới khách hàng ngay bây giờ.`, confirmLabel: 'Gửi ưu đãi', tone: 'warning' })) return
    try {
        const result = await sendMarketingCampaignNow(campaign.id)
        notice.value = result.message
        await loadCampaigns()
    } catch (e) {
        error.value = (e as { response?: { data?: { message?: string } } }).response?.data?.message
            ?? 'Không gửi được ưu đãi.'
    }
}

async function cancel(campaign: MarketingCampaign) {
    if (!await appDialog.confirm({ title: 'Hủy lịch gửi?', message: `Lịch gửi ưu đãi “${campaign.name}” sẽ bị hủy.`, confirmLabel: 'Hủy lịch gửi', tone: 'danger' })) return
    try {
        await cancelMarketingCampaign(campaign.id)
        notice.value = 'Đã huỷ lịch gửi.'
        await loadCampaigns()
    } catch (e) {
        error.value = (e as { response?: { data?: { message?: string } } }).response?.data?.message
            ?? 'Không huỷ được chiến dịch.'
    }
}

async function remove(campaign: MarketingCampaign) {
    if (!await appDialog.confirm({ title: 'Xóa chiến dịch?', message: `Chiến dịch “${campaign.name}” sẽ bị xóa.`, confirmLabel: 'Xóa chiến dịch', tone: 'danger' })) return
    try {
        await deleteMarketingCampaign(campaign.id)
        notice.value = 'Đã xoá chiến dịch.'
        await loadCampaigns()
    } catch (e) {
        error.value = (e as { response?: { data?: { message?: string } } }).response?.data?.message
            ?? 'Không xoá được chiến dịch.'
    }
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('vi-VN', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    })
}
</script>

<template>
    <div class="campaigns-page">
        <header class="page-head">
            <div>
                <span class="eyebrow">ƯU ĐÃI KHUNG GIỜ VÀNG</span>
                <h2>Chiến dịch ưu đãi tới khách</h2>
                <p>
                    Đặt lịch ưu đãi theo khung giờ vàng (trưa/tối). Hệ thống tự gửi thông báo
                    tới khách thân thiết kèm mã voucher, khách copy mã và áp dụng khi thanh toán.
                </p>
            </div>
            <div class="head-cta">
                <button class="btn primary" :disabled="loading" @click="openCreate">+ Thêm chiến dịch</button>
            </div>
        </header>

        <p v-if="notice" class="notice" role="status">{{ notice }}</p>
        <p v-if="error && !modalOpen" class="error" role="alert">{{ error }}</p>

        <nav class="tabs" role="tablist" aria-label="Lọc theo trạng thái">
            <button role="tab" :aria-selected="statusFilter === ''" :class="{ active: statusFilter === '' }" @click="statusFilter = ''">
                Tất cả
            </button>
            <button
                v-for="(label, key) in STATUS_LABELS"
                :key="key"
                role="tab"
                :aria-selected="statusFilter === key"
                :class="{ active: statusFilter === key }"
                @click="statusFilter = key"
            >
                {{ label }}
            </button>
        </nav>

        <p v-if="loading" class="muted" role="status">Đang tải chiến dịch…</p>
        <p v-else-if="!filtered.length" class="muted empty-state">
            Chưa có chiến dịch nào. Bấm "+ Thêm chiến dịch" để tạo ưu đãi đầu tiên.
        </p>

        <ul v-else class="campaign-list">
            <li v-for="campaign in filtered" :key="campaign.id" class="campaign-card">
                <div class="campaign-main">
                    <div class="campaign-head">
                        <h3>{{ campaign.name }}</h3>
                        <span class="status-pill" :class="`status-${campaign.status.toLowerCase()}`">
                            {{ STATUS_LABELS[campaign.status] }}
                        </span>
                    </div>
                    <p class="campaign-message">{{ campaign.message }}</p>
                    <div class="campaign-meta">
                        <span class="code-chip">{{ campaign.voucher_code }}</span>
                        <span v-if="campaign.discount_label" class="discount-chip">{{ campaign.discount_label }}</span>
                        <span class="meta-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
                            </svg>
                            {{ campaign.window_start }}–{{ campaign.window_end }}
                        </span>
                        <span class="meta-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" />
                            </svg>
                            {{ formatDate(campaign.send_at) }}
                        </span>
                        <span v-if="campaign.status === 'SENT'" class="meta-item">
                            Đã gửi {{ campaign.recipient_count }} khách
                        </span>
                    </div>
                </div>

                <div class="campaign-actions">
                    <button
                        v-if="campaign.status === 'SCHEDULED'"
                        class="btn ghost"
                        @click="sendNow(campaign)"
                    >
                        Gửi ngay
                    </button>
                    <button
                        v-if="campaign.status === 'SCHEDULED'"
                        class="btn ghost"
                        @click="openEdit(campaign)"
                    >
                        Sửa
                    </button>
                    <button
                        v-if="campaign.status === 'SCHEDULED'"
                        class="btn ghost danger"
                        @click="cancel(campaign)"
                    >
                        Huỷ
                    </button>
                    <button
                        v-if="campaign.status !== 'SENT'"
                        class="btn ghost danger"
                        @click="remove(campaign)"
                    >
                        Xoá
                    </button>
                </div>
            </li>
        </ul>

        <!-- ===== Modal tạo / sửa ===== -->
        <div v-if="modalOpen" class="modal-backdrop" @click.self="modalOpen = false">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="campaign-modal-title">
                <header class="modal-head">
                    <h3 id="campaign-modal-title">{{ isEditing ? 'Sửa chiến dịch' : 'Thêm chiến dịch ưu đãi' }}</h3>
                    <button class="icon-close" type="button" aria-label="Đóng" @click="modalOpen = false">✕</button>
                </header>

                <div class="modal-body">
                    <label class="field">
                        <span>Tên chiến dịch</span>
                        <input v-model.trim="form.name" type="text" maxlength="150" placeholder="Flash deal trưa" required />
                    </label>

                    <label class="field">
                        <span>Nội dung thông báo (hiện trong chuông)</span>
                        <textarea v-model.trim="form.message" rows="2" maxlength="1000" placeholder="Mì cay giảm 20% từ 11h-13h30!" required />
                    </label>

                    <label class="field">
                        <span>Mã voucher áp dụng</span>
                        <select v-model="form.voucher_code" required>
                            <option value="" disabled>— Chọn mã voucher —</option>
                            <option v-for="v in vouchers" :key="v.code" :value="v.code">
                                {{ v.code }} — {{ v.name }}
                            </option>
                        </select>
                        <small v-if="!vouchers.length" class="hint">
                            Chưa có mã voucher nào. Tạo mã ở trang "Khuyến mãi &amp; Voucher" trước.
                        </small>
                    </label>

                    <div class="field-row">
                        <label class="field">
                            <span>Ngày &amp; giờ gửi</span>
                            <input v-model="form.send_at" type="datetime-local" required />
                        </label>
                        <label class="field">
                            <span>Màu banner</span>
                            <input v-model="form.accent_color" type="color" class="color-input" />
                        </label>
                    </div>

                    <div class="field-row">
                        <label class="field">
                            <span>Khung giờ vàng — bắt đầu</span>
                            <input v-model="form.window_start" type="time" required />
                        </label>
                        <label class="field">
                            <span>Khung giờ vàng — kết thúc</span>
                            <input v-model="form.window_end" type="time" required />
                        </label>
                    </div>

                    <div class="field-row">
                        <label class="field">
                            <span>Chữ trên nút</span>
                            <input v-model.trim="form.cta_text" type="text" maxlength="60" placeholder="Nhận ưu đãi" />
                        </label>
                        <label class="field">
                            <span>Ảnh banner (URL)</span>
                            <input v-model.trim="form.banner_image_url" type="text" placeholder="https://… (để trống dùng màu)" />
                        </label>
                    </div>

                    <fieldset class="tier-picker">
                        <legend>Hạng thành viên được nhận</legend>
                        <p class="hint">Bỏ chọn tất cả = gửi cho mọi khách đã từng chi.</p>
                        <label v-for="tier in tiers" :key="tier.id" class="tier-option">
                            <input
                                type="checkbox"
                                :checked="(form.target_tier_ids ?? []).includes(tier.id)"
                                @change="toggleTier(tier.id)"
                            />
                            <span :style="tier.color ? { color: tier.color } : undefined">{{ tier.name }}</span>
                        </label>
                    </fieldset>

                    <p v-if="error" class="error" role="alert">{{ error }}</p>
                </div>

                <footer class="modal-foot">
                    <button class="btn ghost" type="button" @click="modalOpen = false">Huỷ</button>
                    <button class="btn primary" type="button" :disabled="saving" @click="save">
                        {{ saving ? 'Đang lưu…' : isEditing ? 'Lưu thay đổi' : 'Tạo chiến dịch' }}
                    </button>
                </footer>
            </div>
        </div>
    </div>
</template>

<style scoped>
.campaigns-page {
    display: flex;
    flex-direction: column;
    gap: 18px;
    font: 14px/1.5 'Inter', sans-serif;
    color: var(--charcoal, #1a1a1a);
}
.page-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.eyebrow {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.4px;
    color: var(--chili, #d71920);
    margin-bottom: 6px;
}
.page-head h2 { margin: 0 0 4px; font-size: 24px; font-weight: 800; letter-spacing: -0.01em; }
.page-head p { margin: 0; color: #6b5f54; font-size: 14px; max-width: 70ch; }
.head-cta { display: flex; gap: 8px; }

.notice {
    margin: 0;
    padding: 10px 14px;
    border-radius: 10px;
    background: #eaf7ee;
    color: #1b6b34;
    font-size: 14px;
}
.error { margin: 0; padding: 10px 14px; border-radius: 10px; background: #fdecea; color: #a32118; font-size: 14px; }
.muted { color: #6b5f54; }
.empty-state { padding: 40px 0; text-align: center; }

.tabs { display: flex; gap: 6px; border-bottom: 1px solid rgba(26, 14, 6, 0.08); flex-wrap: wrap; }
.tabs button {
    border: none;
    background: transparent;
    padding: 10px 16px;
    font: inherit;
    font-weight: 600;
    color: #6b5f54;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    transition: color 0.12s, border-color 0.12s;
}
.tabs button:hover { color: var(--chili, #d71920); }
.tabs button.active { color: var(--chili, #d71920); border-bottom-color: var(--chili, #d71920); }

.campaign-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
.campaign-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px;
    border: 1px solid rgba(26, 14, 6, 0.08);
    border-radius: 14px;
    background: #fff;
    flex-wrap: wrap;
}
.campaign-main { flex: 1 1 320px; min-width: 0; }
.campaign-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.campaign-head h3 { margin: 0; font-size: 16px; font-weight: 700; }

.status-pill { padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.status-scheduled { background: #fff3e0; color: #92400e; }
.status-sent { background: #eaf7ee; color: #1b6b34; }
.status-skipped { background: #f1f1ef; color: #6b6b6b; }
.status-cancelled { background: #fdecea; color: #a32118; }

.campaign-message { margin: 6px 0 0; color: #4a4038; font-size: 14px; }
.campaign-meta { display: flex; align-items: center; gap: 8px; margin-top: 10px; flex-wrap: wrap; }

.code-chip {
    padding: 4px 10px;
    border: 1.5px dashed var(--chili, #d71920);
    border-radius: 8px;
    color: var(--chili, #d71920);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.05em;
}
.discount-chip { padding: 4px 10px; border-radius: 8px; background: #fff3e0; color: #92400e; font-size: 12px; font-weight: 700; }
.meta-item { display: inline-flex; align-items: center; gap: 5px; color: #6b5f54; font-size: 12px; }

.campaign-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.btn {
    min-height: 38px;
    padding: 8px 14px;
    border: 1px solid transparent;
    border-radius: 10px;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
    transition: filter 0.12s, background 0.12s;
}
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn.primary { background: var(--chili, #d71920); color: #fff; }
.btn.primary:not(:disabled):hover { filter: brightness(0.94); }
.btn.ghost { background: transparent; border-color: rgba(26, 14, 6, 0.14); color: inherit; }
.btn.ghost:hover:not(:disabled) { background: rgba(26, 14, 6, 0.04); }
.btn.ghost.danger { color: #a32118; border-color: rgba(163, 33, 24, 0.3); }

.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 200;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(21, 21, 21, 0.45);
}
.modal {
    width: min(680px, 100%);
    max-height: calc(100dvh - 40px);
    display: flex;
    flex-direction: column;
    border-radius: 16px;
    background: #fff;
    overflow: hidden;
}
.modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid rgba(26, 14, 6, 0.08);
}
.modal-head h3 { margin: 0; font-size: 17px; font-weight: 700; }
.icon-close {
    min-width: 36px;
    min-height: 36px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    font-size: 15px;
    cursor: pointer;
}
.icon-close:hover { background: rgba(0, 0, 0, 0.05); }

.modal-body { padding: 18px 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }

.field { display: flex; flex-direction: column; gap: 6px; }
.field > span { font-size: 13px; font-weight: 600; }
.field input[type="text"],
.field input[type="datetime-local"],
.field input[type="time"],
.field select,
.field textarea {
    width: 100%;
    min-height: 40px;
    padding: 9px 12px;
    border: 1px solid rgba(26, 14, 6, 0.16);
    border-radius: 10px;
    background: #fff;
    color: inherit;
    font: inherit;
    box-sizing: border-box;
}
.field textarea { min-height: 64px; resize: vertical; }
.color-input { padding: 4px; height: 40px; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.hint { color: #8a7f76; font-size: 12px; }

.tier-picker { margin: 0; padding: 12px 14px; border: 1px solid rgba(26, 14, 6, 0.12); border-radius: 12px; }
.tier-picker legend { padding: 0 6px; font-size: 13px; font-weight: 600; }
.tier-picker .hint { margin: 0 0 8px; }
.tier-option { display: inline-flex; align-items: center; gap: 6px; margin-right: 14px; font-size: 13px; cursor: pointer; }

.modal-foot {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 14px 20px;
    border-top: 1px solid rgba(26, 14, 6, 0.08);
}

@media (max-width: 620px) {
    .field-row { grid-template-columns: 1fr; }
    .campaign-card { flex-direction: column; align-items: stretch; }
}
</style>
