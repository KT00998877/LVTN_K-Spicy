<script setup lang="ts">
/**
 * ExportPanel — Component chung cho việc xuất báo cáo Excel/PDF.
 *
 * Dùng cho: ManagerDashboard, CashierReportView, ManagerSalesView, InventoryView.
 *
 * Props:
 *  - allowedTypes: danh sách type hiển thị
 *  - defaultType:  giá trị khởi tạo
 *  - needsDateRange: báo cáo có cần chọn ngày không
 *
 * Behavior:
 *  - Chọn loại báo cáo + from/to → bấm "Excel" hoặc "PDF"
 *  - Dùng `fetch` thẳng để trigger download (response là binary)
 *  - Thông báo lỗi bằng nhãn + màu sắc
 *  - Loading state trên từng button
 */
import { computed, ref, watch } from 'vue'
import { canExport, downloadReport } from '@/api/report'
import { useStaffStore } from '@/stores/staff'
import type { ReportFormat, ReportType, ReportTypeOption } from '@/types/report'

const props = withDefaults(
    defineProps<{
        allowedTypes?: ReportTypeOption[]
        defaultType?: ReportType
        showOrderFilters?: boolean
        initialFrom?: string
        initialTo?: string
    }>(),
    {
        allowedTypes: () => [
            {
                code: 'revenue',
                label: 'Doanh thu',
                description: 'Doanh thu theo ngày, phương thức thanh toán và loại đơn',
                allowedRoles: ['MANAGER', 'CASHIER'],
                needsDateRange: true,
            },
            {
                code: 'orders',
                label: 'Đơn hàng',
                description: 'Danh sách đơn hàng trong khoảng thời gian',
                allowedRoles: ['MANAGER', 'CASHIER'],
                needsDateRange: true,
            },
            {
                code: 'top_menu',
                label: 'Món bán chạy',
                description: 'Top món bán chạy theo số lượng & doanh thu',
                allowedRoles: ['MANAGER'],
                needsDateRange: true,
            },
            {
                code: 'inventory',
                label: 'Tồn kho nguyên liệu',
                description: 'Số lượng và giá trị tồn kho hiện tại',
                allowedRoles: ['MANAGER'],
                needsDateRange: false,
            },
            {
                code: 'shift',
                label: 'Ca làm việc',
                description: 'Lịch sử các ca bán hàng đã chốt',
                allowedRoles: ['MANAGER', 'CASHIER'],
                needsDateRange: true,
            },
        ],
        defaultType: 'revenue',
        showOrderFilters: false,
    },
)

const staff = useStaffStore()

const REPORT_OPTIONS: ReportTypeOption[] = props.allowedTypes

const selectedType = ref<ReportType>(props.defaultType)
const fromDate = ref<string>(props.initialFrom ?? '')
const toDate = ref<string>(props.initialTo ?? '')
const orderStatus = ref<string>('')
const orderType = ref<string>('')
const loadingFormat = ref<ReportFormat | null>(null)
const lastError = ref<string | null>(null)
const lastSuccess = ref<string | null>(null)

const userRoles = computed(() => staff.session?.roles ?? [])

watch(
    () => [props.initialFrom, props.initialTo] as const,
    ([from, to]) => {
        if (from) fromDate.value = from
        if (to) toDate.value = to
    },
    { immediate: true },
)

const canSubmit = computed(() => {
    if (!canExport(selectedType.value, userRoles.value)) {
        return false
    }
    const opt = REPORT_OPTIONS.find((o) => o.code === selectedType.value)
    if (opt?.needsDateRange && (!fromDate.value || !toDate.value)) {
        return false
    }
    if (fromDate.value && toDate.value && fromDate.value > toDate.value) {
        return false
    }
    return true
})

const currentTypeLabel = computed(() => {
    const opt = REPORT_OPTIONS.find((o) => o.code === selectedType.value)
    return opt?.label ?? selectedType.value
})

// Nếu loại được phép thay đổi → đảm bảo selectedType luôn nằm trong allowedTypes
watch(
    () => props.allowedTypes,
    (options) => {
        if (!options.find((o) => o.code === selectedType.value)) {
            selectedType.value = options[0]?.code ?? 'revenue'
        }
    },
)

async function download(format: ReportFormat) {
    if (!canSubmit.value || loadingFormat.value) return
    lastError.value = null
    lastSuccess.value = null
    loadingFormat.value = format
    try {
        const params = {
            type: selectedType.value,
            format,
            from: fromDate.value || undefined,
            to: toDate.value || undefined,
            status: orderStatus.value || undefined,
            order_type: (orderType.value as 'DINE_IN' | 'TAKEAWAY' | 'ONLINE') || undefined,
        }
        const { filename, size } = await downloadReport(params)
        lastSuccess.value = `Đã tải ${filename} (${formatSize(size)}).`
    } catch (e: unknown) {
        lastError.value = extractError(e)
    } finally {
        loadingFormat.value = null
    }
}

function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

function extractError(e: unknown): string {
    if (e instanceof Error) return e.message
    return 'Không thể xuất báo cáo. Vui lòng thử lại.'
}

function quickRange(value: 'today' | '7d' | 'month' | '30d') {
    const today = new Date()
    const yyyy = today.getFullYear()
    const mm = String(today.getMonth() + 1).padStart(2, '0')
    const dd = String(today.getDate()).padStart(2, '0')
    const todayStr = `${yyyy}-${mm}-${dd}`
    toDate.value = todayStr
    if (value === 'today') {
        fromDate.value = todayStr
    } else if (value === '7d') {
        const d = new Date(today)
        d.setDate(d.getDate() - 6)
        fromDate.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    } else if (value === '30d') {
        const d = new Date(today)
        d.setDate(d.getDate() - 29)
        fromDate.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    } else if (value === 'month') {
        fromDate.value = `${yyyy}-${mm}-01`
    }
}
</script>

<template>
    <div class="export-panel">
        <div class="export-panel__head">
            <div class="export-panel__icon">📊</div>
            <div>
                <h3 class="export-panel__title">Xuất báo cáo</h3>
                <p class="export-panel__sub">Tải {{ currentTypeLabel }} dưới định dạng Excel hoặc PDF để xem ngoại tuyến hoặc gửi qua email.</p>
            </div>
        </div>

        <div class="export-panel__grid">
            <label class="export-panel__field export-panel__field--wide">
                <span>Loại báo cáo</span>
                <select v-model="selectedType" class="export-panel__input">
                    <option v-for="opt in REPORT_OPTIONS" :key="opt.code" :value="opt.code" :disabled="!canExport(opt.code, userRoles)">
                        {{ opt.label }}<template v-if="!canExport(opt.code, userRoles)"> — yêu cầu quyền</template>
                    </option>
                </select>
                <span class="export-panel__hint">{{ REPORT_OPTIONS.find((o) => o.code === selectedType)?.description }}</span>
            </label>
        </div>

        <div class="export-panel__grid" v-if="REPORT_OPTIONS.find((o) => o.code === selectedType)?.needsDateRange">
            <label class="export-panel__field">
                <span>Từ ngày</span>
                <input type="date" v-model="fromDate" class="export-panel__input" :max="toDate || undefined" />
            </label>
            <label class="export-panel__field">
                <span>Đến ngày</span>
                <input type="date" v-model="toDate" class="export-panel__input" :min="fromDate || undefined" />
            </label>
        </div>

        <div v-if="showOrderFilters && selectedType === 'orders'" class="export-panel__grid">
            <label class="export-panel__field">
                <span>Trạng thái đơn (tuỳ chọn)</span>
                <select v-model="orderStatus" class="export-panel__input">
                    <option value="">— Tất cả —</option>
                    <option value="PENDING">Chờ xử lý</option>
                    <option value="CONFIRMED">Đã xác nhận</option>
                    <option value="PREPARING">Đang nấu</option>
                    <option value="READY">Sẵn sàng</option>
                    <option value="COMPLETED">Hoàn thành</option>
                    <option value="CANCELLED">Đã hủy</option>
                </select>
            </label>
            <label class="export-panel__field">
                <span>Loại đơn (tuỳ chọn)</span>
                <select v-model="orderType" class="export-panel__input">
                    <option value="">— Tất cả —</option>
                    <option value="DINE_IN">Tại bàn</option>
                    <option value="TAKEAWAY">Mang đi</option>
                    <option value="ONLINE">Online</option>
                </select>
            </label>
        </div>

        <div class="export-panel__quickrange" v-if="REPORT_OPTIONS.find((o) => o.code === selectedType)?.needsDateRange">
            <button type="button" class="chip" @click="quickRange('today')">Hôm nay</button>
            <button type="button" class="chip" @click="quickRange('7d')">7 ngày</button>
            <button type="button" class="chip" @click="quickRange('30d')">30 ngày</button>
            <button type="button" class="chip" @click="quickRange('month')">Tháng này</button>
        </div>

        <div class="export-panel__actions">
            <button
                type="button"
                class="export-btn export-btn--xlsx"
                :disabled="!canSubmit || loadingFormat !== null"
                @click="download('xlsx')"
            >
                <span v-if="loadingFormat === 'xlsx'" class="export-btn__loading">⏳</span>
                <span v-else class="export-btn__icon">📗</span>
                <span>Tải Excel (.xlsx)</span>
            </button>
            <button
                type="button"
                class="export-btn export-btn--pdf"
                :disabled="!canSubmit || loadingFormat !== null"
                @click="download('pdf')"
            >
                <span v-if="loadingFormat === 'pdf'" class="export-btn__loading">⏳</span>
                <span v-else class="export-btn__icon">📕</span>
                <span>Tải PDF (.pdf)</span>
            </button>
        </div>

        <p v-if="lastSuccess" class="export-panel__msg export-panel__msg--ok">✓ {{ lastSuccess }}</p>
        <p v-if="lastError" class="export-panel__msg export-panel__msg--err">⚠ {{ lastError }}</p>
    </div>
</template>

<style scoped>
.export-panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.export-panel__head {
    display: flex;
    align-items: center;
    gap: 12px;
}
.export-panel__icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #ffe4e6 0%, #fecaca 100%);
    color: #b91c1c;
    display: grid;
    place-items: center;
    font-size: 22px;
    box-shadow: inset 0 0 0 1px rgba(220, 38, 38, 0.15);
}
.export-panel__title {
    font-size: 17px;
    font-weight: 700;
    color: #1F2937;
    margin: 0;
}
.export-panel__sub {
    font-size: 13px;
    color: #64748b;
    margin: 2px 0 0;
}

.export-panel__grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.export-panel__field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 12px;
    color: #475569;
}
.export-panel__field--wide {
    grid-column: span 2;
}
.export-panel__field > span {
    font-weight: 600;
    color: #1F2937;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-size: 11px;
}
.export-panel__input {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 14px;
    color: #1F2937;
    background: #fff;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.export-panel__input:focus {
    border-color: #D71920;
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.15);
    outline: none;
}
.export-panel__hint {
    font-size: 12px;
    color: #94a3b8;
    line-height: 1.4;
}

.export-panel__quickrange {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.chip {
    border: 1px solid #e5e7eb;
    background: #f9fafb;
    color: #475569;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}
.chip:hover {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.export-panel__actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.export-btn {
    flex: 1;
    min-width: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 16px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 14px;
    border: 0;
    cursor: pointer;
    transition: transform 0.12s ease, opacity 0.12s ease, box-shadow 0.15s ease;
}
.export-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.export-btn:not(:disabled):hover {
    transform: translateY(-1px);
}
.export-btn--xlsx {
    background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
    color: #fff;
    box-shadow: 0 6px 14px rgba(22, 163, 74, 0.25);
}
.export-btn--pdf {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    color: #fff;
    box-shadow: 0 6px 14px rgba(220, 38, 38, 0.25);
}
.export-btn__icon,
.export-btn__loading {
    font-size: 18px;
}

.export-panel__msg {
    font-size: 13px;
    margin: 0;
    padding: 10px 12px;
    border-radius: 10px;
}
.export-panel__msg--ok {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #6ee7b7;
}
.export-panel__msg--err {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

@media (max-width: 640px) {
    .export-panel__grid {
        grid-template-columns: 1fr;
    }
    .export-panel__field--wide {
        grid-column: span 1;
    }
    .export-btn {
        flex: 1 1 100%;
    }
}
</style>
