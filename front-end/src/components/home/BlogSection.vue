<script setup lang="ts">
import { useHomeStore } from '@/stores/home'
import { imageUrl } from '@/utils/image'

const store = useHomeStore()

function formatDate(iso: string | null): string {
    if (!iso) return ''
    return new Date(iso).toLocaleDateString('vi-VN', {
        day: '2-digit', month: '2-digit', year: 'numeric',
    })
}

function categoryLabel(c: string): string {
    const map: Record<string, string> = {
        NEWS: 'Tin tức',
        PROMOTION: 'Khuyến mãi',
        NEW_DISH: 'Món mới',
        EVENT: 'Sự kiện',
        CULINARY: 'Ẩm thực',
    }
    return map[c] || c
}

function defaultCover(category: string): string {
    const map: Record<string, string> = {
        NEWS:      '/storage/blogs/news.jpg',
        PROMOTION: '/storage/blogs/promotion.jpg',
        NEW_DISH:  '/storage/blogs/new-dish.jpg',
        EVENT:     '/storage/blogs/event.jpg',
        CULINARY:  '/storage/blogs/culinary.jpg',
    }
    return imageUrl(map[category] || '/storage/blogs/news.jpg')
}
</script>

<template>
    <section id="blog" class="hp-section blog">
        <div class="hp-container">
            <h2 class="hp-section-title">📰 Tin tức &amp; sự kiện</h2>
            <p class="hp-section-subtitle">
                Cập nhật món mới, khuyến mãi và câu chuyện ẩm thực Hàn Quốc
            </p>

            <div v-if="store.blogs.length" class="blog-grid">
                <article v-for="post in store.blogs" :key="post.id" class="blog-card">
                    <div class="blog-cover">
                        <img
                            :src="imageUrl(post.cover_image) || defaultCover(post.category)"
                            :alt="post.title"
                            loading="lazy"
                        />
                        <span class="blog-cat-tag">{{ categoryLabel(post.category) }}</span>
                    </div>
                    <div class="blog-body">
                        <div class="blog-meta">
                            <span>📅 {{ formatDate(post.published_at) }}</span>
                            <span v-if="post.author_name">✍️ {{ post.author_name }}</span>
                        </div>
                        <h3 class="blog-title">{{ post.title }}</h3>
                        <p v-if="post.excerpt" class="blog-excerpt">
                            {{ post.excerpt }}
                        </p>
                        <a :href="`/blog/${post.slug}`" class="blog-link">
                            Đọc tiếp →
                        </a>
                    </div>
                </article>
            </div>

            <div v-else-if="store.loading" class="blog-grid">
                <div v-for="i in 3" :key="i" class="hp-skeleton blog-skel"></div>
            </div>

            <p v-else class="empty">Chưa có bài viết nào được đăng.</p>
        </div>
    </section>
</template>

<style scoped>
.blog { background: #fff; }

.blog-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

.blog-card {
    background: #fff;
    border-radius: var(--hp-radius);
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
    transition: all 0.3s;
    display: flex;
    flex-direction: column;
}

.blog-card:hover {
    transform: translateY(-6px);
    box-shadow: var(--hp-shadow-hover);
}

.blog-cover {
    position: relative;
    height: 200px;
    overflow: hidden;
}

.blog-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s;
}

.blog-card:hover .blog-cover img {
    transform: scale(1.05);
}

.blog-cat-tag {
    position: absolute;
    top: 14px;
    left: 14px;
    background: var(--hp-primary);
    color: #fff;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
}

.blog-body {
    padding: 20px;
    display: flex;
    flex-direction: column;
    flex: 1;
}

.blog-meta {
    display: flex;
    gap: 14px;
    color: var(--hp-muted);
    font-size: 12px;
    margin-bottom: 10px;
}

.blog-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--hp-dark);
    margin-bottom: 8px;
    line-height: 1.4;
}

.blog-excerpt {
    color: var(--hp-muted);
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 14px;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    flex: 1;
}

.blog-link {
    color: var(--hp-primary);
    font-weight: 600;
    text-decoration: none;
    font-size: 14px;
}

.blog-link:hover { text-decoration: underline; }

.blog-skel { height: 360px; }

.empty { text-align: center; color: var(--hp-muted); padding: 40px; }

@media (max-width: 900px) {
    .blog-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
    .blog-grid { grid-template-columns: 1fr; }
}
</style>
