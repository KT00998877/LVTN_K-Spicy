<template>
    <div class="promotions-page">
        <header class="page-head">
            <div>
                <span class="eyebrow">CHIẾN DỊCH KHUYẾN MÃI</span>
                <h2>Khuyến mãi &amp; Voucher</h2>
                <p>Quản lý mã nhập tay và khuyến mãi tự động áp vào giỏ hàng.</p>
            </div>
            <div class="head-cta">
                <button
                    v-if="activeTab === 'voucher'"
                    class="btn primary"
                    :disabled="store.loading || store.saving"
                    @click="openCreateVoucher"
                >
                    + Thêm mã giảm giá
                </button>
                <button
                    v-if="activeTab === 'promotion'"
                    class="btn primary"
                    :disabled="store.promoLoading || store.promoSaving"
                    @click="openCreatePromotion"
                >
                    + Thêm khuyến mãi
                </button>
                <button
                    v-if="activeTab === 'campaign'"
                    class="btn primary"
                    :disabled="store.campaignLoading || store.campaignSaving"
                    @click="openCreateCampaign"
                >
                    + Thêm chiến dịch
                </button>
            </div>
        </header>

        <nav class="tabs" role="tablist" aria-label="Loại khuyến mãi">
            <button
                role="tab"
                :aria-selected="activeTab === 'voucher'"
                :class="{ active: activeTab === 'voucher' }"
                @click="activeTab = 'voucher'"
            >
                Mã giảm giá (Voucher)
            </button>
            <button
                role="tab"
                :aria-selected="activeTab === 'promotion'"
                :class="{ active: activeTab === 'promotion' }"
                @click="activeTab = 'promotion'"
            >
                Khuyến mãi tự động
            </button>
            <button
                role="tab"
                :aria-selected="activeTab === 'campaign'"
                :class="{ active: activeTab === 'campaign' }"
                @click="activeTab = 'campaign'"
            >
                Chiến dịch
            </button>
        </nav>

        <!-- ============ TAB: VOUCHER (Phase 3) ============ -->
        <template v-if="activeTab === 'voucher'">
            <section class="kpi-strip" aria-label="Tổng quan voucher">
                <article class="kpi">
                    <span class="label">Tổng mã</span>
                    <strong>{{ store.stats.total }}</strong>
                </article>
                <article class="kpi accent">
                    <span class="label">Đang kích hoạt</span>
                    <strong>{{ store.stats.active }}</strong>
                </article>
                <article class="kpi">
                    <span class="label">Tạm ngưng</span>
                    <strong>{{ store.stats.inactive }}</strong>
                </article>
                <article class="kpi warn">
                    <span class="label">Sắp hết hạn (7 ngày)</span>
                    <strong>{{ store.stats.expiringSoon }}</strong>
                </article>
            </section>

            <div class="layout">
                <section class="main">
                    <form class="filters" @submit.prevent="onSearchVoucher">
                        <input
                            v-model="voucherSearch"
                            placeholder="Tìm theo mã hoặc tên…"
                            aria-label="Tìm voucher"
                            maxlength="100"
                        />
                        <select v-model="voucherFilters.is_active" aria-label="Trạng thái">
                            <option value="">Tất cả trạng thái</option>
                            <option value="active">Đang kích hoạt</option>
                            <option value="inactive">Tạm ngưng</option>
                        </select>
                        <select v-model="voucherFilters.type" aria-label="Loại">
                            <option value="">Tất cả loại</option>
                            <option value="PERCENT">Giảm theo %</option>
                            <option value="FIXED">Giảm cố định</option>
                        </select>
                        <input v-model="voucherFilters.from" type="date" aria-label="Từ ngày" />
                        <input v-model="voucherFilters.to" type="date" aria-label="Đến ngày" />
                        <button class="btn" :disabled="store.loading">Lọc</button>
                        <button type="button" class="btn ghost" :disabled="store.loading" @click="resetVoucherFilters">
                            Đặt lại
                        </button>
                    </form>

                    <p v-if="voucherBanner.error" class="alert error" role="alert">{{ voucherBanner.error }}</p>
                    <p v-if="voucherBanner.success" class="alert success" role="status">
                        {{ voucherBanner.success }}
                    </p>
                    <p v-if="store.loading" class="state">Đang tải danh sách…</p>

                    <template v-else>
                        <p class="result-count">{{ store.meta.total }} mã phù hợp</p>

                        <div v-if="store.rows.length" class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Mã &amp; tên</th>
                                        <th>Loại</th>
                                        <th>Điều kiện</th>
                                        <th>Sử dụng</th>
                                        <th>Hiệu lực</th>
                                        <th>Trạng thái</th>
                                        <th class="actions-col">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="v in store.rows" :key="v.id" :class="{ dim: !v.is_active }">
                                        <td>
                                            <div class="cell-primary">
                                                <span class="code">{{ v.code }}</span>
                                                <strong>{{ v.name }}</strong>
                                                <small v-if="v.description">{{ v.description }}</small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="type-pill" :class="v.type.toLowerCase()">{{ v.badge }}</span>
                                        </td>
                                        <td>
                                            <div v-if="v.min_order_subtotal > 0">
                                                Đơn ≥ {{ formatMoney(v.min_order_subtotal) }}
                                            </div>
                                            <div v-else class="muted">Không yêu cầu tối thiểu</div>
                                            <small v-if="v.usage_limit_per_user" class="muted">
                                                {{ v.usage_limit_per_user }} lượt / khách
                                            </small>
                                        </td>
                                        <td>
                                            <div class="quota">
                                                <div class="quota-bar">
                                                    <div
                                                        class="quota-fill"
                                                        :style="{ width: voucherUsagePercent(v) + '%' }"
                                                    />
                                                </div>
                                                <small>{{ v.used_count }} / {{ v.usage_limit || '∞' }}</small>
                                            </div>
                                        </td>
                                        <td>
                                            <div v-if="v.starts_at || v.ends_at" class="muted small">
                                                <span v-if="v.starts_at">{{ formatDate(v.starts_at) }}</span>
                                                <span v-if="v.starts_at && v.ends_at"> → </span>
                                                <span v-if="v.ends_at">{{ formatDate(v.ends_at) }}</span>
                                            </div>
                                            <div v-else class="muted">Không giới hạn</div>
                                            <small
                                                v-if="v.is_within_window === false && v.is_active"
                                                class="warn"
                                            >
                                                Ngoài thời gian
                                            </small>
                                        </td>
                                        <td>
                                            <button
                                                class="status-toggle"
                                                :class="{ on: v.is_active }"
                                                :disabled="store.saving"
                                                @click="onToggleVoucher(v)"
                                            >
                                                <span class="dot" />
                                                {{ v.is_active ? 'Kích hoạt' : 'Tạm ngưng' }}
                                            </button>
                                        </td>
                                        <td class="actions-col">
                                            <div class="row-actions">
                                                <button class="btn xs ghost" @click="openEditVoucher(v)">Sửa</button>
                                                <button class="btn xs ghost" @click="openVoucherUsages(v)">
                                                    Lịch sử
                                                </button>
                                                <button
                                                    class="btn xs ghost danger"
                                                    :disabled="store.saving"
                                                    @click="onDeleteVoucher(v)"
                                                >
                                                    Xoá
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="state empty">
                            <strong>Chưa có mã nào.</strong>
                            <small>Bấm "Thêm mã giảm giá" ở góc trên để tạo mã đầu tiên.</small>
                        </p>

                        <div v-if="store.meta.last_page > 1" class="pagination">
                            <button
                                class="btn ghost"
                                :disabled="store.meta.current_page <= 1"
                                @click="setVoucherPage(store.meta.current_page - 1)"
                            >
                                Trước
                            </button>
                            <span>Trang {{ store.meta.current_page }} / {{ store.meta.last_page }}</span>
                            <button
                                class="btn ghost"
                                :disabled="store.meta.current_page >= store.meta.last_page"
                                @click="setVoucherPage(store.meta.current_page + 1)"
                            >
                                Sau
                            </button>
                        </div>
                    </template>
                </section>

                <aside class="side">
                    <VoucherPreviewPanel
                        ref="voucherPreviewRef"
                        :initial-code="lastVoucherCode"
                        @submit="onTestVoucher"
                    />
                </aside>
            </div>

            <VoucherFormModal
                ref="voucherFormRef"
                :voucher="editingVoucher"
                @save="onSaveVoucher"
                @cancel="onCancelEditVoucher"
            />

            <VoucherUsagesDrawer
                :open="voucherDrawerOpen"
                :voucher="voucherDrawer"
                :usages="voucherUsages"
                :meta="voucherUsagesMeta"
                :loading="store.loadingUsages"
                :error="store.usagesError"
                :page="voucherUsagesPage"
                @close="closeVoucherUsages"
                @change-page="onVoucherDrawerPage"
            />
        </template>

        <!-- ============ TAB: PROMOTION (Phase 4) ============ -->
        <template v-if="activeTab === 'promotion'">
            <section class="kpi-strip" aria-label="Tổng quan khuyến mãi tự động">
                <article class="kpi">
                    <span class="label">Tổng chương trình</span>
                    <strong>{{ store.promoMeta.total }}</strong>
                </article>
                <article class="kpi accent">
                    <span class="label">Đang kích hoạt</span>
                    <strong>{{ promoStats.active }}</strong>
                </article>
                <article class="kpi">
                    <span class="label">Tạm ngưng</span>
                    <strong>{{ promoStats.inactive }}</strong>
                </article>
                <article class="kpi warn">
                    <span class="label">Đã đạt quota</span>
                    <strong>{{ promoStats.outOfQuota }}</strong>
                </article>
            </section>

            <div class="layout">
                <section class="main">
                    <form class="filters" @submit.prevent="onSearchPromotion">
                        <input
                            v-model="promoSearch"
                            placeholder="Tìm theo tên hoặc slug…"
                            aria-label="Tìm khuyến mãi"
                            maxlength="100"
                        />
                        <select v-model="promoFilters.is_active" aria-label="Trạng thái">
                            <option value="">Tất cả trạng thái</option>
                            <option value="active">Đang kích hoạt</option>
                            <option value="inactive">Tạm ngưng</option>
                        </select>
                        <select v-model="promoFilters.kind" aria-label="Loại">
                            <option value="">Tất cả loại</option>
                            <option value="PERCENT_OFF">Giảm theo %</option>
                            <option value="FIXED_OFF">Giảm cố định</option>
                            <option value="BXGY">Mua X tặng Y</option>
                            <option value="COMBO">Combo</option>
                        </select>
                        <input v-model="promoFilters.from" type="date" aria-label="Từ ngày" />
                        <input v-model="promoFilters.to" type="date" aria-label="Đến ngày" />
                        <button class="btn" :disabled="store.promoLoading">Lọc</button>
                        <button
                            type="button"
                            class="btn ghost"
                            :disabled="store.promoLoading"
                            @click="resetPromoFilters"
                        >
                            Đặt lại
                        </button>
                    </form>

                    <p v-if="promoBanner.error" class="alert error" role="alert">{{ promoBanner.error }}</p>
                    <p v-if="promoBanner.success" class="alert success" role="status">{{ promoBanner.success }}</p>
                    <p v-if="store.promoLoading" class="state">Đang tải danh sách…</p>

                    <template v-else>
                        <p class="result-count">{{ store.promoMeta.total }} chương trình phù hợp</p>

                        <PromotionListTable
                            v-if="store.promoRows.length"
                            :rows="store.promoRows"
                            :saving="store.promoSaving"
                            @edit="openEditPromotion"
                            @delete="onDeletePromotion"
                            @toggle="onTogglePromotion"
                            @usages="openPromoUsages"
                        />
                        <p v-else class="state empty">
                            <strong>Chưa có chương trình nào.</strong>
                            <small>Bấm "Thêm khuyến mãi" ở góc trên để tạo chương trình đầu tiên.</small>
                        </p>

                        <div v-if="store.promoMeta.last_page > 1" class="pagination">
                            <button
                                class="btn ghost"
                                :disabled="store.promoMeta.current_page <= 1"
                                @click="setPromoPage(store.promoMeta.current_page - 1)"
                            >
                                Trước
                            </button>
                            <span>Trang {{ store.promoMeta.current_page }} / {{ store.promoMeta.last_page }}</span>
                            <button
                                class="btn ghost"
                                :disabled="store.promoMeta.current_page >= store.promoMeta.last_page"
                                @click="setPromoPage(store.promoMeta.current_page + 1)"
                            >
                                Sau
                            </button>
                        </div>
                    </template>
                </section>

                <aside class="side">
                    <PromotionPreviewPanel />
                </aside>
            </div>

            <PromotionFormModal
                ref="promoFormRef"
                :promotion="editingPromotionPayload"
                @save="onSavePromotion"
                @cancel="onCancelEditPromotion"
            />

            <PromotionUsagesDrawer
                :open="promoDrawerOpen"
                :promotion="promoDrawer"
                :usages="promoUsages"
                :meta="promoUsagesMeta"
                :loading="store.promoLoadingUsages"
                :error="store.promoUsagesError"
                :page="promoUsagesPage"
                @close="closePromoUsages"
                @change-page="onPromoDrawerPage"
            />
        </template>

        <!-- ============ TAB: CAMPAIGN (Phase 5) ============ -->
        <template v-if="activeTab === 'campaign'">
            <section class="kpi-strip" aria-label="Tổng quan chiến dịch">
                <article class="kpi">
                    <span class="label">Tổng chiến dịch</span>
                    <strong>{{ store.campaignMeta.total }}</strong>
                </article>
                <article class="kpi accent">
                    <span class="label">Đang chạy</span>
                    <strong>{{ campaignStats.live }}</strong>
                </article>
                <article class="kpi">
                    <span class="label">Tạm ngưng</span>
                    <strong>{{ campaignStats.paused }}</strong>
                </article>
                <article class="kpi warn">
                    <span class="label">Tổng KM con</span>
                    <strong>{{ campaignStats.totalPromotions }}</strong>
                </article>
            </section>

            <div class="layout">
                <section class="main">
                    <form class="filters" @submit.prevent="onSearchCampaign">
                        <input
                            v-model="campaignSearch"
                            placeholder="Tìm theo tên hoặc slug…"
                            aria-label="Tìm chiến dịch"
                            maxlength="100"
                        />
                        <input v-model="campaignFilters.from" type="date" aria-label="Từ ngày" />
                        <input v-model="campaignFilters.to" type="date" aria-label="Đến ngày" />
                        <button class="btn" :disabled="store.campaignLoading">Lọc</button>
                        <button
                            type="button"
                            class="btn ghost"
                            :disabled="store.campaignLoading"
                            @click="resetCampaignFilters"
                        >
                            Đặt lại
                        </button>
                    </form>

                    <p v-if="campaignBanner.error" class="alert error" role="alert">
                        {{ campaignBanner.error }}
                    </p>
                    <p v-if="campaignBanner.success" class="alert success" role="status">
                        {{ campaignBanner.success }}
                    </p>
                    <p v-if="store.campaignLoading" class="state">Đang tải danh sách…</p>

                    <template v-else>
                        <p class="result-count">{{ store.campaignMeta.total }} chiến dịch phù hợp</p>

                        <CampaignListTable
                            v-if="store.campaignRows.length"
                            :rows="store.campaignRows"
                            :saving="store.campaignSaving"
                            @view="openCampaignDetail"
                            @edit="openEditCampaign"
                            @delete="onDeleteCampaign"
                            @toggle="onToggleCampaign"
                        />
                        <p v-else class="state empty">
                            <strong>Chưa có chiến dịch nào.</strong>
                            <small>Bấm "Thêm chiến dịch" ở góc trên để tạo chiến dịch đầu tiên.</small>
                        </p>

                        <div v-if="store.campaignMeta.last_page > 1" class="pagination">
                            <button
                                class="btn ghost"
                                :disabled="store.campaignMeta.current_page <= 1"
                                @click="setCampaignPage(store.campaignMeta.current_page - 1)"
                            >
                                Trước
                            </button>
                            <span>
                                Trang {{ store.campaignMeta.current_page }} /
                                {{ store.campaignMeta.last_page }}
                            </span>
                            <button
                                class="btn ghost"
                                :disabled="store.campaignMeta.current_page >= store.campaignMeta.last_page"
                                @click="setCampaignPage(store.campaignMeta.current_page + 1)"
                            >
                                Sau
                            </button>
                        </div>
                    </template>
                </section>
            </div>

            <CampaignFormModal
                ref="campaignFormRef"
                :campaign="editingCampaign"
                @save="onSaveCampaign"
                @cancel="onCancelEditCampaign"
            />

            <CampaignDetailDrawer
                :open="campaignDrawerOpen"
                :campaign="campaignDrawer"
                :stats="store.campaignStats"
                :loading="store.campaignDetailLoading"
                :error="store.campaignDetailError"
                @close="closeCampaignDetail"
            />
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { useManagerPromotionsStore } from '@/stores/managerPromotions'
import type { CampaignPayload, ManagerCampaign, ManagerCampaignDetail } from '@/types/campaign'
import type { ManagerPromotion, PromotionPayload } from '@/types/promotion'
import type { ManagerVoucher, VoucherPayload } from '@/api/managerVoucher'
import VoucherFormModal from '@/components/manager/promotions/VoucherFormModal.vue'
import VoucherUsagesDrawer from '@/components/manager/promotions/VoucherUsagesDrawer.vue'
import VoucherPreviewPanel from '@/components/manager/promotions/VoucherPreviewPanel.vue'
import PromotionFormModal from '@/components/manager/promotions/PromotionFormModal.vue'
import PromotionListTable from '@/components/manager/promotions/PromotionListTable.vue'
import PromotionPreviewPanel from '@/components/manager/promotions/PromotionPreviewPanel.vue'
import PromotionUsagesDrawer from '@/components/manager/promotions/PromotionUsagesDrawer.vue'
import CampaignFormModal from '@/components/manager/promotions/CampaignFormModal.vue'
import CampaignListTable from '@/components/manager/promotions/CampaignListTable.vue'
import CampaignDetailDrawer from '@/components/manager/promotions/CampaignDetailDrawer.vue'

type Tab = 'voucher' | 'promotion' | 'campaign'
const activeTab = ref<Tab>('voucher')

const store = useManagerPromotionsStore()

/* ============================================================
 *  VOUCHER state (giữ nguyên logic Phase 3)
 * ============================================================ */

const voucherFilters = reactive({
    is_active: '' as '' | 'active' | 'inactive',
    type: '' as '' | 'PERCENT' | 'FIXED',
    from: '',
    to: '',
})
const voucherSearch = ref('')

watch(
    [() => voucherFilters.is_active, () => voucherFilters.type, () => voucherFilters.from, () => voucherFilters.to],
    () => {
        store.filters = { ...store.filters, ...voucherFilters }
        store.meta = { ...store.meta, current_page: 1 }
        void store.loadList()
    },
)
watch(voucherSearch, (v) => {
    store.filters.search = v
    store.meta = { ...store.meta, current_page: 1 }
    void store.loadList()
})

function onSearchVoucher(): void {
    store.filters.search = voucherSearch.value
    void store.loadList()
}
function resetVoucherFilters(): void {
    voucherSearch.value = ''
    voucherFilters.is_active = ''
    voucherFilters.type = ''
    voucherFilters.from = ''
    voucherFilters.to = ''
    store.resetFilters()
}
function setVoucherPage(p: number): void {
    store.setPage(p)
}

const voucherFormRef = ref<InstanceType<typeof VoucherFormModal> | null>(null)
const editingVoucher = ref<ManagerVoucher | null>(null)
const voucherBanner = reactive({ error: '', success: '' })

function openCreateVoucher(): void {
    editingVoucher.value = null
    voucherFormRef.value?.open()
}
function openEditVoucher(v: ManagerVoucher): void {
    editingVoucher.value = v
    voucherFormRef.value?.open()
}
function onCancelEditVoucher(): void {
    editingVoucher.value = null
}

async function onSaveVoucher(payload: VoucherPayload & { id?: number }): Promise<void> {
    try {
        if (payload.id) {
            await store.update(payload.id, payload)
            voucherBanner.success = 'Đã cập nhật mã ' + payload.code
        } else {
            await store.create(payload)
            voucherBanner.success = 'Đã tạo mã ' + payload.code
        }
        setTimeout(() => (voucherBanner.success = ''), 4000)
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } }
        voucherBanner.error = err.response?.data?.message || 'Lưu thất bại. Vui lòng thử lại.'
    }
}

async function onToggleVoucher(v: ManagerVoucher): Promise<void> {
    try {
        await store.toggle(v.id)
        voucherBanner.success = v.is_active ? `Đã tạm ngưng mã ${v.code}` : `Đã kích hoạt mã ${v.code}`
        setTimeout(() => (voucherBanner.success = ''), 3000)
    } catch {
        voucherBanner.error = 'Không thể đổi trạng thái. Vui lòng thử lại.'
    }
}

async function onDeleteVoucher(v: ManagerVoucher): Promise<void> {
    if (!confirm(`Xoá mã ${v.code}? Mã đã có lượt dùng sẽ chỉ tạm ngưng thay vì xoá cứng.`)) return
    try {
        const msg = await store.remove(v.id)
        voucherBanner.success = msg
        setTimeout(() => (voucherBanner.success = ''), 4000)
    } catch {
        voucherBanner.error = 'Xoá thất bại. Vui lòng thử lại.'
    }
}

const voucherDrawerOpen = ref(false)
const voucherDrawer = ref<ManagerVoucher | null>(null)
const voucherUsagesPage = ref(1)
const voucherUsages = computed(() =>
    voucherDrawer.value ? store.usagesByVoucher[voucherDrawer.value.id] ?? [] : [],
)
const voucherUsagesMeta = computed(() =>
    voucherDrawer.value ? store.usagesMeta[voucherDrawer.value.id] ?? null : null,
)

async function openVoucherUsages(v: ManagerVoucher): Promise<void> {
    voucherDrawer.value = v
    voucherDrawerOpen.value = true
    voucherUsagesPage.value = 1
    await store.loadUsages(v.id, 1)
}
function closeVoucherUsages(): void {
    voucherDrawerOpen.value = false
    setTimeout(() => {
        voucherDrawer.value = null
    }, 250)
}
async function onVoucherDrawerPage(p: number): Promise<void> {
    if (!voucherDrawer.value) return
    voucherUsagesPage.value = p
    await store.loadUsages(voucherDrawer.value.id, p)
}

const voucherPreviewRef = ref<InstanceType<typeof VoucherPreviewPanel> | null>(null)
const lastVoucherCode = ref('')

async function onTestVoucher(payload: { code: string; subtotal: number }): Promise<void> {
    lastVoucherCode.value = payload.code
    try {
        const r = await store.runPreview(payload.code, payload.subtotal)
        voucherPreviewRef.value?.setResult(r)
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } }
        voucherPreviewRef.value?.setError(err.response?.data?.message || 'Mã không hợp lệ hoặc không đủ điều kiện.')
    } finally {
        voucherPreviewRef.value?.setLoading(false)
    }
}

/* ============================================================
 *  PROMOTION state (Phase 4)
 * ============================================================ */

const promoFilters = reactive({
    is_active: '' as '' | 'active' | 'inactive',
    kind: '' as '' | 'PERCENT_OFF' | 'FIXED_OFF' | 'BXGY' | 'COMBO',
    campaign_id: '' as '' | number,
    from: '',
    to: '',
})
const promoSearch = ref('')

watch(
    [
        () => promoFilters.is_active,
        () => promoFilters.kind,
        () => promoFilters.campaign_id,
        () => promoFilters.from,
        () => promoFilters.to,
    ],
    () => {
        store.promoFilters = { ...store.promoFilters, ...promoFilters }
        store.promoMeta = { ...store.promoMeta, current_page: 1 }
        void store.loadPromotions()
    },
)
watch(promoSearch, (v) => {
    store.promoFilters.search = v
    store.promoMeta = { ...store.promoMeta, current_page: 1 }
    void store.loadPromotions()
})

function onSearchPromotion(): void {
    store.promoFilters.search = promoSearch.value
    void store.loadPromotions()
}
function resetPromoFilters(): void {
    promoSearch.value = ''
    promoFilters.is_active = ''
    promoFilters.kind = ''
    promoFilters.campaign_id = ''
    promoFilters.from = ''
    promoFilters.to = ''
    store.resetPromoFilters()
}
function setPromoPage(p: number): void {
    store.setPromoPage(p)
}

const promoFormRef = ref<InstanceType<typeof PromotionFormModal> | null>(null)
const editingPromotion = ref<ManagerPromotion | null>(null)
const editingPromotionPayload = computed<PromotionPayload | null>(() => {
    const p = editingPromotion.value
    if (!p) return null
    return {
        name: p.name,
        description: p.description,
        campaign_id: p.campaign_id,
        slug: p.slug,
        kind: p.kind,
        stacking_mode: p.stacking_mode,
        priority: p.priority,
        config: p.config,
        conditions: p.conditions,
        usage_limit: p.usage_limit,
        usage_limit_per_user: p.usage_limit_per_user,
        starts_at: p.starts_at,
        ends_at: p.ends_at,
        is_active: p.is_active,
        items: p.items,
    }
})
const promoBanner = reactive({ error: '', success: '' })

function openCreatePromotion(): void {
    editingPromotion.value = null
    promoFormRef.value?.open()
}
async function openEditPromotion(p: ManagerPromotion): Promise<void> {
    try {
        const full = await store.fetchPromotion(p.id)
        editingPromotion.value = full
        promoFormRef.value?.open()
    } catch {
        promoBanner.error = 'Không tải được chi tiết chương trình.'
    }
}
function onCancelEditPromotion(): void {
    editingPromotion.value = null
}

async function onSavePromotion(payload: PromotionPayload & { id?: number }): Promise<void> {
    try {
        if (payload.id) {
            await store.updatePromotion(payload.id, payload)
            promoBanner.success = 'Đã cập nhật chương trình.'
        } else {
            await store.createPromotion(payload)
            promoBanner.success = 'Đã tạo chương trình.'
        }
        editingPromotion.value = null
        setTimeout(() => (promoBanner.success = ''), 4000)
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } }
        promoBanner.error = err.response?.data?.message || 'Lưu thất bại. Vui lòng thử lại.'
    }
}

async function onTogglePromotion(p: ManagerPromotion): Promise<void> {
    try {
        await store.togglePromotion(p.id)
        promoBanner.success = p.is_active
            ? `Đã tạm ngưng chương trình.`
            : `Đã kích hoạt chương trình.`
        setTimeout(() => (promoBanner.success = ''), 3000)
    } catch {
        promoBanner.error = 'Không thể đổi trạng thái. Vui lòng thử lại.'
    }
}

async function onDeletePromotion(p: ManagerPromotion): Promise<void> {
    if (!confirm(`Xoá chương trình "${p.name}"? Đã có lượt dùng sẽ chỉ tạm ngưng.`)) return
    try {
        const msg = await store.removePromotion(p.id)
        promoBanner.success = msg
        setTimeout(() => (promoBanner.success = ''), 4000)
    } catch {
        promoBanner.error = 'Xoá thất bại. Vui lòng thử lại.'
    }
}

const promoDrawerOpen = ref(false)
const promoDrawer = ref<ManagerPromotion | null>(null)
const promoUsagesPage = ref(1)
const promoUsages = computed(() =>
    promoDrawer.value ? store.promoUsagesByPromotion[promoDrawer.value.id] ?? [] : [],
)
const promoUsagesMeta = computed(() =>
    promoDrawer.value ? store.promoUsagesMeta[promoDrawer.value.id] ?? null : null,
)

async function openPromoUsages(p: ManagerPromotion): Promise<void> {
    promoDrawer.value = p
    promoDrawerOpen.value = true
    promoUsagesPage.value = 1
    await store.loadPromotionUsages(p.id, 1)
}
function closePromoUsages(): void {
    promoDrawerOpen.value = false
    setTimeout(() => {
        promoDrawer.value = null
    }, 250)
}
async function onPromoDrawerPage(p: number): Promise<void> {
    if (!promoDrawer.value) return
    promoUsagesPage.value = p
    await store.loadPromotionUsages(promoDrawer.value.id, p)
}

const promoStats = computed(() => {
    let active = 0
    let outOfQuota = 0
    for (const r of store.promoRows) {
        if (r.is_active) active++
        if (!r.has_global_quota) outOfQuota++
    }
    return {
        active,
        inactive: store.promoMeta.total - active,
        outOfQuota,
    }
})

/* ============================================================
 *  CAMPAIGN state (Phase 5)
 * ============================================================ */

const campaignFilters = reactive({ from: '', to: '' })
const campaignSearch = ref('')

watch([() => campaignFilters.from, () => campaignFilters.to], () => {
    store.campaignFilters = { ...store.campaignFilters, ...campaignFilters }
    store.campaignMeta = { ...store.campaignMeta, current_page: 1 }
    void store.loadCampaigns()
})
watch(campaignSearch, (v) => {
    store.campaignFilters.search = v
    store.campaignMeta = { ...store.campaignMeta, current_page: 1 }
    void store.loadCampaigns()
})

function onSearchCampaign(): void {
    store.campaignFilters.search = campaignSearch.value
    void store.loadCampaigns()
}
function resetCampaignFilters(): void {
    campaignSearch.value = ''
    campaignFilters.from = ''
    campaignFilters.to = ''
    store.campaignFilters = { search: '', from: '', to: '' }
    store.campaignMeta = { ...store.campaignMeta, current_page: 1 }
    void store.loadCampaigns()
}
function setCampaignPage(p: number): void {
    store.setCampaignPage(p)
}

const campaignFormRef = ref<InstanceType<typeof CampaignFormModal> | null>(null)
const editingCampaign = ref<ManagerCampaignDetail | null>(null)
const campaignBanner = reactive({ error: '', success: '' })

function openCreateCampaign(): void {
    editingCampaign.value = null
    campaignFormRef.value?.open()
}
async function openEditCampaign(c: ManagerCampaign): Promise<void> {
    try {
        const full = await store.fetchCampaignDetail(c.id)
        editingCampaign.value = full
        campaignFormRef.value?.open()
    } catch {
        campaignBanner.error = 'Không tải được chi tiết chiến dịch.'
    }
}
function onCancelEditCampaign(): void {
    editingCampaign.value = null
}

async function onSaveCampaign(payload: CampaignPayload & { id?: number }): Promise<void> {
    try {
        if (payload.id) {
            await store.updateCampaign(payload.id, payload)
            campaignBanner.success = 'Đã cập nhật chiến dịch.'
        } else {
            await store.createCampaign(payload)
            campaignBanner.success = 'Đã tạo chiến dịch.'
            // Refresh dropdown cache cho CampaignPicker.
            void store.loadCampaignOptions(true)
        }
        editingCampaign.value = null
        setTimeout(() => (campaignBanner.success = ''), 4000)
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } }
        campaignBanner.error = err.response?.data?.message || 'Lưu thất bại. Vui lòng thử lại.'
    }
}

async function onToggleCampaign(c: ManagerCampaign): Promise<void> {
    try {
        await store.toggleCampaign(c.id)
        campaignBanner.success = c.is_active ? 'Đã tạm ngưng chiến dịch.' : 'Đã kích hoạt chiến dịch.'
        setTimeout(() => (campaignBanner.success = ''), 3000)
    } catch {
        campaignBanner.error = 'Không thể đổi trạng thái.'
    }
}

async function onDeleteCampaign(c: ManagerCampaign): Promise<void> {
    if (
        !confirm(
            `Xoá chiến dịch "${c.name}"?\nChiến dịch có khuyến mãi đang chạy sẽ không xoá được — vui lòng tắt/chuyển trước.`,
        )
    )
        return
    try {
        const msg = await store.removeCampaign(c.id)
        campaignBanner.success = msg
        setTimeout(() => (campaignBanner.success = ''), 4000)
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } }
        campaignBanner.error = err.response?.data?.message || 'Xoá thất bại.'
    }
}

const campaignDrawerOpen = ref(false)
const campaignDrawer = ref<ManagerCampaignDetail | null>(null)
async function openCampaignDetail(c: ManagerCampaign): Promise<void> {
    campaignDrawer.value = null
    campaignDrawerOpen.value = true
    try {
        const detail = await store.fetchCampaignDetail(c.id)
        campaignDrawer.value = detail
        void store.fetchCampaignStats(c.id)
    } catch {
        campaignBanner.error = 'Không tải được chi tiết chiến dịch.'
        campaignDrawerOpen.value = false
    }
}
function closeCampaignDetail(): void {
    campaignDrawerOpen.value = false
    setTimeout(() => {
        campaignDrawer.value = null
    }, 250)
}

const campaignStats = computed(() => {
    let live = 0
    let paused = 0
    let totalPromotions = 0
    for (const c of store.campaignRows) {
        if (c.is_live) live++
        else if (c.is_active) paused++
        totalPromotions += c.promotions_count
    }
    return { live, paused, totalPromotions }
})

/* ============================================================
 *  Helpers
 * ============================================================ */

function formatMoney(n: number): string {
    return (n || 0).toLocaleString('vi-VN') + 'đ'
}
function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('vi-VN')
}
function voucherUsagePercent(v: ManagerVoucher): number {
    if (!v.usage_limit) return 0
    return Math.min(100, Math.round((v.used_count / v.usage_limit) * 100))
}

onMounted(() => {
    void store.loadList()
    void store.loadPromotions()
    void store.loadCampaigns()
})
useRealtimeRefresh(
    () => {
        void store.loadList(true)
        void store.loadPromotions(true)
        void store.loadCampaigns(true)
    },
    () =>
        !store.loading &&
        !store.saving &&
        !store.promoLoading &&
        !store.promoSaving &&
        !store.campaignLoading &&
        !store.campaignSaving,
)
</script>

<style scoped>
.promotions-page {
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
.page-head h2 {
    margin: 0 0 4px;
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.01em;
}
.page-head p {
    margin: 0;
    color: #6b5f54;
    font-size: 14px;
    max-width: 60ch;
}
.head-cta {
    display: flex;
    gap: 8px;
}

.tabs {
    display: flex;
    gap: 6px;
    border-bottom: 1px solid rgba(26, 14, 6, 0.08);
    padding-bottom: 0;
}
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
.tabs button:hover:not(:disabled) {
    color: var(--chili, #d71920);
}
.tabs button.active {
    color: var(--chili, #d71920);
    border-bottom-color: var(--chili, #d71920);
}
.tabs button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.tabs button small {
    font-weight: 400;
    font-size: 11px;
    margin-left: 4px;
}

.kpi-strip {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
.kpi {
    background: #fff;
    border: 1px solid rgba(26, 14, 6, 0.08);
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.kpi .label {
    font-size: 11px;
    color: #6b5f54;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}
.kpi strong {
    font-size: 24px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.kpi.accent {
    background: linear-gradient(135deg, var(--chili, #d71920), #ff574c);
    color: #fff;
    border-color: transparent;
}
.kpi.accent .label {
    color: rgba(255, 255, 255, 0.85);
}
.kpi.accent strong {
    color: #fff;
}
.kpi.warn {
    background: rgba(245, 158, 11, 0.08);
    border-color: rgba(245, 158, 11, 0.25);
}
.kpi.warn .label {
    color: #b45309;
}
.kpi.warn strong {
    color: #b45309;
}

.layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: 22px;
    align-items: start;
}
.main {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.side {
    position: sticky;
    top: 12px;
}

.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    background: #fff;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid rgba(26, 14, 6, 0.08);
}
.filters input,
.filters select {
    padding: 9px 11px;
    border: 1px solid #d6cdc1;
    border-radius: 8px;
    font: inherit;
    background: #fff;
}
.filters input:focus,
.filters select:focus {
    outline: none;
    border-color: var(--chili, #d71920);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.18);
}
.filters input[placeholder] {
    flex: 1;
    min-width: 200px;
}

.alert {
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
}
.alert.error {
    background: rgba(215, 25, 32, 0.08);
    color: var(--chili, #d71920);
}
.alert.success {
    background: rgba(34, 139, 80, 0.1);
    color: #166534;
}

.state {
    background: #fff;
    border: 1px dashed rgba(26, 14, 6, 0.14);
    border-radius: 12px;
    padding: 18px;
    text-align: center;
    color: #6b5f54;
    font-size: 13px;
}
.state.empty {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.state.empty strong {
    font-size: 14px;
    color: #1a1a1a;
}
.state.empty small {
    font-size: 12px;
}

.result-count {
    margin: 0 0 6px;
    font-size: 12px;
    color: #6b5f54;
}

.table-wrap {
    background: #fff;
    border: 1px solid rgba(26, 14, 6, 0.08);
    border-radius: 12px;
    overflow: auto;
}
table {
    width: 100%;
    min-width: 880px;
    border-collapse: collapse;
    font-size: 13px;
}
th,
td {
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid rgba(26, 14, 6, 0.06);
    vertical-align: middle;
}
th {
    background: rgba(26, 14, 6, 0.03);
    color: #6b5f54;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    font-weight: 700;
}
tbody tr:hover {
    background: rgba(215, 25, 32, 0.03);
}
tbody tr.dim {
    opacity: 0.55;
}
tbody tr:last-child td {
    border-bottom: 0;
}

.cell-primary {
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-width: 280px;
}
.cell-primary .code {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    background: rgba(215, 25, 32, 0.1);
    color: var(--chili, #d71920);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    align-self: flex-start;
}
.cell-primary strong {
    font-size: 14px;
}
.cell-primary small {
    color: #6b5f54;
    font-size: 12px;
}

.type-pill {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 12px;
}
.type-pill.percent {
    background: rgba(217, 119, 6, 0.12);
    color: #b45309;
}
.type-pill.fixed {
    background: rgba(15, 118, 110, 0.12);
    color: #0f766e;
}

.muted {
    color: #6b5f54;
    font-size: 12px;
}
.muted.small {
    font-size: 11px;
}
.warn {
    color: var(--chili, #d71920);
    font-size: 11px;
    font-weight: 600;
}

.quota {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 110px;
}
.quota-bar {
    height: 6px;
    background: rgba(26, 14, 6, 0.08);
    border-radius: 999px;
    overflow: hidden;
}
.quota-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--chili, #d71920), #ff574c);
    border-radius: 999px;
    transition: width 0.2s ease;
}
.quota small {
    color: #6b5f54;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
}

.status-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid transparent;
    background: rgba(26, 14, 6, 0.06);
    color: #6b5f54;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.12s, color 0.12s;
}
.status-toggle .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #94a3b8;
}
.status-toggle.on {
    background: rgba(34, 139, 80, 0.12);
    color: #166534;
}
.status-toggle.on .dot {
    background: #16a34a;
    box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.18);
}
.status-toggle:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.actions-col {
    text-align: right;
    width: 200px;
}
.row-actions {
    display: flex;
    justify-content: flex-end;
    gap: 6px;
}

.btn {
    cursor: pointer;
    padding: 9px 16px;
    border-radius: 10px;
    font: inherit;
    font-weight: 600;
    border: 1px solid transparent;
    transition: background 0.12s, transform 0.06s;
}
.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn.primary {
    background: var(--chili, #d71920);
    color: #fff;
}
.btn.primary:not(:disabled):hover {
    background: #b5131a;
}
.btn.ghost {
    background: transparent;
    border-color: rgba(26, 14, 6, 0.12);
    color: #2a221c;
}
.btn.ghost:hover {
    background: rgba(26, 14, 6, 0.05);
}
.btn.ghost.danger {
    color: var(--chili, #d71920);
    border-color: rgba(215, 25, 32, 0.25);
}
.btn.ghost.danger:hover {
    background: rgba(215, 25, 32, 0.08);
}
.btn.xs {
    padding: 6px 10px;
    font-size: 12px;
}

.pagination {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    padding: 4px 0;
    font-size: 12px;
}

@media (max-width: 1024px) {
    .layout {
        grid-template-columns: 1fr;
    }
    .side {
        position: static;
    }
    .kpi-strip {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 600px) {
    .kpi-strip {
        grid-template-columns: 1fr 1fr;
    }
    .actions-col {
        width: auto;
    }
    .row-actions {
        flex-wrap: wrap;
    }
}
</style>
