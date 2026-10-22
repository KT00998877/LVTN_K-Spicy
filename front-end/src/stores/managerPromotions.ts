/**
 * managerPromotionsStore — Pinia store cho trang /staff/manager/promotions.
 *
 * Phase 3: chỉ quản lý Voucher (CRUD + toggle + usages + preview).
 * Phase 4: bổ sung slice Promotion (CRUD + toggle + usages + preview cart mẫu).
 *
 * State pattern:
 *  - 1 list state dùng chung cho table (rows, meta, loading, error, filters)
 *  - usages là state riêng cho drawer (theo voucher đang xem)
 *  - preview là state riêng cho panel test
 *  - Promotion state tách hẳn để tránh đụng filter Voucher (Promotion có filter riêng theo kind/campaign).
 *
 * Realtime safety:
 *  - Dùng beginRead() để huỷ request cũ khi filter đổi nhanh.
 *  - Mọi mutation đều phát markDataWrite() thông qua API client interceptor.
 */
import { defineStore } from 'pinia'
import { beginRead } from '@/utils/realtime'
import {
    createManagerVoucher,
    deleteManagerVoucher,
    getManagerVoucher,
    listManagerVouchers,
    listVoucherUsages,
    previewManagerVoucher,
    toggleManagerVoucher,
    updateManagerVoucher,
    type ListVouchersParams,
    type VoucherPayload,
} from '@/api/managerVoucher'
import {
    createManagerPromotion,
    deleteManagerPromotion,
    getManagerPromotion,
    listManagerPromotions,
    listPromotionUsages,
    previewManagerPromotion,
    toggleManagerPromotion,
    updateManagerPromotion,
    type ListPromotionsParams,
    type PreviewPayload as PreviewPromotionPayload,
} from '@/api/managerPromotion'
import {
    createManagerCampaign,
    deleteManagerCampaign,
    getManagerCampaign,
    getManagerCampaignStats,
    listManagerCampaigns,
    toggleManagerCampaign,
    updateManagerCampaign,
    type ListCampaignsParams,
} from '@/api/campaign'
import type {
    CampaignPayload,
    CampaignStats,
    ManagerCampaign,
    ManagerCampaignDetail,
} from '@/types/campaign'
import type {
    ManagerPromotion,
    ManagerPromotionUsage,
    PromotionKind,
    PromotionPayload,
    PromotionPreviewResult,
} from '@/types/promotion'
import type {
    ManagerVoucher,
    ManagerVoucherUsage,
    VoucherPreview,
} from '@/types/voucher'

type FilterState = {
    search: string
    is_active: '' | 'active' | 'inactive'
    type: '' | 'PERCENT' | 'FIXED'
    from: string
    to: string
}

const blankFilters = (): FilterState => ({
    search: '',
    is_active: '',
    type: '',
    from: '',
    to: '',
})

/* ===== Promotion filter (Phase 4) ===== */
type PromotionFilterState = {
    search: string
    is_active: '' | 'active' | 'inactive'
    kind: '' | PromotionKind
    campaign_id: '' | number
    from: string
    to: string
}

const blankPromotionFilters = (): PromotionFilterState => ({
    search: '',
    is_active: '',
    kind: '',
    campaign_id: '',
    from: '',
    to: '',
})

export const useManagerPromotionsStore = defineStore('managerPromotions', {
    state: () => ({
        /* ===== Voucher list ===== */
        rows: [] as ManagerVoucher[],
        meta: { total: 0, per_page: 20, current_page: 1, last_page: 1 },
        loading: false,
        error: '',
        saving: false,
        filters: blankFilters(),
        liveReads: {} as Record<string, unknown>,

        /* ===== Usages drawer ===== */
        usagesByVoucher: {} as Record<number, ManagerVoucherUsage[]>,
        usagesMeta: {} as Record<number, { total: number; per_page: number; current_page: number; last_page: number }>,
        loadingUsages: false,
        usagesError: '',

        /* ===== Preview panel ===== */
        preview: null as VoucherPreview | null,
        loadingPreview: false,
        previewError: '',

        /* ===== Promotion list (Phase 4) ===== */
        promoRows: [] as ManagerPromotion[],
        promoMeta: { total: 0, per_page: 20, current_page: 1, last_page: 1 },
        promoLoading: false,
        promoError: '',
        promoSaving: false,
        promoFilters: blankPromotionFilters(),
        promoLiveReads: {} as Record<string, unknown>,

        /* ===== Promotion usages drawer ===== */
        promoUsagesByPromotion: {} as Record<number, ManagerPromotionUsage[]>,
        promoUsagesMeta: {} as Record<number, { total: number; per_page: number; current_page: number; last_page: number }>,
        promoLoadingUsages: false,
        promoUsagesError: '',

        /* ===== Promotion preview (cart sample) ===== */
        promoPreview: null as PromotionPreviewResult | null,
        promoLoadingPreview: false,
        promoPreviewError: '',

        /* ===== Campaign list (Phase 5) ===== */
        campaignRows: [] as ManagerCampaign[],
        campaignMeta: { total: 0, per_page: 20, current_page: 1, last_page: 1 },
        campaignLoading: false,
        campaignError: '',
        campaignSaving: false,
        campaignFilters: { search: '', from: '', to: '' },
        campaignLiveReads: {} as Record<string, unknown>,

        /* ===== Campaign detail (đang xem) ===== */
        campaignDetail: null as ManagerCampaignDetail | null,
        campaignStats: null as CampaignStats | null,
        campaignDetailLoading: false,
        campaignDetailError: '',

        /* ===== Campaign dropdown cache (cho form Promotion — Phase 5) ===== */
        campaignOptions: [] as { id: number; name: string; is_active: boolean }[],
        campaignOptionsLoading: false,
    }),

    getters: {
        /** Tổng quan nhanh cho summary header. */
        stats(state): { total: number; active: number; inactive: number; expiringSoon: number } {
            const today = new Date()
            const in7days = new Date(today.getTime() + 7 * 24 * 3600 * 1000)
            let active = 0, expiringSoon = 0
            for (const r of state.rows) {
                if (r.is_active) active++
                if (r.is_active && r.ends_at && new Date(r.ends_at) <= in7days && new Date(r.ends_at) >= today) {
                    expiringSoon++
                }
            }
            return {
                total: state.meta.total,
                active,
                inactive: state.meta.total - active,
                expiringSoon,
            }
        },
    },

    actions: {
        /* ============================================================
         *  List
         * ============================================================ */

        async loadList(quiet = false): Promise<void> {
            const live = beginRead(this.liveReads, 'list', quiet)
            if (!quiet) {
                this.loading = true
                this.error = ''
            }
            try {
                const params: ListVouchersParams = {
                    per_page: this.meta.per_page,
                    page: this.meta.current_page,
                }
                if (this.filters.search.trim()) params.search = this.filters.search.trim()
                if (this.filters.is_active) params.is_active = this.filters.is_active === 'active'
                if (this.filters.type) params.type = this.filters.type
                if (this.filters.from) params.from = this.filters.from
                if (this.filters.to) params.to = this.filters.to

                const res = await listManagerVouchers(params)
                if (!live()) return
                this.rows = res.data
                this.meta = res.meta
            } catch (e) {
                if (quiet) throw e
                this.error = 'Không tải được danh sách. Vui lòng bấm Làm mới.'
            } finally {
                if (!quiet) this.loading = false
            }
        },

        setPage(page: number): void {
            this.meta = { ...this.meta, current_page: page }
            void this.loadList()
        },

        resetFilters(): void {
            this.filters = blankFilters()
            this.meta = { ...this.meta, current_page: 1 }
            void this.loadList()
        },

        /* ============================================================
         *  CRUD
         * ============================================================ */

        async fetchOne(id: number): Promise<ManagerVoucher> {
            return getManagerVoucher(id)
        },

        async create(payload: VoucherPayload): Promise<ManagerVoucher> {
            this.saving = true
            try {
                const v = await createManagerVoucher(payload)
                await this.loadList(true)
                return v
            } finally {
                this.saving = false
            }
        },

        async update(id: number, payload: VoucherPayload): Promise<ManagerVoucher> {
            this.saving = true
            try {
                const v = await updateManagerVoucher(id, payload)
                // Update trực tiếp trong rows nếu có (UX: không flicker)
                const idx = this.rows.findIndex((r) => r.id === id)
                if (idx !== -1) this.rows[idx] = v
                return v
            } finally {
                this.saving = false
            }
        },

        async remove(id: number): Promise<string> {
            this.saving = true
            try {
                const res = await deleteManagerVoucher(id)
                await this.loadList(true)
                return res.message
            } finally {
                this.saving = false
            }
        },

        async toggle(id: number): Promise<ManagerVoucher> {
            const v = await toggleManagerVoucher(id)
            const idx = this.rows.findIndex((r) => r.id === id)
            if (idx !== -1) this.rows[idx] = v
            return v
        },

        /* ============================================================
         *  Usages (drawer)
         * ============================================================ */

        async loadUsages(voucherId: number, page = 1): Promise<void> {
            this.loadingUsages = true
            this.usagesError = ''
            try {
                const res = await listVoucherUsages(voucherId, page, 20)
                this.usagesByVoucher[voucherId] = res.data
                this.usagesMeta[voucherId] = res.meta
            } catch {
                this.usagesError = 'Không tải được lịch sử sử dụng.'
            } finally {
                this.loadingUsages = false
            }
        },

        /* ============================================================
         *  Preview (panel)
         * ============================================================ */

        async runPreview(code: string, subtotal: number): Promise<void> {
            if (!code.trim()) {
                this.previewError = 'Nhập mã trước.'
                this.preview = null
                return
            }
            if (subtotal <= 0) {
                this.previewError = 'Nhập tạm tính đơn hàng > 0.'
                this.preview = null
                return
            }
            this.loadingPreview = true
            this.previewError = ''
            try {
                this.preview = await previewManagerVoucher(code.trim(), subtotal)
            } catch (e: unknown) {
                const err = e as { response?: { data?: { message?: string } } }
                this.previewError = err.response?.data?.message || 'Mã không hợp lệ hoặc không đủ điều kiện.'
                this.preview = null
            } finally {
                this.loadingPreview = false
            }
        },

        /* ============================================================
         *  Promotion — list / CRUD / usages / preview (Phase 4)
         * ============================================================ */

        async loadPromotions(quiet = false): Promise<void> {
            const live = beginRead(this.promoLiveReads, 'list', quiet)
            if (!quiet) {
                this.promoLoading = true
                this.promoError = ''
            }
            try {
                const params: ListPromotionsParams = {
                    per_page: this.promoMeta.per_page,
                    page: this.promoMeta.current_page,
                }
                if (this.promoFilters.search.trim()) params.search = this.promoFilters.search.trim()
                if (this.promoFilters.is_active) params.is_active = this.promoFilters.is_active === 'active'
                if (this.promoFilters.kind) params.kind = this.promoFilters.kind
                if (this.promoFilters.campaign_id) params.campaign_id = this.promoFilters.campaign_id
                if (this.promoFilters.from) params.from = this.promoFilters.from
                if (this.promoFilters.to) params.to = this.promoFilters.to

                const res = await listManagerPromotions(params)
                if (!live()) return
                this.promoRows = res.data
                this.promoMeta = res.meta
            } catch {
                if (!quiet) this.promoError = 'Không tải được danh sách khuyến mãi. Vui lòng bấm Làm mới.'
            } finally {
                if (!quiet) this.promoLoading = false
            }
        },

        setPromoPage(page: number): void {
            this.promoMeta = { ...this.promoMeta, current_page: page }
            void this.loadPromotions()
        },

        resetPromoFilters(): void {
            this.promoFilters = blankPromotionFilters()
            this.promoMeta = { ...this.promoMeta, current_page: 1 }
            void this.loadPromotions()
        },

        async fetchPromotion(id: number): Promise<ManagerPromotion> {
            return getManagerPromotion(id)
        },

        async createPromotion(payload: PromotionPayload): Promise<ManagerPromotion> {
            this.promoSaving = true
            try {
                const p = await createManagerPromotion(payload)
                await this.loadPromotions(true)
                return p
            } finally {
                this.promoSaving = false
            }
        },

        async updatePromotion(id: number, payload: PromotionPayload): Promise<ManagerPromotion> {
            this.promoSaving = true
            try {
                const p = await updateManagerPromotion(id, payload)
                const idx = this.promoRows.findIndex((r) => r.id === id)
                if (idx !== -1) this.promoRows[idx] = p
                return p
            } finally {
                this.promoSaving = false
            }
        },

        async removePromotion(id: number): Promise<string> {
            this.promoSaving = true
            try {
                const res = await deleteManagerPromotion(id)
                await this.loadPromotions(true)
                return res.message
            } finally {
                this.promoSaving = false
            }
        },

        async togglePromotion(id: number): Promise<ManagerPromotion> {
            const p = await toggleManagerPromotion(id)
            const idx = this.promoRows.findIndex((r) => r.id === id)
            if (idx !== -1) this.promoRows[idx] = p
            return p
        },

        async loadPromotionUsages(promotionId: number, page = 1): Promise<void> {
            this.promoLoadingUsages = true
            this.promoUsagesError = ''
            try {
                const res = await listPromotionUsages(promotionId, page, 20)
                this.promoUsagesByPromotion[promotionId] = res.data
                this.promoUsagesMeta[promotionId] = res.meta
            } catch {
                this.promoUsagesError = 'Không tải được lịch sử sử dụng.'
            } finally {
                this.promoLoadingUsages = false
            }
        },

        async runPromotionPreview(payload: PreviewPromotionPayload): Promise<void> {
            if (payload.items.length === 0) {
                this.promoPreviewError = 'Thêm ít nhất 1 món vào cart mẫu.'
                this.promoPreview = null
                return
            }
            if (payload.subtotal <= 0) {
                this.promoPreviewError = 'Tạm tính đơn hàng phải > 0.'
                this.promoPreview = null
                return
            }
            this.promoLoadingPreview = true
            this.promoPreviewError = ''
            try {
                this.promoPreview = await previewManagerPromotion(payload)
            } catch (e: unknown) {
                const err = e as { response?: { data?: { message?: string } } }
                this.promoPreviewError =
                    err.response?.data?.message || 'Không tính được khuyến mãi cho cart mẫu.'
                this.promoPreview = null
            } finally {
                this.promoLoadingPreview = false
            }
        },

        /* ============================================================
         *  Campaign — list / CRUD / stats (Phase 5)
         * ============================================================ */

        async loadCampaigns(quiet = false): Promise<void> {
            const live = beginRead(this.campaignLiveReads, 'list', quiet)
            if (!quiet) {
                this.campaignLoading = true
                this.campaignError = ''
            }
            try {
                const params: ListCampaignsParams = {
                    per_page: this.campaignMeta.per_page,
                    page: this.campaignMeta.current_page,
                }
                if (this.campaignFilters.search.trim()) params.search = this.campaignFilters.search.trim()
                if (this.campaignFilters.from) params.from = this.campaignFilters.from
                if (this.campaignFilters.to) params.to = this.campaignFilters.to

                const res = await listManagerCampaigns(params)
                if (!live()) return
                this.campaignRows = res.data
                this.campaignMeta = res.meta
            } catch {
                if (!quiet) this.campaignError = 'Không tải được danh sách chiến dịch.'
            } finally {
                if (!quiet) this.campaignLoading = false
            }
        },

        setCampaignPage(page: number): void {
            this.campaignMeta = { ...this.campaignMeta, current_page: page }
            void this.loadCampaigns()
        },

        async fetchCampaignDetail(id: number): Promise<ManagerCampaignDetail> {
            this.campaignDetailLoading = true
            this.campaignDetailError = ''
            try {
                const d = await getManagerCampaign(id)
                this.campaignDetail = d
                return d
            } catch {
                this.campaignDetailError = 'Không tải được chi tiết chiến dịch.'
                throw new Error('not_found')
            } finally {
                this.campaignDetailLoading = false
            }
        },

        async fetchCampaignStats(id: number): Promise<CampaignStats> {
            const s = await getManagerCampaignStats(id)
            this.campaignStats = s
            return s
        },

        async createCampaign(payload: CampaignPayload): Promise<ManagerCampaignDetail> {
            this.campaignSaving = true
            try {
                const c = await createManagerCampaign(payload)
                await this.loadCampaigns(true)
                return c
            } finally {
                this.campaignSaving = false
            }
        },

        async updateCampaign(id: number, payload: CampaignPayload): Promise<ManagerCampaignDetail> {
            this.campaignSaving = true
            try {
                const c = await updateManagerCampaign(id, payload)
                const idx = this.campaignRows.findIndex((r) => r.id === id)
                if (idx !== -1) {
                    this.campaignRows[idx] = { ...this.campaignRows[idx], ...c }
                }
                if (this.campaignDetail?.id === id) {
                    this.campaignDetail = c
                }
                return c
            } finally {
                this.campaignSaving = false
            }
        },

        async removeCampaign(id: number): Promise<string> {
            this.campaignSaving = true
            try {
                const res = await deleteManagerCampaign(id)
                await this.loadCampaigns(true)
                return res.message
            } finally {
                this.campaignSaving = false
            }
        },

        async toggleCampaign(id: number): Promise<ManagerCampaign> {
            const c = await toggleManagerCampaign(id)
            const idx = this.campaignRows.findIndex((r) => r.id === id)
            if (idx !== -1) this.campaignRows[idx] = c
            return c
        },

        /**
         * Cache campaign options cho dropdown ở form Promotion.
         * Gọi lazy 1 lần, cache lại — không block UI.
         */
        async loadCampaignOptions(force = false): Promise<void> {
            if (!force && this.campaignOptions.length > 0) return
            this.campaignOptionsLoading = true
            try {
                const res = await listManagerCampaigns({ all: true, per_page: 100 })
                this.campaignOptions = res.data.map((c) => ({
                    id: c.id,
                    name: c.name,
                    is_active: c.is_active,
                }))
            } catch {
                this.campaignOptions = []
            } finally {
                this.campaignOptionsLoading = false
            }
        },
    },
})