<template>
    <div class="marketing-page">
        <header class="page-head">
            <div>
                <span class="eyebrow">AI MARKETING STUDIO</span>
                <h2>Sinh &amp; duyệt content cho K-Spicy</h2>
                <p>AI tự động viết caption, headline, hashtag theo tone — bạn duyệt rồi đăng.</p>
            </div>
            <div class="head-cta">
                <button
                    v-if="store.viewMode === 'active' && activeSection !== 'blog'"
                    class="btn primary"
                    :disabled="store.mutating"
                    @click="openCreate"
                >
                    + Tạo asset mới
                </button>
                <button
                    v-if="activeSection === 'blog'"
                    class="btn primary"
                    :disabled="blogStore.mutating"
                    @click="openCreateBlog"
                >
                    + Tạo bài viết
                </button>
            </div>
        </header>

        <div class="view-toggle" role="tablist" aria-label="Phân khu">
            <button
                role="tab"
                :aria-selected="activeSection === 'asset'"
                :class="{ active: activeSection === 'asset' }"
                @click="switchSection('asset')"
            >
                AI Content
            </button>
            <button
                role="tab"
                :aria-selected="activeSection === 'blog'"
                :class="{ active: activeSection === 'blog' }"
                @click="switchSection('blog')"
            >
                Blog nhà hàng
                <span v-if="activeSection === 'blog'" class="tab-pill">{{ blogStore.meta.total }}</span>
            </button>
        </div>

        <!-- =========================================================== -->
        <!-- AI CONTENT SECTION                                          -->
        <!-- =========================================================== -->
        <template v-if="activeSection === 'asset'">
            <div class="view-toggle" role="tablist" aria-label="Chế độ xem">
                <button
                    role="tab"
                    :aria-selected="store.viewMode === 'active'"
                    :class="{ active: store.viewMode === 'active' }"
                    @click="switchView('active')"
                >
                    Đang hoạt động
                </button>
                <button
                    role="tab"
                    :aria-selected="store.viewMode === 'trash'"
                    :class="{ active: store.viewMode === 'trash' }"
                    @click="switchView('trash')"
                >
                    Thùng rác
                    <span v-if="store.viewMode === 'trash'" class="tab-pill">{{ store.meta.total }}</span>
                </button>
            </div>

            <nav class="tabs" role="tablist" aria-label="Khu vực làm việc">
                <button role="tab" :aria-selected="tab === 'list'" :class="{ active: tab === 'list' }" @click="switchTab('list')">
                    Danh sách ({{ store.meta.total }})
                </button>
                <button
                    v-if="store.viewMode === 'active'"
                    role="tab"
                    :aria-selected="tab === 'create'"
                    :class="{ active: tab === 'create' }"
                    @click="switchTab('create')"
                >
                    Tạo mới
                </button>
                <button
                    v-if="store.viewMode === 'active'"
                    role="tab"
                    :aria-selected="tab === 'review'"
                    :class="{ active: tab === 'review' }"
                    @click="switchTab('review')"
                >
                    Duyệt nội dung
                    <span v-if="reviewId" class="tab-pill">#{{ reviewId }}</span>
                </button>
            </nav>

        <!-- ============ TAB: LIST ============ -->
        <section v-if="tab === 'list'" class="list-section">
            <div class="filter-row">
                <select v-model="filter.status" @change="onFilterChange">
                    <option value="">Tất cả trạng thái</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
                </select>
                <select v-model="filter.channel" @change="onFilterChange">
                    <option value="">Tất cả kênh</option>
                    <option v-for="c in (store.context?.channels ?? [])" :key="c" :value="c">{{ channelLabel(c) }}</option>
                </select>
                <input v-model="filter.q" type="text" placeholder="Tìm theo subject…" @keyup.enter="onFilterChange" />
                <button class="btn ghost" @click="onFilterChange">Lọc</button>
            </div>

            <div v-if="store.loading" class="state">Đang tải…</div>
            <div v-else-if="store.error" class="state error">{{ store.error }}</div>
            <div v-else-if="store.rows.length === 0" class="state empty">
                Chưa có asset nào. <button type="button" class="link" @click="switchTab('create')">Tạo asset đầu tiên →</button>
            </div>
            <div v-else class="asset-grid">
                <article v-for="row in store.rows" :key="row.id" class="asset-card" :data-status="row.status">
                    <header class="card-head">
                        <span class="channel-pill" :data-channel="row.channel">{{ channelLabel(row.channel) }}</span>
                        <span class="status-pill" :data-status="row.status">{{ statusLabel(row.status) }}</span>
                    </header>
                    <h3 class="card-title">{{ row.subject }}</h3>
                    <div class="card-meta">
                        <span>Tone: <strong>{{ row.tone }}</strong></span>
                        <span>Versions: {{ row.versions_count ?? (row.versions?.length ?? 0) }}</span>
                    </div>
                    <footer class="card-foot">
                        <button
                            v-if="store.viewMode === 'active'"
                            class="btn ghost sm"
                            @click="openReview(row)"
                        >
                            Mở
                        </button>
                        <button
                            v-if="store.viewMode === 'active'"
                            class="btn danger sm"
                            @click="onArchive(row)"
                        >
                            Lưu trữ
                        </button>
                        <template v-else>
                            <button
                                class="btn ghost sm"
                                :disabled="store.mutating"
                                @click="onRestore(row)"
                            >
                                Khôi phục
                            </button>
                            <button
                                class="btn danger sm"
                                :disabled="store.mutating"
                                @click="onForceDelete(row)"
                            >
                                Xoá vĩnh viễn
                            </button>
                        </template>
                    </footer>
                </article>
            </div>

            <nav v-if="store.meta.last_page > 1" class="pagination">
                <button :disabled="store.meta.current_page <= 1" @click="store.loadAssets(store.meta.current_page - 1)">‹ Trước</button>
                <span>Trang {{ store.meta.current_page }} / {{ store.meta.last_page }} ({{ store.meta.total }} asset)</span>
                <button :disabled="store.meta.current_page >= store.meta.last_page" @click="store.loadAssets(store.meta.current_page + 1)">Sau ›</button>
            </nav>
        </section>

        <!-- ============ TAB: CREATE ============ -->
        <section v-if="tab === 'create'" class="create-section">
            <form class="create-form" @submit.prevent="onCreate">
                <div class="grid">
                    <label class="field">
                        <span>Kênh đăng</span>
                        <select v-model="form.channel" required>
                            <option v-for="c in (store.context?.channels ?? [])" :key="c" :value="c">{{ channelLabel(c) }}</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Loại content</span>
                        <select v-model="form.content_type" required>
                            <option v-for="t in (store.context?.content_types ?? [])" :key="t" :value="t">{{ contentTypeLabel(t) }}</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Tone</span>
                        <select v-model="form.tone">
                            <option v-for="t in (store.context?.tones ?? [])" :key="t" :value="t">{{ toneLabel(t) }}</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Chiến dịch (optional)</span>
                        <select v-model="form.campaign_id">
                            <option :value="null">— Không gắn —</option>
                            <option v-for="c in (store.context?.campaigns ?? [])" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </label>
                    <label class="field span-2">
                        <span>Món ăn (gợi ý subject + giá)</span>
                        <select v-model="form.menu_item_id" @change="onMenuItemChange">
                            <option :value="null">— Tự nhập subject —</option>
                            <option v-for="m in (store.context?.menu_items ?? [])" :key="m.id" :value="m.id">{{ m.name }} ({{ formatVnd(m.price) }}đ)</option>
                        </select>
                    </label>
                    <label class="field span-2">
                        <span>Subject (tiêu đề)</span>
                        <input v-model="form.subject" type="text" maxlength="200" placeholder="VD: Mì cay bò Mỹ" />
                    </label>
                </div>

                <p class="brand-info" v-if="store.context">
                    {{ store.context.store_info }}
                </p>

                <div class="form-actions">
                    <button type="submit" class="btn primary" :disabled="store.mutating">
                        {{ store.mutating ? 'Đang sinh…' : 'Tạo asset + sinh version 1' }}
                    </button>
                </div>
            </form>
        </section>

        <!-- ============ TAB: REVIEW ============ -->
        <section v-if="tab === 'review'" class="review-section">
            <div v-if="!reviewId" class="state empty">Chọn 1 asset ở tab "Danh sách" để duyệt.</div>
            <div v-else-if="store.currentLoading" class="state">Đang tải asset…</div>
            <article v-else-if="store.current" class="review-detail">
                <header class="review-head">
                    <div>
                        <span class="channel-pill" :data-channel="store.current.channel">{{ channelLabel(store.current.channel) }}</span>
                        <span class="status-pill" :data-status="store.current.status">{{ statusLabel(store.current.status) }}</span>
                    </div>
                    <button class="btn primary sm" :disabled="store.mutating" @click="onGenerate">
                        Sinh version mới
                    </button>
                </header>

                <h2 class="review-title">{{ store.current.subject }}</h2>
                <p class="review-meta">Tone: <strong>{{ store.current.tone }}</strong> · {{ store.current.versions?.length ?? 0 }} version</p>

                <div class="version-stack">
                    <article
                        v-for="v in store.current.versions ?? []"
                        :key="v.id"
                        class="version-card"
                        :data-selected="v.is_selected ? 'true' : 'false'"
                    >
                        <header class="version-head">
                            <span class="version-num">Version {{ v.version_number }}</span>
                            <span class="provider-pill" :data-provider="v.provider">{{ v.provider }}</span>
                            <button
                                v-if="!v.is_selected && store.current.status !== 'ARCHIVED'"
                                class="btn ghost sm"
                                :disabled="store.mutating"
                                @click="onSelectVersion(v.id)"
                            >
                                Duyệt version này
                            </button>
                            <button
                                v-if="store.current.status !== 'ARCHIVED'"
                                class="btn ghost sm"
                                :disabled="store.mutating || blogStore.mutating"
                                @click="openEditVersion(v)"
                            >
                                Sửa nội dung
                            </button>
                            <button
                                v-if="store.current.status !== 'ARCHIVED'"
                                class="btn ghost sm"
                                :disabled="blogStore.mutating"
                                @click="onPushVersionToBlog(v)"
                            >
                                Đẩy lên Blog
                            </button>
                            <span v-if="v.is_selected" class="approved-pill">Đã duyệt</span>
                        </header>

                        <h4 v-if="v.headline" class="version-headline">{{ v.headline }}</h4>
                        <p v-if="v.caption" class="version-caption">{{ v.caption }}</p>
                        <pre v-if="v.body" class="version-body">{{ v.body }}</pre>
                        <div v-if="v.hashtags && v.hashtags.length" class="hashtags">
                            <span v-for="tag in v.hashtags" :key="tag" class="hashtag">{{ tag }}</span>
                        </div>
                    </article>
                </div>
            </article>
        </section>
        </template>

        <!-- =========================================================== -->
        <!-- BLOG SECTION                                                 -->
        <!-- =========================================================== -->
        <template v-if="activeSection === 'blog'">
            <nav class="tabs" role="tablist" aria-label="Khu vực blog">
                <button
                    role="tab"
                    :aria-selected="blogTab === 'list'"
                    :class="{ active: blogTab === 'list' }"
                    @click="blogTab = 'list'"
                >
                    Danh sách ({{ blogStore.meta.total }})
                </button>
            </nav>

            <section v-if="blogTab === 'list'" class="blog-list-section">
                <form class="filter-row" @submit.prevent="onBlogFilter">
                    <select v-model="blogFilters.status" @change="onBlogFilter">
                        <option value="">Tất cả trạng thái</option>
                        <option value="DRAFT">Bản nháp</option>
                        <option value="PUBLISHED">Đã xuất bản</option>
                        <option value="ARCHIVED">Lưu trữ</option>
                    </select>
                    <select v-model="blogFilters.news_section" @change="onBlogFilter">
                        <option value="">Tất cả mục</option>
                        <option value="NEWS">Tin mới</option>
                        <option value="OPENING">Tin khai trương</option>
                        <option value="PROMOTION">Tin khuyến mãi</option>
                    </select>
                    <select v-model="blogFilters.category" @change="onBlogFilter">
                        <option value="">Tất cả danh mục</option>
                        <option value="NEWS">NEWS</option>
                        <option value="PROMOTION">PROMOTION</option>
                        <option value="NEW_DISH">NEW_DISH</option>
                        <option value="EVENT">EVENT</option>
                        <option value="CULINARY">CULINARY</option>
                    </select>
                    <input v-model="blogFilters.search" type="text" placeholder="Tìm theo tiêu đề…" @keyup.enter="onBlogFilter" />
                    <button type="button" class="btn ghost" @click="onBlogFilter">Lọc</button>
                </form>

                <div v-if="blogStore.loading" class="state">Đang tải…</div>
                <div v-else-if="blogStore.error" class="state error">{{ blogStore.error }}</div>
                <div v-else-if="blogStore.rows.length === 0" class="state empty">
                    Chưa có bài viết nào. <button type="button" class="link" @click="openCreateBlog">Tạo bài viết đầu tiên →</button>
                </div>
                <div v-else class="asset-grid">
                    <article
                        v-for="b in blogStore.rows"
                        :key="b.id"
                        class="asset-card blog-card"
                        :data-status="b.status"
                    >
                        <div v-if="b.cover_image" class="blog-cover">
                            <img :src="b.cover_image" :alt="b.title" loading="lazy" />
                        </div>
                        <div v-else class="blog-cover fallback" :aria-label="'Không có ảnh bìa cho ' + b.title">
                            <span>{{ blogInitials(b.title) }}</span>
                        </div>
                        <header class="card-head">
                            <span class="channel-pill" :data-channel="b.news_section">{{ blogSectionLabel(b.news_section) }}</span>
                            <span class="status-pill" :data-status="b.status">{{ blogStatusLabel(b.status) }}</span>
                        </header>
                        <h3 class="card-title">{{ b.title }}</h3>
                        <p v-if="b.excerpt" class="blog-excerpt">{{ b.excerpt }}</p>
                        <div class="card-meta">
                            <span v-if="b.author">{{ b.author.name }}</span>
                            <span v-if="b.published_at">{{ formatDate(b.published_at) }}</span>
                            <span v-else class="muted">Chưa xuất bản</span>
                            <span v-if="b.reading_time" class="muted">· {{ b.reading_time }} phút đọc</span>
                        </div>
                        <footer class="card-foot blog-actions">
                            <button class="btn ghost sm" @click="openEditBlog(b)">Sửa</button>
                            <button
                                v-if="b.status !== 'PUBLISHED'"
                                class="btn primary sm"
                                :disabled="blogStore.mutating"
                                @click="onPublishBlog(b)"
                            >
                                Xuất bản
                            </button>
                            <button
                                v-if="b.status === 'PUBLISHED'"
                                class="btn ghost sm"
                                :disabled="blogStore.mutating"
                                @click="onArchiveBlog(b)"
                            >
                                Lưu trữ
                            </button>
                            <button class="btn danger sm" :disabled="blogStore.mutating" @click="onDeleteBlog(b)">Xoá</button>
                        </footer>
                    </article>
                </div>

                <nav v-if="blogStore.meta.last_page > 1" class="pagination">
                    <button :disabled="blogStore.meta.current_page <= 1" @click="blogStore.loadBlogs(blogStore.meta.current_page - 1)">
                        ‹ Trước
                    </button>
                    <span>Trang {{ blogStore.meta.current_page }} / {{ blogStore.meta.last_page }} ({{ blogStore.meta.total }} bài)</span>
                    <button :disabled="blogStore.meta.current_page >= blogStore.meta.last_page" @click="blogStore.loadBlogs(blogStore.meta.current_page + 1)">
                        Sau ›
                    </button>
                </nav>
            </section>
        </template>

        <BlogFormModal
            ref="blogFormRef"
            :blog="editingBlog"
            @save="onSaveBlog"
            @cancel="editingBlog = null"
        />

        <VersionEditModal
            ref="editModalRef"
            :version="editingVersion"
            @save="onSaveVersion"
            @cancel="editingVersion = null"
        />
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, computed } from 'vue'
import { useAppDialog } from '@/composables/useAppDialog'
import { useToast } from '@/composables/useStaffUtils'
import { useMarketingStudioStore } from '@/stores/marketingStudio'
import { useBlogManagerStore } from '@/stores/blogManager'
import VersionEditModal from '@/components/manager/promotions/VersionEditModal.vue'
import BlogFormModal from '@/components/manager/promotions/BlogFormModal.vue'
import type { CreateAssetPayload, MarketingAsset, MarketingVersion } from '@/api/marketing'
import type { ManagerBlog, BlogPayload } from '@/api/managerBlog'
import { pushAssetVersionToBlog } from '@/api/marketing'

const store = useMarketingStudioStore()
const blogStore = useBlogManagerStore()
const appDialog = useAppDialog()
const toast = useToast()

const statuses = ['DRAFT', 'IN_REVIEW', 'APPROVED', 'SCHEDULED', 'PUBLISHED', 'ARCHIVED'] as const
type StatusValue = (typeof statuses)[number]

const tab = ref<'list' | 'create' | 'review'>('list')
const reviewId = ref<number | null>(null)

const activeSection = ref<'asset' | 'blog'>('asset')
const blogTab = ref<'list'>('list')
const editingBlog = ref<ManagerBlog | null>(null)
const blogFormRef = ref<InstanceType<typeof BlogFormModal> | null>(null)

const blogFilters = reactive({
    status: '' as '' | 'DRAFT' | 'PUBLISHED' | 'ARCHIVED',
    news_section: '' as '' | 'NEWS' | 'OPENING' | 'PROMOTION',
    category: '' as '' | 'NEWS' | 'PROMOTION' | 'NEW_DISH' | 'EVENT' | 'CULINARY',
    search: '' as string,
})

const filter = reactive({
    status: '' as '' | StatusValue,
    channel: '' as string,
    q: '' as string,
})

const form = reactive<{
    channel: 'FACEBOOK' | 'INSTAGRAM' | 'TIKTOK' | 'ZALO' | 'BLOG' | 'EMAIL'
    content_type: 'POST' | 'STORY' | 'REEL_SCRIPT' | 'BLOG_ARTICLE' | 'EMAIL_BLAST'
    tone: 'FUN' | 'WARM' | 'PREMIUM' | 'CASUAL' | 'PUNCHY'
    subject: string
    campaign_id: number | null
    menu_item_id: number | null
}>({
    channel: 'FACEBOOK',
    content_type: 'POST',
    tone: 'FUN',
    subject: '',
    campaign_id: null,
    menu_item_id: null,
})

onMounted(async () => {
    await store.loadContext()
    await store.loadAssets(1)
})

function switchSection(section: 'asset' | 'blog') {
    activeSection.value = section
    if (section === 'blog') {
        // Khi chuyển sang tab Blog lần đầu → load.
        if (blogStore.rows.length === 0 && !blogStore.loading) {
            void blogStore.loadBlogs(1)
        }
    }
}

function switchTab(t: 'list' | 'create' | 'review') {
    tab.value = t
    if (t === 'list') store.loadAssets(1)
}

function onBlogFilter() {
    blogStore.setFilter({
        status: blogFilters.status as any,
        news_section: blogFilters.news_section as any,
        category: blogFilters.category as any,
        search: blogFilters.search,
    })
    void blogStore.loadBlogs(1)
}

function openCreateBlog() {
    editingBlog.value = null
    blogFormRef.value?.open()
}

async function openEditBlog(b: ManagerBlog) {
    try {
        editingBlog.value = await blogStore.loadBlog(b.id)
        blogFormRef.value?.open()
    } catch {
        toast.error('Không tải được chi tiết bài viết.')
    }
}

async function onSaveBlog(payload: BlogPayload & { id?: number }) {
    try {
        if (payload.id) {
            await blogStore.update(payload.id, payload)
        } else {
            await blogStore.create(payload)
        }
        editingBlog.value = null
    } catch (e: any) {
        toast.error(e?.response?.data?.message || 'Lưu bài viết thất bại.')
    }
}

async function onPublishBlog(b: ManagerBlog) {
    if (!await appDialog.confirm({ title: 'Xuất bản bài viết?', message: `“${b.title}” sẽ hiển thị công khai trên trang khách hàng.`, confirmLabel: 'Xuất bản', tone: 'warning' })) return
    try {
        await blogStore.publish(b.id)
    } catch (e: any) {
        toast.error(e?.response?.data?.message || 'Xuất bản thất bại.')
    }
}

async function onArchiveBlog(b: ManagerBlog) {
    if (!await appDialog.confirm({ title: 'Lưu trữ bài viết?', message: `“${b.title}” sẽ được chuyển vào mục đã lưu trữ.`, confirmLabel: 'Lưu trữ' })) return
    try {
        await blogStore.archive(b.id)
    } catch (e: any) {
        toast.error(e?.response?.data?.message || 'Lưu trữ thất bại.')
    }
}

async function onDeleteBlog(b: ManagerBlog) {
    if (!await appDialog.confirm({ title: 'Xóa vĩnh viễn bài viết?', message: `“${b.title}” sẽ bị xóa và không thể khôi phục.`, confirmLabel: 'Xóa bài viết', tone: 'danger' })) return
    try {
        await blogStore.remove(b.id)
    } catch (e: any) {
        toast.error(e?.response?.data?.message || 'Xóa thất bại.')
    }
}

async function onPushVersionToBlog(v: MarketingVersion) {
    if (!store.current) return
    if (!await appDialog.confirm({ title: 'Tạo bản nháp Blog?', message: `Version ${v.version_number} sẽ được tạo thành bài viết nháp trong mục Blog.`, confirmLabel: 'Tạo bản nháp' })) return
    try {
        const blog = await pushAssetVersionToBlog(store.current.id, { version_id: v.id })
        toast.success('Đã tạo bài viết nháp trong Blog nhà hàng.')
        // Switch sang blog section để user xem ngay.
        activeSection.value = 'blog'
        void blogStore.loadBlogs(1)
        void blog
    } catch (e: any) {
        toast.error(e?.response?.data?.message || 'Đẩy lên Blog thất bại.')
    }
}

function onFilterChange() {
    store.setFilter({ status: filter.status as any, channel: filter.channel, q: filter.q })
    store.loadAssets(1)
}

function onMenuItemChange() {
    if (!form.menu_item_id) return
    const m = store.context?.menu_items.find((x) => x.id === form.menu_item_id)
    if (m && !form.subject) form.subject = m.name
}

async function onCreate() {
    if (!form.subject.trim()) {
        toast.warning('Nhập chủ đề hoặc chọn món ăn để AI tự gợi ý.')
        return
    }
    const payload: CreateAssetPayload = {
        channel: form.channel,
        content_type: form.content_type,
        tone: form.tone,
        subject: form.subject,
        campaign_id: form.campaign_id,
        menu_item_id: form.menu_item_id,
    }
    const created = await store.create(payload)
    tab.value = 'review'
    reviewId.value = created.id
    await store.loadAsset(created.id)
}

function openCreate() {
    tab.value = 'create'
}

async function openReview(row: MarketingAsset) {
    tab.value = 'review'
    reviewId.value = row.id
    await store.loadAsset(row.id)
}

async function onGenerate() {
    if (!store.current) return
    await store.generateVersion(store.current.id)
}

async function onSelectVersion(vid: number) {
    if (!store.current) return
    await store.selectAssetVersion(store.current.id, vid)
}

async function onArchive(row: MarketingAsset) {
    if (!await appDialog.confirm({ title: 'Lưu trữ nội dung?', message: `“${row.subject}” sẽ được chuyển vào thùng rác và có thể khôi phục.`, confirmLabel: 'Lưu trữ' })) return
    await store.archive(row.id)
}

async function onRestore(row: MarketingAsset) {
    if (!await appDialog.confirm({ title: 'Khôi phục nội dung?', message: `“${row.subject}” sẽ được đưa về bản nháp.`, confirmLabel: 'Khôi phục' })) return
    try {
        await store.restoreAsset(row.id)
    } catch (e: any) {
        toast.error(e?.response?.data?.message || 'Không thể khôi phục nội dung.')
    }
}

async function onForceDelete(row: MarketingAsset) {
    const ok = await appDialog.confirm({
        title: 'Xóa vĩnh viễn nội dung?',
        message: `“${row.subject}” cùng toàn bộ phiên bản và tệp đính kèm sẽ bị xóa, không thể khôi phục.`,
        confirmLabel: 'Tiếp tục xác nhận',
        tone: 'danger',
    })
    if (!ok) return
    // Lớp confirm 2: bắt buộc nhập đúng tên asset để xác nhận.
    const typed = await appDialog.prompt({ title: 'Xác nhận tên nội dung', message: 'Nhập đúng tên nội dung để hoàn tất thao tác xóa vĩnh viễn.', label: 'Tên nội dung', placeholder: row.subject, confirmLabel: 'Xóa vĩnh viễn', tone: 'danger' })
    if (typed !== row.subject) {
        toast.info('Đã hủy thao tác vì tên không khớp.')
        return
    }
    try {
        await store.forceDeleteAsset(row.id)
    } catch (e: any) {
        toast.error(e?.response?.data?.message || 'Không thể xóa nội dung.')
    }
}

/* ===== Edit version ===== */

const editModalRef = ref<InstanceType<typeof VersionEditModal> | null>(null)
const editingVersion = ref<MarketingVersion | null>(null)

function openEditVersion(v: MarketingVersion) {
    editingVersion.value = v
    editModalRef.value?.open()
}

async function onSaveVersion(payload: {
    headline: string
    caption: string
    body: string
    hashtags: string[]
}) {
    if (!store.current || !editingVersion.value) return
    try {
        await store.updateAssetVersion(store.current.id, editingVersion.value.id, payload)
        editingVersion.value = null
    } catch (e: any) {
        toast.error(e?.response?.data?.message || 'Không thể lưu phiên bản.')
    }
}

function switchView(mode: 'active' | 'trash') {
    store.setViewMode(mode)
    // Reset tab về list để tránh nhảy sang review/create khi chuyển thùng rác.
    tab.value = 'list'
    reviewId.value = null
    store.loadAssets(1)
}

/* ===== Labels ===== */

const labels = {
    channel: { FACEBOOK: 'Facebook', INSTAGRAM: 'Instagram', TIKTOK: 'TikTok', ZALO: 'Zalo', BLOG: 'Blog', EMAIL: 'Email' } as const,
    content_type: { POST: 'Bài viết', STORY: 'Story', REEL_SCRIPT: 'Kịch bản Reel', BLOG_ARTICLE: 'Bài blog', EMAIL_BLAST: 'Email blast' } as const,
    tone: { FUN: 'Vui vẻ', WARM: 'Ấm áp', PREMIUM: 'Sang trọng', CASUAL: 'Đời thường', PUNCHY: 'Gọn mạnh' } as const,
    status: {
        DRAFT: 'Bản nháp', IN_REVIEW: 'Đang duyệt', APPROVED: 'Đã duyệt',
        SCHEDULED: 'Đã lên lịch', PUBLISHED: 'Đã đăng', REJECTED: 'Bị từ chối', ARCHIVED: 'Đã lưu trữ',
    } as const,
}

function channelLabel(c: string) { return (labels.channel as any)[c] ?? c }
function contentTypeLabel(t: string) { return (labels.content_type as any)[t] ?? t }
function toneLabel(t: string) { return (labels.tone as any)[t] ?? t }
function statusLabel(s: string) { return (labels.status as any)[s] ?? s }

const blogLabels = {
    section: { NEWS: 'Tin mới', OPENING: 'Tin khai trương', PROMOTION: 'Tin khuyến mãi' } as const,
    status: { DRAFT: 'Bản nháp', PUBLISHED: 'Đã xuất bản', ARCHIVED: 'Lưu trữ' } as const,
}
function blogSectionLabel(s: string) { return (blogLabels.section as any)[s] ?? s }
function blogStatusLabel(s: string) { return (blogLabels.status as any)[s] ?? s }
function blogInitials(title: string): string {
    return title
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('')
}
function formatDate(iso: string | null | undefined): string {
    if (!iso) return ''
    try { return new Date(iso).toLocaleDateString('vi-VN') } catch { return '' }
}

function formatVnd(n: number) { return n.toLocaleString('vi-VN') }
</script>

<style scoped>
/* ============================================================
 * Restaurant-aligned UI — không phá design system hiện tại.
 * ============================================================ */

.marketing-page {
    padding: 24px;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    overflow-wrap: anywhere;
    max-width: 1280px;
    margin: 0 auto;
    font-family: var(--font-family-base, system-ui);
    color: var(--color-charcoal-black, #1a1a1a);
}

.page-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}
.page-head .eyebrow {
    text-transform: uppercase;
    letter-spacing: 1px;
    font-size: 11px;
    color: var(--color-chili-red, #d71920);
    font-weight: 700;
}
.page-head h2 {
    margin: 6px 0 4px;
    font-size: 26px;
    line-height: 1.2;
}
.page-head p { color: rgba(0, 0, 0, 0.55); margin: 0; font-size: 14px; }

.tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.08);
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.tabs button {
    padding: 10px 16px;
    background: transparent;
    border: 0;
    border-bottom: 2px solid transparent;
    font-size: 14px;
    font-weight: 500;
    color: rgba(0, 0, 0, 0.6);
    cursor: pointer;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: color 0.12s, border-color 0.12s;
}
.tabs button:hover { color: rgba(0, 0, 0, 0.85); }
.tabs button.active {
    color: var(--color-chili-red, #d71920);
    border-bottom-color: var(--color-chili-red, #d71920);
    font-weight: 600;
}
.tab-pill {
    background: rgba(215, 25, 32, 0.1);
    color: var(--color-chili-red, #d71920);
    font-size: 11px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 999px;
}

/* ===== Filter ===== */
.filter-row {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.filter-row select,
.filter-row input {
    padding: 8px 12px;
    border: 1px solid rgba(0, 0, 0, 0.12);
    border-radius: 8px;
    background: #fff;
    font-size: 13px;
    font-family: inherit;
}
.filter-row input { min-width: min(220px, 100%); }
.filter-row input, .filter-row select { max-width: 100%; }

/* ===== Asset grid ===== */
.asset-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr));
    gap: 14px;
}
.asset-card {
    min-width: 0;
    background: #fff;
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 12px;
    padding: 14px;
    transition: box-shadow 0.15s, transform 0.15s;
}
.asset-card:hover {
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
    transform: translateY(-1px);
}
.card-head {
    display: flex;
    justify-content: space-between;
    gap: 6px;
    margin-bottom: 8px;
}
.card-title {
    margin: 0 0 6px;
    font-size: 15px;
    line-height: 1.35;
}
.card-meta {
    display: flex;
    justify-content: space-between;
    color: rgba(0, 0, 0, 0.55);
    font-size: 12px;
    margin-bottom: 12px;
}
.card-foot { display: flex; flex-wrap: wrap; gap: 8px; }

.channel-pill,
.status-pill {
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
}
.channel-pill {
    background: rgba(26, 14, 6, 0.06);
    color: rgba(26, 14, 6, 0.7);
}
.status-pill[data-status="APPROVED"] { background: rgba(22, 163, 74, 0.12); color: #166534; }
.status-pill[data-status="PUBLISHED"] { background: rgba(22, 163, 74, 0.18); color: #15803d; }
.status-pill[data-status="DRAFT"]     { background: rgba(0, 0, 0, 0.06);        color: rgba(0, 0, 0, 0.55); }
.status-pill[data-status="ARCHIVED"]  { background: rgba(0, 0, 0, 0.06);        color: rgba(0, 0, 0, 0.55); }
.status-pill[data-status="IN_REVIEW"] { background: rgba(245, 158, 11, 0.14);  color: #92400e; }
.status-pill[data-status="REJECTED"]  { background: rgba(217, 25, 32, 0.10);   color: var(--color-chili-red, #d71920); }
.status-pill[data-status="SCHEDULED"] { background: rgba(59, 130, 246, 0.12);  color: #1d4ed8; }

.provider-pill[data-provider="HUMAN"]  { background: rgba(217, 25, 32, 0.10); color: var(--color-chili-red, #d71920); }
.provider-pill[data-provider="GEMINI"] { background: rgba(15, 118, 110, 0.10); color: #0f766e; }
.provider-pill[data-provider="OPENAI"] { background: rgba(124, 58, 237, 0.10); color: #6d28d9; }

/* ===== View toggle (Đang hoạt động / Thùng rác) ===== */
.view-toggle {
    display: inline-flex;
    flex-wrap: wrap;
    max-width: 100%;
    gap: 4px;
    padding: 4px;
    background: rgba(0, 0, 0, 0.04);
    border-radius: 999px;
    margin-bottom: 16px;
}
.view-toggle button {
    border: 0;
    background: transparent;
    padding: 8px 14px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    color: rgba(0, 0, 0, 0.6);
    cursor: pointer;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.12s, color 0.12s;
}
.view-toggle button:hover { color: rgba(0, 0, 0, 0.85); }
.view-toggle button.active {
    background: #fff;
    color: var(--color-chili-red, #d71920);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

/* ===== Blog list ===== */
.blog-list-section { display: flex; flex-direction: column; }
.blog-card {
    flex-direction: column;
    align-items: stretch;
    padding: 0;
    overflow: hidden;
}
.blog-cover {
    width: 100%;
    aspect-ratio: 16 / 9;
    background: rgba(26, 14, 6, 0.05);
    overflow: hidden;
    position: relative;
}
.blog-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}
.blog-card:hover .blog-cover img {
    transform: scale(1.04);
}
.blog-cover.fallback {
    background: linear-gradient(135deg, var(--color-chili-red, #d71920), #ff574c);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    font-weight: 800;
    letter-spacing: -1px;
}
.blog-card .card-head {
    margin-bottom: 8px;
    padding: 12px 14px 0;
}
.blog-card .card-title,
.blog-card .blog-excerpt,
.blog-card .card-meta,
.blog-card .card-foot {
    padding-left: 14px;
    padding-right: 14px;
}
.blog-card .card-foot {
    padding-bottom: 14px;
    padding-top: 4px;
}
.blog-excerpt {
    color: rgba(0, 0, 0, 0.6);
    font-size: 13px;
    line-height: 1.5;
    margin: 4px 0 10px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.blog-actions {
    flex-wrap: wrap;
}
.blog-actions .btn { flex: 1 1 auto; min-width: 80px; }
.muted { color: rgba(0, 0, 0, 0.5); font-size: 12px; }

/* ===== Create form ===== */
.create-form {
    background: #fff;
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 12px;
    padding: 20px;
}
.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}
.field { display: flex; flex-direction: column; min-width: 0; gap: 6px; font-size: 13px; }
.field.span-2 { grid-column: span 2; }
.field span { font-weight: 600; color: rgba(0, 0, 0, 0.7); }
.field input,
.field select {
    min-width: 0;
    max-width: 100%;
    padding: 9px 12px;
    border: 1px solid rgba(0, 0, 0, 0.12);
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
}
.field input:focus,
.field select:focus {
    border-color: var(--color-chili-red, #d71920);
    outline: 2px solid rgba(215, 25, 32, 0.15);
}
.brand-info {
    margin: 16px 0 0;
    padding: 12px 14px;
    background: rgba(215, 25, 32, 0.06);
    border-left: 3px solid var(--color-chili-red, #d71920);
    border-radius: 6px;
    color: rgba(0, 0, 0, 0.7);
    font-size: 13px;
}
.form-actions { margin-top: 16px; display: flex; justify-content: flex-end; }

/* ===== Review ===== */
.review-detail {
    background: #fff;
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 12px;
    padding: 20px;
}
.review-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.review-title { margin: 0 0 6px; font-size: 22px; line-height: 1.25; }
.review-meta { color: rgba(0, 0, 0, 0.55); font-size: 13px; margin: 0 0 18px; }

.version-stack { display: flex; flex-direction: column; gap: 14px; }
.version-card {
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 10px;
    padding: 16px;
    background: #fafafa;
    position: relative;
}
.version-card[data-selected="true"] {
    border-color: rgba(22, 163, 74, 0.4);
    background: rgba(22, 163, 74, 0.04);
}
.version-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}
.version-num { font-weight: 700; font-size: 13px; }
.provider-pill {
    background: rgba(26, 14, 6, 0.06);
    color: rgba(26, 14, 6, 0.7);
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 999px;
}
.approved-pill {
    background: rgba(22, 163, 74, 0.16);
    color: #166534;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 999px;
}
.version-headline { margin: 0 0 8px; font-size: 16px; }
.version-caption {
    margin: 0 0 10px;
    font-size: 14px;
    line-height: 1.55;
    white-space: pre-wrap;
}
.version-body {
    margin: 0 0 10px;
    padding: 10px 12px;
    background: #fff;
    border: 1px dashed rgba(0, 0, 0, 0.1);
    border-radius: 6px;
    font-family: inherit;
    font-size: 13px;
    white-space: pre-wrap;
    line-height: 1.55;
    color: rgba(0, 0, 0, 0.78);
}
.hashtags { display: flex; flex-wrap: wrap; gap: 6px; }
.hashtag {
    background: rgba(215, 25, 32, 0.08);
    color: var(--color-chili-red, #d71920);
    font-size: 12px;
    padding: 3px 8px;
    border-radius: 999px;
}

/* ===== Buttons (shared) ===== */
.btn {
    padding: 9px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid transparent;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.12s, color 0.12s, border-color 0.12s;
}
.btn.primary {
    background: var(--color-chili-red, #d71920);
    color: #fff;
}
.btn.primary:hover:not(:disabled) { background: #b91518; }
.btn.ghost {
    background: transparent;
    border-color: rgba(0, 0, 0, 0.12);
    color: rgba(0, 0, 0, 0.7);
}
.btn.ghost:hover:not(:disabled) { background: rgba(0, 0, 0, 0.04); }
.btn.danger {
    background: transparent;
    border-color: rgba(215, 25, 32, 0.25);
    color: var(--color-chili-red, #d71920);
}
.btn.danger:hover:not(:disabled) { background: rgba(215, 25, 32, 0.08); }
.btn.sm { padding: 6px 10px; font-size: 12px; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.link {
    display: inline-flex;
    align-items: center;
    min-height: 40px;
    margin-left: 8px;
    padding: 8px 14px;
    background: #fff8f4;
    border: 1px solid rgba(215, 25, 32, 0.35);
    border-radius: 9px;
    color: #991b1b;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    box-shadow: 0 4px 12px rgba(94, 15, 24, 0.08);
    transition: background-color 160ms ease, border-color 160ms ease, transform 160ms ease;
}

.link:hover {
    background: #fff1f2;
    border-color: #d71920;
    transform: translateY(-1px);
}

.link:active {
    transform: translateY(0);
}

.link:focus-visible {
    outline: 3px solid #d71920;
    outline-offset: 2px;
}

.pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-top: 20px;
    color: rgba(0, 0, 0, 0.6);
    font-size: 13px;
}

.state {
    text-align: center;
    padding: 48px 16px;
    color: rgba(0, 0, 0, 0.5);
    font-size: 14px;
}
.state.error { color: var(--color-chili-red, #d71920); }
.state.empty { color: rgba(0, 0, 0, 0.45); }

@media (max-width: 768px) {
    .marketing-page { padding: 0; }
    .grid { grid-template-columns: minmax(0, 1fr); }
    .field.span-2 { grid-column: auto; }
}
</style>
