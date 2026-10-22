/**
 * Blog Manager store — Pinia cho tab "Blog" trong AI Marketing Studio (Phase 6.2).
 *
 * Quản lý:
 *  - list (rows + meta + filter + loading)
 *  - mutation actions (create / update / delete / publish / archive)
 */
import { defineStore } from 'pinia'
import { beginRead } from '@/utils/realtime'
import {
    archiveBlog,
    createBlog,
    deleteBlog,
    getBlog,
    listBlogs,
    publishBlog,
    updateBlog,
    type BlogPayload,
    type ListBlogsParams,
    type ManagerBlog,
    type BlogStatus,
    type BlogCategory,
    type BlogSection,
} from '@/api/managerBlog'

interface BlogState {
    rows: ManagerBlog[]
    meta: { total: number; per_page: number; current_page: number; last_page: number }
    loading: boolean
    error: string | null
    filters: { status: BlogStatus | ''; category: BlogCategory | ''; news_section: BlogSection | ''; search: string }

    current: ManagerBlog | null
    currentLoading: boolean

    mutating: boolean
    listLiveReads: Record<string, unknown>
    detailLiveReads: Record<string, unknown>
}

export const useBlogManagerStore = defineStore('blogManager', {
    state: (): BlogState => ({
        rows: [],
        meta: { total: 0, per_page: 20, current_page: 1, last_page: 1 },
        loading: false,
        error: null,
        filters: { status: '', category: '', news_section: '', search: '' },

        current: null,
        currentLoading: false,

        mutating: false,
        listLiveReads: {},
        detailLiveReads: {},
    }),

    actions: {
        async loadBlogs(p = 1): Promise<void> {
            const stop = beginRead(this.listLiveReads, 'blogs', true)
            this.loading = true
            this.error = null
            try {
                const params: ListBlogsParams = {
                    page: p,
                    per_page: this.meta.per_page,
                }
                if (this.filters.status) params.status = this.filters.status
                if (this.filters.category) params.category = this.filters.category
                if (this.filters.news_section) params.news_section = this.filters.news_section
                if (this.filters.search.trim()) params.search = this.filters.search.trim()

                const r = await listBlogs(params)
                this.rows = r.data
                this.meta = r.meta
            } catch (e: any) {
                this.error = e?.response?.data?.message || 'Không tải được danh sách bài viết.'
            } finally {
                this.loading = false
                stop()
            }
        },

        setFilter(patch: Partial<BlogState['filters']>): void {
            this.filters = { ...this.filters, ...patch }
        },

        async loadBlog(id: number): Promise<ManagerBlog> {
            const stop = beginRead(this.detailLiveReads, `blog-${id}`, true)
            this.currentLoading = true
            try {
                this.current = await getBlog(id)
                return this.current
            } finally {
                this.currentLoading = false
                stop()
            }
        },

        async create(payload: BlogPayload): Promise<ManagerBlog> {
            this.mutating = true
            try {
                const created = await createBlog(payload)
                // Refresh list NGẦM (không await) — modal có thể đóng ngay,
                // danh sách sẽ tự cập nhật khi request hoàn tất.
                void this.loadBlogs(1)
                return created
            } finally {
                this.mutating = false
            }
        },

        async update(id: number, payload: BlogPayload): Promise<ManagerBlog> {
            this.mutating = true
            try {
                const updated = await updateBlog(id, payload)
                if (this.current?.id === id) this.current = updated
                // Refresh list ngầm — tránh UI phải đợi 2 request liên tiếp.
                void this.loadBlogs(this.meta.current_page)
                return updated
            } finally {
                this.mutating = false
            }
        },

        async remove(id: number): Promise<void> {
            this.mutating = true
            try {
                await deleteBlog(id)
                void this.loadBlogs(this.meta.current_page)
            } finally {
                this.mutating = false
            }
        },

        async publish(id: number): Promise<ManagerBlog> {
            this.mutating = true
            try {
                const updated = await publishBlog(id)
                if (this.current?.id === id) this.current = updated
                void this.loadBlogs(this.meta.current_page)
                return updated
            } finally {
                this.mutating = false
            }
        },

        async archive(id: number): Promise<ManagerBlog> {
            this.mutating = true
            try {
                const updated = await archiveBlog(id)
                if (this.current?.id === id) this.current = updated
                void this.loadBlogs(this.meta.current_page)
                return updated
            } finally {
                this.mutating = false
            }
        },
    },
})