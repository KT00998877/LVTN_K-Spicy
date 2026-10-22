<template>
    <NavbarHome />
    <main class="customer-page">
        <header class="page-hero"><span>MÌ CAY HÀN QUỐC</span><h1>{{ title }}</h1><p>{{ contact ? 'Chúng tôi luôn sẵn sàng lắng nghe bạn.' : 'Khám phá những câu chuyện và trải nghiệm tại nhà hàng.' }}</p></header>
        <div v-if="contact" class="contact-grid">
            <section class="contact-info"><h2>{{ home.restaurant?.name || 'Mì Cay Hàn Quốc' }}</h2><template v-if="home.restaurant"><dl><dt>Địa chỉ</dt><dd>{{ home.restaurant.address }}</dd><dt>Điện thoại</dt><dd><a :href="'tel:' + home.restaurant.phone">{{ home.restaurant.phone }}</a></dd><dt>Giờ mở cửa</dt><dd>{{ home.restaurant.open_hours }}</dd></dl><a class="page-button" :href="'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(home.restaurant.address)" target="_blank" rel="noopener">Xem đường đi trên bản đồ</a></template><p v-else>{{ home.error ? 'Chưa tải được thông tin liên hệ.' : 'Đang tải thông tin…' }}</p></section>
            <form class="contact-form" @submit.prevent="send"><h2>Gửi lời nhắn</h2><fieldset :disabled="sending"><label>Họ và tên *<input v-model="form.name" required maxlength="100" autocomplete="name" /></label><label>Email *<input v-model="form.email" type="email" required maxlength="150" autocomplete="email" /></label><label>Số điện thoại<input v-model="form.phone" type="tel" maxlength="20" autocomplete="tel" /></label><label>Địa chỉ<input v-model="form.address" maxlength="255" autocomplete="street-address" /></label><label>Nội dung cần liên hệ *<select v-model="form.topic"><option value="FEEDBACK">Góp ý dịch vụ</option><option value="FRANCHISE">Hợp tác / nhượng quyền</option><option value="OTHER">Khác</option></select></label><label>Lời nhắn *<textarea v-model="form.message" required maxlength="5000" rows="5" /></label><button class="page-button">{{ sending ? 'Đang gửi…' : 'Gửi lời nhắn' }}</button></fieldset><p v-if="notice" role="status">{{ notice }}</p><p v-if="error" role="alert" class="error">{{ error }}</p></form>
        </div>

        <!-- ============ BLOG LIST ============ -->
        <template v-else-if="news && route.name === 'customer-news'">
            <nav class="news-tabs" aria-label="Loại tin">
                <RouterLink v-for="n in sections" :key="n.code" :to="'/tin-vui/' + n.path">{{ n.title }}</RouterLink>
            </nav>

            <div v-if="loading" class="news-grid" aria-busy="true">
                <article v-for="i in 6" :key="'sk-' + i" class="news-card skeleton" aria-hidden="true">
                    <div class="cover"></div>
                    <div class="body">
                        <div class="line"></div>
                        <div class="line big"></div>
                        <div class="line short"></div>
                    </div>
                </article>
            </div>

            <p v-else-if="error" class="state error" role="alert">
                {{ error }}
                <button class="retry-btn" @click="fetchNews(1)">Thử lại</button>
            </p>

            <template v-else>
                <div v-if="posts.length" class="news-grid">
                    <article
                        v-for="p in posts"
                        :key="p.id"
                        class="news-card"
                        :data-section="p.news_section"
                    >
                        <RouterLink :to="'/bai-viet/' + p.slug" class="news-link">
                            <div class="cover">
                                <span class="badge" :data-section="p.news_section">{{ sectionBadge(p.news_section) }}</span>
                                <img
                                    v-if="p.cover_image"
                                    :src="imageUrl(p.cover_image)"
                                    :alt="p.title"
                                    loading="lazy"
                                />
                                <div v-else class="cover-fallback">
                                    <span>{{ initials(p.title) }}</span>
                                </div>
                            </div>
                            <div class="body">
                                <small class="meta">
                                    <span>{{ formatDate(p.published_at) }}</span>
                                    <span v-if="p.reading_time" class="dot">•</span>
                                    <span v-if="p.reading_time">{{ p.reading_time }} phút đọc</span>
                                </small>
                                <h3 class="title">{{ p.title }}</h3>
                                <p v-if="p.excerpt" class="excerpt">{{ p.excerpt }}</p>
                                <span class="cta">Đọc tiếp →</span>
                            </div>
                        </RouterLink>
                    </article>
                </div>
                <p v-else class="state">Chưa có bài viết trong mục này.</p>

                <div v-if="lastPage > 1" class="pagination">
                    <button :disabled="page <= 1" @click="fetchNews(page - 1)">‹ Trước</button>
                    <span>{{ page }} / {{ lastPage }}</span>
                    <button :disabled="page >= lastPage" @click="fetchNews(page + 1)">Sau ›</button>
                </div>
            </template>
        </template>

        <!-- ============ ARTICLE DETAIL ============ -->
        <template v-else-if="news && route.name === 'customer-article'">
            <div v-if="loading" class="article-loading">Đang tải bài viết…</div>
            <p v-else-if="error" class="state error" role="alert">{{ error }}</p>

            <article v-else-if="post" class="article">
                <!-- Cover hero -->
                <div class="article-hero" :class="{ 'is-placeholder': !post.cover_image }">
                    <img
                        v-if="post.cover_image"
                        :src="imageUrl(post.cover_image)"
                        :alt="post.title"
                    />
                    <div v-else class="hero-fallback">
                        <span>{{ initials(post.title) }}</span>
                    </div>
                    <div class="hero-overlay">
                        <span class="badge large" :data-section="post.news_section">{{ sectionBadge(post.news_section) }}</span>
                    </div>
                </div>

                <div class="article-layout">
                    <div class="article-main">
                        <!-- Breadcrumb -->
                        <nav class="breadcrumb" aria-label="Đường dẫn">
                            <RouterLink to="/tin-vui/tin-moi">Tin vui</RouterLink>
                            <span class="sep">/</span>
                            <span>{{ currentSectionTitle(post.news_section) }}</span>
                        </nav>

                        <h1 class="article-title">{{ post.title }}</h1>

                        <div class="article-meta">
                            <span v-if="post.author_name" class="author">
                                <span class="author-avatar">{{ initials(post.author_name) }}</span>
                                {{ post.author_name }}
                            </span>
                            <span class="sep">•</span>
                            <time :datetime="post.published_at">{{ formatDate(post.published_at) }}</time>
                            <span class="sep">•</span>
                            <span v-if="post.reading_time">{{ post.reading_time }} phút đọc</span>
                        </div>

                        <p v-if="post.excerpt" class="lead">{{ post.excerpt }}</p>

                        <div class="article-content rich-content" v-html="safeContent"></div>

                        <!-- Share + back -->
                        <div class="article-foot">
                            <RouterLink to="/tin-vui/tin-moi" class="back-link">← Về danh sách tin</RouterLink>
                            <div class="share-row">
                                <span>Chia sẻ:</span>
                                <button class="share-btn facebook" :aria-label="'Chia sẻ lên Facebook'" @click="shareFacebook">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 22v-8h3l1-4h-4V7.5c0-1.1.3-2 2-2h2V2.2C16.6 2.1 15.4 2 14 2c-3 0-5 1.8-5 5v3H6v4h3v8h4z"/></svg>
                                </button>
                                <button class="share-btn zalo" :aria-label="'Chia sẻ qua Zalo'" @click="shareZalo">
                                    <span>Zalo</span>
                                </button>
                                <button class="share-btn copy" :aria-label="'Sao chép liên kết'" @click="copyLink">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar share (desktop) -->
                    <aside class="article-side" aria-label="Chia sẻ">
                        <span class="side-label">Chia sẻ</span>
                        <button class="side-btn facebook" @click="shareFacebook" :aria-label="'Chia sẻ Facebook'">f</button>
                        <button class="side-btn zalo" @click="shareZalo" :aria-label="'Chia sẻ Zalo'">Z</button>
                        <button class="side-btn copy" @click="copyLink" :aria-label="'Sao chép liên kết'">⌘</button>
                    </aside>
                </div>

                <!-- Related -->
                <section v-if="related.length" class="related">
                    <header class="related-head">
                        <span class="eyebrow">CÙNG CHUYÊN MỤC</span>
                        <h2>Bài viết liên quan</h2>
                    </header>
                    <div class="related-grid">
                        <article v-for="r in related" :key="r.id" class="news-card compact">
                                <RouterLink :to="'/bai-viet/' + r.slug" class="news-link">
                                    <div class="cover">
                                        <span class="badge small" :data-section="r.news_section">{{ sectionBadge(r.news_section) }}</span>
                                        <img v-if="r.cover_image" :src="imageUrl(r.cover_image)" :alt="r.title" loading="lazy" />
                                        <div v-else class="cover-fallback"><span>{{ initials(r.title) }}</span></div>
                                    </div>
                                    <div class="body">
                                        <h3 class="title">{{ r.title }}</h3>
                                        <span class="cta">Đọc tiếp →</span>
                                    </div>
                                </RouterLink>
                            </article>
                    </div>
                </section>

                <!-- Toast -->
                <div v-if="copyToast" class="toast" role="status">{{ copyToast }}</div>

                <!-- Back-to-top -->
                <button
                    v-show="showBackTop"
                    class="back-top"
                    aria-label="Trở lại đầu trang"
                    @click="scrollTop"
                >↑</button>
            </article>
        </template>

        <BestSellersSection v-else-if="route.name === 'best-sellers'" />
        <TablesSection v-else-if="route.name === 'customer-tables'" />
        <ReservationSection v-else-if="route.name === 'reservation'" />
    </main><FooterHome />
</template>

<script setup lang="ts">
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import BestSellersSection from '@/components/home/BestSellersSection.vue'
import TablesSection from '@/components/home/TablesSection.vue'
import ReservationSection from '@/components/home/ReservationSection.vue'
import { useHomeStore } from '@/stores/home'
import api from '@/api/client'
import { imageUrl } from '@/utils/image'
import '@/components/home/home.css'

interface Post {
    id: number
    title: string
    slug: string
    excerpt: string | null
    cover_image: string | null
    published_at: string
    content?: string
    news_section?: 'NEWS' | 'OPENING' | 'PROMOTION'
    reading_time?: number
    author_name?: string | null
}

interface Related {
    id: number
    title: string
    slug: string
    excerpt: string | null
    cover_image: string | null
    published_at: string
    news_section?: 'NEWS' | 'OPENING' | 'PROMOTION'
    reading_time?: number
}

const home = useHomeStore(), route = useRoute()
const sections = [
    { code: 'NEWS', path: 'tin-moi', title: 'Tin mới' },
    { code: 'OPENING', path: 'tin-khai-truong', title: 'Tin khai trương' },
    { code: 'PROMOTION', path: 'tin-khuyen-mai', title: 'Tin khuyến mãi' },
] as const

const contact = computed(() => route.name === 'contact')
const news = computed(() => route.name === 'customer-news' || route.name === 'customer-article')
const current = computed(() => sections.find((s) => s.path === route.params.section) || sections[0])
const title = computed(() =>
    contact.value
        ? 'Liên hệ với chúng tôi'
        : news.value
            ? (route.name === 'customer-article' ? 'Tin vui' : current.value!.title)
            : route.name === 'best-sellers'
                ? 'Món bán chạy'
                : route.name === 'customer-tables'
                    ? 'Không gian & bàn ăn'
                    : 'Đặt bàn',
)

const posts = ref<Post[]>([])
const post = ref<Post | null>(null)
const related = ref<Related[]>([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const sending = ref(false)
const showBackTop = ref(false)
const copyToast = ref('')

/** Render HTML an toàn từ content — đã được server sanitize. */
const safeContent = computed(() => post.value?.content ?? '')

function sectionBadge(s: string | undefined): string {
    switch (s) {
        case 'OPENING': return 'Khai trương'
        case 'PROMOTION': return 'Khuyến mãi'
        case 'NEWS':
        default: return 'Tin mới'
    }
}

function currentSectionTitle(s: string | undefined): string {
    return sections.find((x) => x.code === s)?.title ?? 'Tin vui'
}

function initials(title: string): string {
    return title
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('')
}

function formatDate(s: string | null | undefined): string {
    if (!s) return ''
    try {
        const d = new Date(s)
        return d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' })
    } catch {
        return ''
    }
}

const form = reactive({ name: '', email: '', phone: '', address: '', topic: 'FEEDBACK', message: '' })

let requestId = 0
async function fetchNews(p = 1, quiet = false) {
    const id = ++requestId
    if (!news.value) return
    if (!quiet) loading.value = true
    if (!quiet) error.value = ''
    if (!quiet) {
        post.value = null
        related.value = []
    }
    try {
        if (route.name === 'customer-article') {
            const r = await api.get('/news/' + encodeURIComponent(String(route.params.slug)))
            if (id === requestId) {
                const isFirstLoad = post.value === null
                post.value = r.data.data
                related.value = r.data.data?.related ?? []
                error.value = ''
                // Scroll lên top CHỈ khi load bài viết lần đầu.
                // Polling không được reset scroll position — user đang đọc giữa chừng.
                if (isFirstLoad) {
                    setTimeout(() => window.scrollTo({ top: 0, behavior: 'smooth' }), 30)
                }
            }
        } else {
            const r = await api.get('/news', { params: { section: current.value!.code, page: p } })
            if (id === requestId) {
                error.value = ''
                posts.value = r.data.data
                page.value = r.data.current_page
                lastPage.value = r.data.last_page
            }
        }
    } catch (e: any) {
        if (quiet) throw e
        if (id === requestId) {
            error.value = e?.response?.status === 404
                ? 'Bài viết không tồn tại hoặc chưa được công bố.'
                : 'Không tải được bài viết.'
        }
    } finally {
        if (id === requestId && !quiet) loading.value = false
    }
}

async function send() {
    if (sending.value) return
    sending.value = true
    error.value = ''
    notice.value = ''
    try {
        await api.post('/contact', form)
        notice.value = 'Đã tiếp nhận lời nhắn. Cảm ơn bạn đã liên hệ!'
        form.message = ''
    } catch (e: any) {
        error.value = Object.values(e?.response?.data?.errors || {}).flat().join(' ') || 'Chưa gửi được lời nhắn. Vui lòng thử lại sau.'
    } finally {
        sending.value = false
    }
}

/* ========== Share helpers ========== */
function articleUrl(): string {
    return window.location.origin + '/bai-viet/' + (post.value?.slug ?? '')
}
function shareFacebook() {
    const url = encodeURIComponent(articleUrl())
    window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}`, '_blank', 'noopener,width=600,height=500')
}
function shareZalo() {
    const url = encodeURIComponent(articleUrl())
    window.open(`https://sp.zalo.me/shareInline?url=${url}`, '_blank', 'noopener,width=600,height=500')
}
async function copyLink() {
    try {
        await navigator.clipboard.writeText(articleUrl())
        copyToast.value = 'Đã sao chép liên kết!'
        setTimeout(() => (copyToast.value = ''), 1800)
    } catch {
        copyToast.value = 'Không thể sao chép. Hãy copy thủ công từ thanh địa chỉ.'
        setTimeout(() => (copyToast.value = ''), 2400)
    }
}

/* ========== Scroll handlers ========== */
function onScroll() {
    showBackTop.value = window.scrollY > 600
}
function scrollTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

watch(() => route.fullPath, () => {
    error.value = ''
    notice.value = ''
    void fetchNews(1)
})

onMounted(() => {
    void home.load()
    void fetchNews()
    window.addEventListener('scroll', onScroll, { passive: true })
})
onUnmounted(() => {
    requestId++
    window.removeEventListener('scroll', onScroll)
})

useRealtimeRefresh(
    () => fetchNews(page.value, true),
    () => news.value && !loading.value && !sending.value,
)
</script>

<style scoped>
/* ====================================================================
   ROOT
   ==================================================================== */
.customer-page {
    min-height: 65vh;
    background: var(--color-cream);
    color: var(--color-charcoal-black);
    padding-top: var(--navbar-height);
    padding-bottom: var(--space-11);
    font-family: var(--font-family-base);
}
@media (max-width: 768px) {
    .customer-page { padding-top: var(--navbar-height-mobile); }
}

.page-hero {
    text-align: center;
    padding: 64px 24px;
    background: var(--color-brand-dark);
    color: var(--color-cream);
}
.page-hero span {
    font-size: 11px;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--color-golden);
}
.page-hero h1 {
    font-size: clamp(28px, 4vw, 42px);
    margin: 18px 0;
    color: var(--color-white);
    font-weight: 800;
    letter-spacing: -0.5px;
}
.page-hero p {
    line-height: 1.7;
    color: rgba(255, 255, 255, 0.75);
    max-width: 580px;
    margin: 0 auto;
}

/* ====================================================================
   CONTACT (giữ nguyên layout cũ)
   ==================================================================== */
.contact-grid {
    max-width: 1150px;
    margin: 48px auto;
    display: grid;
    grid-template-columns: 1fr 1.1fr;
    gap: 28px;
    padding: 0 24px;
}
.contact-info,
.contact-form {
    padding: 32px;
    border-radius: var(--radius-xl);
}
.contact-info { background: var(--color-brand-dark); color: var(--color-cream); }
.contact-form { background: var(--color-white); border: 1px solid var(--color-gray-200); box-shadow: var(--shadow-sm); }
.contact-form h2 { font-size: 20px; font-weight: 800; margin: 0 0 24px; color: var(--color-charcoal-black); }
fieldset { border: 0; padding: 0; display: flex; flex-direction: column; gap: 16px; }
.contact-info h2 { color: var(--color-white); font-size: 22px; font-weight: 800; margin: 0 0 8px; }
label { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 600; color: var(--color-gray-700); }
input, select, textarea {
    padding: 12px 14px;
    border: 1px solid var(--color-gray-300);
    border-radius: var(--radius-md);
    font: inherit;
    font-size: 14px;
    color: var(--color-charcoal-black);
    background: var(--color-white);
    box-sizing: border-box;
    width: 100%;
    transition: border-color var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out);
}
input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: var(--color-chili-red);
    box-shadow: 0 0 0 3px rgba(215, 25, 32, 0.12);
}
.page-button {
    display: inline-block;
    padding: 12px 22px;
    border: 0;
    border-radius: var(--radius-md);
    background: var(--color-chili-red);
    color: var(--color-white);
    font: 600 14px var(--font-family-base);
    cursor: pointer;
    text-decoration: none;
    transition: background-color var(--dur-fast) var(--ease-out), transform var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out);
}
.page-button:hover { background: var(--color-dark-red); box-shadow: var(--shadow-red); }
.contact-info .page-button { background: var(--color-white); color: var(--color-chili-red); margin-top: 24px; font-weight: 700; }
.contact-info .page-button:hover { background: var(--color-cream-dark); color: var(--color-dark-red); }

/* ====================================================================
   BLOG LIST — TABS + CARDS
   ==================================================================== */
.news-tabs {
    display: flex;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 32px 24px;
}
.news-tabs a {
    padding: 10px 18px;
    border: 1px solid var(--color-gray-300);
    border-radius: var(--radius-full);
    color: var(--color-charcoal-black);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: all var(--dur-fast) var(--ease-out);
}
.news-tabs a:hover { border-color: var(--color-chili-red); color: var(--color-chili-red); }
.news-tabs .router-link-active { background: var(--color-chili-red); color: var(--color-white); border-color: var(--color-chili-red); }

.news-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
    max-width: 1180px;
    padding: 0 24px;
    margin: auto;
}
@media (max-width: 800px) { .news-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 500px) { .news-grid { grid-template-columns: 1fr; } }

.news-card {
    background: var(--color-white);
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    transition: transform var(--dur-base) var(--ease-out), box-shadow var(--dur-base) var(--ease-out);
    position: relative;
    animation: cardEnter 0.4s var(--ease-out) both;
}
.news-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
}
@keyframes cardEnter {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}
.news-link { color: inherit; text-decoration: none; display: block; }

.news-card .cover {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    background: rgba(26, 14, 6, 0.04);
    overflow: hidden;
}
.news-card .cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.5s var(--ease-out);
}
.news-card:hover .cover img { transform: scale(1.05); }
.cover-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--color-chili-red) 0%, #ff574c 100%);
    color: #fff;
    font-size: 36px;
    font-weight: 800;
    letter-spacing: -1px;
}

.news-card .body {
    padding: 18px 20px 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.news-card .meta {
    font-size: 12px;
    color: var(--color-gray-500);
    font-weight: 600;
    display: flex;
    gap: 6px;
    align-items: center;
}
.news-card .meta .dot { color: var(--color-gray-300); }
.news-card .title {
    font-size: 18px;
    line-height: 1.4;
    margin: 4px 0 6px;
    color: var(--color-charcoal-black);
    font-weight: 800;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.news-card .excerpt {
    line-height: 1.6;
    color: var(--color-gray-500);
    font-size: 13.5px;
    margin: 0 0 10px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.news-card .cta {
    color: var(--color-chili-red);
    font-weight: 700;
    font-size: 13px;
    margin-top: 4px;
    transition: transform var(--dur-fast) var(--ease-out);
}
.news-card:hover .cta { transform: translateX(2px); }

/* Badge trên card */
.badge {
    position: absolute;
    top: 12px;
    left: 12px;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(6px);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: var(--radius-full);
    letter-spacing: 0.3px;
    text-transform: uppercase;
    z-index: 2;
}
.badge[data-section='NEWS'] { background: rgba(26, 14, 6, 0.78); }
.badge[data-section='PROMOTION'] { background: var(--color-chili-red); }
.badge[data-section='OPENING'] { background: var(--color-golden); color: var(--color-charcoal-black); }
.badge.small { font-size: 10px; padding: 3px 8px; }
.badge.large { font-size: 12px; padding: 6px 14px; }

/* Skeleton */
.skeleton { pointer-events: none; }
.skeleton .cover {
    background: linear-gradient(90deg, rgba(0,0,0,0.04), rgba(0,0,0,0.08), rgba(0,0,0,0.04));
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
}
.skeleton .body { gap: 10px; padding: 18px 20px; }
.skeleton .line {
    height: 12px;
    background: linear-gradient(90deg, rgba(0,0,0,0.04), rgba(0,0,0,0.08), rgba(0,0,0,0.04));
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    border-radius: 4px;
}
.skeleton .line.big { height: 18px; width: 85%; }
.skeleton .line.short { width: 60%; }
@keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

/* State */
.state, .pagination { text-align: center; padding: 32px; color: var(--color-gray-500); }
.pagination { display: flex; justify-content: center; gap: 16px; align-items: center; }
.pagination button {
    padding: 9px 18px;
    background: var(--color-white);
    border: 1px solid var(--color-gray-300);
    border-radius: var(--radius-md);
    cursor: pointer;
    font: 600 13px var(--font-family-base);
    color: var(--color-charcoal-black);
    transition: border-color var(--dur-fast) var(--ease-out);
}
.pagination button:hover:not(:disabled) { border-color: var(--color-chili-red); color: var(--color-chili-red); }
.pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
.pagination span { font-weight: 700; font-variant-numeric: tabular-nums; }

.retry-btn {
    margin-left: 12px;
    padding: 6px 12px;
    background: var(--color-chili-red);
    color: #fff;
    border: 0;
    border-radius: var(--radius-md);
    cursor: pointer;
    font: 600 12px var(--font-family-base);
}

/* ====================================================================
   ARTICLE DETAIL
   ==================================================================== */
.article-loading {
    text-align: center;
    padding: 80px 24px;
    color: var(--color-gray-500);
    font-size: 15px;
}
.article {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0 24px;
    animation: fadeUp 0.5s var(--ease-out);
}
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}

.article-hero {
    width: 100%;
    aspect-ratio: 16 / 8;
    max-height: 480px;
    border-radius: var(--radius-xl);
    overflow: hidden;
    margin-top: 32px;
    position: relative;
    background: rgba(26, 14, 6, 0.04);
    box-shadow: var(--shadow-md);
}
.article-hero img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    animation: heroZoom 8s ease-out;
}
@keyframes heroZoom {
    from { transform: scale(1.08); }
    to { transform: scale(1); }
}
.hero-fallback {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, var(--color-chili-red) 0%, #ff574c 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 80px;
    font-weight: 800;
    letter-spacing: -2px;
}
.hero-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(0, 0, 0, 0.45) 0%, transparent 35%);
    display: flex;
    align-items: flex-end;
    padding: 22px;
    pointer-events: none;
}

.article-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 56px;
    gap: 32px;
    margin-top: 32px;
}
.article-main { min-width: 0; }

.breadcrumb {
    display: flex;
    gap: 8px;
    align-items: center;
    font-size: 13px;
    color: var(--color-gray-500);
    margin-bottom: 16px;
    font-weight: 600;
}
.breadcrumb a {
    color: var(--color-chili-red);
    text-decoration: none;
    transition: opacity var(--dur-fast) var(--ease-out);
}
.breadcrumb a:hover { opacity: 0.75; }
.breadcrumb .sep { color: var(--color-gray-300); }

.article-title {
    font-size: clamp(26px, 3.6vw, 38px);
    line-height: 1.25;
    margin: 0 0 14px;
    color: var(--color-charcoal-black);
    font-weight: 800;
    letter-spacing: -0.5px;
}
.article-meta {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    font-size: 13px;
    color: var(--color-gray-500);
    margin-bottom: 24px;
}
.article-meta .sep { color: var(--color-gray-300); }
.article-meta .author { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; color: var(--color-charcoal-black); }
.author-avatar {
    display: inline-flex;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--color-chili-red);
    color: #fff;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 800;
}

/* Lead (drop-cap) */
.lead {
    font-size: 18px;
    line-height: 1.7;
    color: var(--color-gray-700);
    margin: 0 0 28px;
    padding: 16px 20px;
    border-left: 4px solid var(--color-chili-red);
    background: rgba(215, 25, 32, 0.04);
    border-radius: 0 var(--radius-md) var(--radius-md) 0;
    font-style: italic;
}

/* Rich content */
.rich-content {
    font-size: 16.5px;
    line-height: 1.85;
    color: var(--color-charcoal-black);
    word-wrap: break-word;
}
.rich-content :deep(h2) {
    font-size: 26px;
    margin: 36px 0 14px;
    font-weight: 800;
    color: var(--color-charcoal-black);
    letter-spacing: -0.3px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--color-chili-red);
}
.rich-content :deep(h3) {
    font-size: 20px;
    margin: 28px 0 12px;
    font-weight: 700;
    color: var(--color-charcoal-black);
}
.rich-content :deep(p) { margin: 0 0 18px; }
.rich-content :deep(ul),
.rich-content :deep(ol) { margin: 0 0 18px; padding-left: 24px; }
.rich-content :deep(li) { margin-bottom: 8px; line-height: 1.75; }
.rich-content :deep(blockquote) {
    margin: 24px 0;
    padding: 16px 20px;
    background: var(--color-cream);
    border-left: 4px solid var(--color-golden);
    border-radius: 0 var(--radius-md) var(--radius-md) 0;
    font-style: italic;
    color: var(--color-gray-700);
}
.rich-content :deep(strong) { color: var(--color-charcoal-black); font-weight: 800; }
.rich-content :deep(a) {
    color: var(--color-chili-red);
    text-decoration: underline;
    text-underline-offset: 3px;
    transition: opacity var(--dur-fast) var(--ease-out);
}
.rich-content :deep(a):hover { opacity: 0.75; }
.rich-content :deep(img) {
    width: 100%;
    height: auto;
    border-radius: var(--radius-lg);
    margin: 24px 0;
    box-shadow: var(--shadow-sm);
}
.rich-content :deep(figure) { margin: 24px 0; }
.rich-content :deep(figcaption) {
    font-size: 13px;
    color: var(--color-gray-500);
    text-align: center;
    margin-top: 8px;
    font-style: italic;
}
.rich-content :deep(code) {
    background: rgba(215, 25, 32, 0.08);
    color: var(--color-dark-red);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: 'SF Mono', Menlo, monospace;
    font-size: 14px;
}
.rich-content :deep(pre) {
    background: var(--color-brand-dark);
    color: #f5f5f5;
    padding: 16px;
    border-radius: var(--radius-md);
    overflow-x: auto;
    margin: 18px 0;
}
.rich-content :deep(hr) {
    border: 0;
    border-top: 1px solid var(--color-gray-200);
    margin: 32px 0;
}

.article-foot {
    margin-top: 40px;
    padding-top: 24px;
    border-top: 1px solid var(--color-gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
}
.back-link {
    color: var(--color-chili-red);
    font-weight: 700;
    text-decoration: none;
    font-size: 14px;
    transition: transform var(--dur-fast) var(--ease-out);
}
.back-link:hover { transform: translateX(-3px); display: inline-block; }

.share-row {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-gray-700);
    font-size: 13px;
    font-weight: 600;
}
.share-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1px solid var(--color-gray-300);
    background: var(--color-white);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--color-charcoal-black);
    transition: all var(--dur-fast) var(--ease-out);
}
.share-btn.zalo {
    width: auto;
    padding: 0 14px;
    border-radius: var(--radius-full);
    background: #0068ff;
    color: #fff;
    border-color: #0068ff;
    font-weight: 700;
    font-size: 13px;
}
.share-btn.zalo:hover { background: #0055d4; }
.share-btn.facebook { color: #1877f2; }
.share-btn.facebook:hover { background: #1877f2; color: #fff; border-color: #1877f2; }
.share-btn.copy:hover { background: var(--color-brand-dark); color: #fff; border-color: var(--color-charcoal-black); }

/* Sidebar share (desktop) */
.article-side {
    position: sticky;
    top: calc(var(--navbar-height) + 24px);
    align-self: start;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding-top: 4px;
}
.side-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.2px;
    color: var(--color-gray-500);
    text-transform: uppercase;
    margin-bottom: 4px;
}
.side-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 1px solid var(--color-gray-300);
    background: var(--color-white);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--color-charcoal-black);
    font-weight: 800;
    transition: all var(--dur-fast) var(--ease-out);
}
.side-btn.facebook:hover { background: #1877f2; color: #fff; border-color: #1877f2; }
.side-btn.zalo { background: #0068ff; color: #fff; border-color: #0068ff; }
.side-btn.zalo:hover { background: #0055d4; }
.side-btn.copy:hover { background: var(--color-brand-dark); color: #fff; border-color: var(--color-charcoal-black); }
@media (max-width: 900px) { .article-side { display: none; } }

/* Related */
.related {
    margin-top: 64px;
    padding: 32px 0 24px;
    border-top: 1px solid var(--color-gray-200);
}
.related-head { margin-bottom: 22px; text-align: center; }
.related-head .eyebrow {
    display: block;
    font-size: 11px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--color-chili-red);
    font-weight: 700;
    margin-bottom: 8px;
}
.related-head h2 {
    font-size: clamp(22px, 3vw, 30px);
    font-weight: 800;
    color: var(--color-charcoal-black);
    margin: 0;
    letter-spacing: -0.5px;
}
.related-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}
.news-card.compact .body { padding: 14px 16px 16px; }
.news-card.compact .title { font-size: 15px; }
@media (max-width: 800px) {
    .related-grid { grid-template-columns: 1fr 1fr; }
    .article-layout { grid-template-columns: 1fr; gap: 0; }
}
@media (max-width: 500px) {
    .related-grid { grid-template-columns: 1fr; }
    .article { padding: 0 16px; }
}

/* Toast */
.toast {
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--color-brand-dark);
    color: #fff;
    padding: 10px 20px;
    border-radius: var(--radius-md);
    font-size: 13px;
    font-weight: 600;
    box-shadow: var(--shadow-md);
    z-index: 100;
    animation: toastIn 0.25s var(--ease-out);
}
@keyframes toastIn { from { opacity: 0; transform: translate(-50%, 20px); } to { opacity: 1; transform: translate(-50%, 0); } }

/* Back-to-top */
.back-top {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: var(--color-chili-red);
    color: #fff;
    border: 0;
    cursor: pointer;
    font-size: 20px;
    font-weight: 700;
    box-shadow: var(--shadow-red);
    z-index: 99;
    transition: transform var(--dur-fast) var(--ease-out);
}
.back-top:hover { transform: translateY(-3px); }
@media (max-width: 500px) {
    .back-top { bottom: 20px; right: 20px; width: 40px; height: 40px; }
}

.error { color: var(--color-error); font-size: 13px; font-weight: 600; }
@media (prefers-reduced-motion: reduce) {
    .news-card,
    .page-button,
    input, select, textarea,
    .article,
    .article-hero img,
    .toast,
    .back-top { transition: none !important; animation: none !important; }
    .news-card:hover { transform: none; }
}
</style>