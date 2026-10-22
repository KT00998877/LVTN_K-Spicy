/**
 * Customer-facing composable cho campaigns.
 * Phase 5 — dùng cho 2 trang public + banner trang chủ.
 */
import { defineStore } from 'pinia'
import { listPublicCampaigns, getPublicCampaign, type ListPublicCampaignsParams } from '@/api/campaign'
import type { PublicCampaign, PublicCampaignDetail } from '@/types/campaign'
import type { Paginated } from '@/types/voucher'

export const usePublicCampaignsStore = defineStore('publicCampaigns', {
    state: () => ({
        rows: [] as PublicCampaign[],
        meta: { total: 0, per_page: 12, current_page: 1, last_page: 1 },
        loading: false,
        error: '',

        detail: null as PublicCampaignDetail | null,
        detailLoading: false,
        detailError: '',
    }),

    actions: {
        async loadList(params: ListPublicCampaignsParams = {}): Promise<void> {
            this.loading = true
            this.error = ''
            try {
                const res: Paginated<PublicCampaign> = await listPublicCampaigns({
                    active: true,
                    per_page: 12,
                    page: 1,
                    ...params,
                })
                this.rows = res.data
                this.meta = res.meta
            } catch {
                this.error = 'Không tải được danh sách chiến dịch.'
            } finally {
                this.loading = false
            }
        },

        async loadDetail(slug: string): Promise<PublicCampaignDetail | null> {
            this.detailLoading = true
            this.detailError = ''
            this.detail = null
            try {
                const d = await getPublicCampaign(slug)
                this.detail = d
                return d
            } catch {
                this.detailError = 'Chiến dịch không tồn tại hoặc đã kết thúc.'
                return null
            } finally {
                this.detailLoading = false
            }
        },
    },
})
