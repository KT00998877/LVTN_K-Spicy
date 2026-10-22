/**
 * Marketing Studio store — Pinia cho trang AI Marketing Studio (Phase 6.1).
 *
 * Quản lý:
 *  - context (brand/catalogs/tones) — load 1 lần khi mở trang
 *  - asset list (rows + meta + filter + loading)
 *  - asset detail hiện tại (versions)
 *  - mutation actions (create / generate / selectVersion / archive)
 *
 * Realtime safety: list dùng beginRead() để huỷ filter cũ khi filter đổi nhanh.
 */
import { defineStore } from 'pinia'
import { beginRead } from '@/utils/realtime'
import {
    archiveAsset,
    createAsset,
    fetchDraftContext,
    forceDeleteAsset,
    generateNewVersion,
    getAsset,
    listAssets,
    restoreAsset,
    selectVersion,
    updateVersion,
    type CreateAssetPayload,
    type ListAssetsParams,
    type MarketingAsset,
    type MarketingDraftContext,
    type MarketingStatus,
    type MarketingVersion,
} from '@/api/marketing'

interface MarketingState {
    context: MarketingDraftContext | null
    contextLoading: boolean

    rows: MarketingAsset[]
    meta: { total: number; per_page: number; current_page: number; last_page: number }
    loading: boolean
    error: string | null
    filters: { status: MarketingStatus | ''; channel: string; q: string }

    current: MarketingAsset | null
    currentLoading: boolean

    mutating: boolean
    liveReads: Record<string, unknown>
    listLiveReads: Record<string, unknown>
    detailLiveReads: Record<string, unknown>

    /** Tab hiện tại của UI: 'active' (mặc định) hoặc 'trash' (thùng rác). */
    viewMode: 'active' | 'trash'
}

export const useMarketingStudioStore = defineStore('marketingStudio', {
    state: (): MarketingState => ({
        context: null,
        contextLoading: false,

        rows: [],
        meta: { total: 0, per_page: 20, current_page: 1, last_page: 1 },
        loading: false,
        error: null,
        filters: { status: '', channel: '', q: '' },

        current: null,
        currentLoading: false,

        mutating: false,
        liveReads: {},
        listLiveReads: {},
        detailLiveReads: {},

        viewMode: 'active',
    }),

    getters: {
        contextLoaded: (s) => s.context !== null,
    },

    actions: {
        /* ===== Context ===== */
        async loadContext(force = false) {
            if (this.context && !force) return
            const stop = beginRead(this.liveReads, 'context', true)
            this.contextLoading = true
            try {
                this.context = await fetchDraftContext()
            } finally {
                this.contextLoading = false
                stop()
            }
        },

        /* ===== List ===== */
        async loadAssets(page = 1) {
            const stop = beginRead(this.listLiveReads, 'assets', true)
            this.loading = true
            this.error = null
            try {
                const params: ListAssetsParams = { page, per_page: this.meta.per_page }
                if (this.viewMode === 'trash') {
                    params.include_archived = true
                    params.status = 'ARCHIVED'
                } else {
                    if (this.filters.status) params.status = this.filters.status
                    if (this.filters.channel) params.channel = this.filters.channel as any
                    if (this.filters.q.trim()) params.q = this.filters.q.trim()
                }

                const r = await listAssets(params)
                this.rows = r.data
                this.meta = r.meta
            } catch (e: any) {
                this.error = e?.response?.data?.message || 'Không tải được danh sách asset.'
            } finally {
                this.loading = false
                stop()
            }
        },

        setViewMode(mode: 'active' | 'trash') {
            this.viewMode = mode
            // Reset filter chuyển tab để tránh filter cũ làm rỗng list thùng rác.
            this.filters = { status: '', channel: '', q: '' }
        },

        setFilter(patch: Partial<MarketingState['filters']>) {
            this.filters = { ...this.filters, ...patch }
        },

        /* ===== Detail ===== */
        async loadAsset(id: number) {
            const stop = beginRead(this.detailLiveReads, `detail-${id}`, true)
            this.currentLoading = true
            try {
                this.current = await getAsset(id)
            } finally {
                this.currentLoading = false
                stop()
            }
        },

        /* ===== Mutations ===== */
        async create(payload: CreateAssetPayload): Promise<MarketingAsset> {
            this.mutating = true
            try {
                const created = await createAsset(payload)
                await this.loadAssets(1)
                return created
            } finally {
                this.mutating = false
            }
        },

        async generateVersion(assetId: number): Promise<MarketingVersion> {
            this.mutating = true
            try {
                const version = await generateNewVersion(assetId)
                if (this.current?.id === assetId) {
                    await this.loadAsset(assetId)
                }
                await this.loadAssets(this.meta.current_page)
                return version
            } finally {
                this.mutating = false
            }
        },

        async selectAssetVersion(assetId: number, versionId: number) {
            this.mutating = true
            try {
                await selectVersion(assetId, versionId)
                if (this.current?.id === assetId) {
                    await this.loadAsset(assetId)
                }
                await this.loadAssets(this.meta.current_page)
            } finally {
                this.mutating = false
            }
        },

        async archive(assetId: number) {
            this.mutating = true
            try {
                await archiveAsset(assetId)
                await this.loadAssets(this.meta.current_page)
            } finally {
                this.mutating = false
            }
        },

        async restoreAsset(assetId: number) {
            this.mutating = true
            try {
                await restoreAsset(assetId)
                // Sau restore → asset chuyển DRAFT, đã rời khỏi thùng rác.
                await this.loadAssets(this.meta.current_page)
            } finally {
                this.mutating = false
            }
        },

        async forceDeleteAsset(assetId: number) {
            this.mutating = true
            try {
                await forceDeleteAsset(assetId)
                await this.loadAssets(this.meta.current_page)
            } finally {
                this.mutating = false
            }
        },

        async updateAssetVersion(
            assetId: number,
            versionId: number,
            payload: { headline?: string | null; caption?: string | null; body?: string | null; hashtags?: string[] | null },
        ) {
            this.mutating = true
            try {
                const updated = await updateVersion(assetId, versionId, payload)
                if (this.current?.id === assetId) {
                    this.current = updated
                }
                await this.loadAssets(this.meta.current_page)
                return updated
            } finally {
                this.mutating = false
            }
        },
    },
})