<script setup lang="ts">
import { useHomeStore } from '@/stores/home'
import { imageUrl } from '@/utils/image'
import RatingBadge from '@/components/review/RatingBadge.vue'

const store = useHomeStore()
</script>

<template>
    <section id="best" class="hp-section best">
        <div class="hp-container">
            <h2 class="hp-section-title">🔥 Bán chạy nhất</h2>
            <p class="hp-section-subtitle">
                Được khách hàng gọi nhiều nhất trong thời gian qua
            </p>

            <div v-if="store.bestSellers.length" class="best-list">
                <article
                    v-for="(item, idx) in store.bestSellers"
                    :key="item.id"
                    class="best-item"
                    :class="{ 'best-item--sold-out': item.is_available === false }"
                    :title="item.sold_out_reason ?? (item.is_available === false ? 'Bếp trưởng vừa báo hết' : undefined)"
                >
                    <div class="best-rank">#{{ idx + 1 }}</div>
                    <div class="best-img-wrap">
                        <img
                            :src="imageUrl(item.image_url)"
                            :alt="item.name"
                            class="best-img"
                            loading="lazy"
                        />
                        <span v-if="item.is_available === false" class="best-sold-badge">
                            Hết món
                        </span>
                        <RatingBadge :menu-item-id="item.id" variant="badge" />
                    </div>
                    <div class="best-info">
                        <span class="best-cat">{{ item.category_name }}</span>
                        <h3 class="best-name">{{ item.name }}</h3>
                        <p v-if="item.description" class="best-desc">
                            {{ item.description }}
                        </p>
                        <div class="best-meta">
                            <span class="best-price">{{ item.price_formatted }}</span>
                            <span v-if="item.sold_count !== undefined" class="best-sold">
                                Đã bán: <strong>{{ item.sold_count }}</strong>
                            </span>
                        </div>
                        <p
                            v-if="item.is_available === false && item.sold_out_reason"
                            class="best-sold-reason"
                        >
                            {{ item.sold_out_reason }}
                        </p>
                    </div>
                </article>
            </div>

            <div v-else-if="store.loading" class="hp-skeleton best-skel"></div>
            <p v-else class="empty">Chưa có dữ liệu bán hàng.</p>

            <div class="best-more">
                <router-link to="/thuc-don" class="hp-btn hp-btn-outline">
                    Xem thêm món →
                </router-link>
            </div>
        </div>
    </section>
</template>

<style scoped>
.best { background: #fff8f4; }

.best-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.best-item {
    background: #fff;
    display: grid;
    grid-template-columns: 60px 140px 1fr;
    gap: 20px;
    padding: 18px;
    border-radius: var(--hp-radius);
    align-items: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    transition: all 0.25s;
}

.best-item:hover {
    transform: translateX(6px);
    box-shadow: var(--hp-shadow-hover);
}

.best-rank {
    font-size: 28px;
    font-weight: 800;
    color: var(--hp-primary);
    text-align: center;
}

.best-img-wrap {
    position: relative;
}

.best-img {
    width: 140px;
    height: 100px;
    object-fit: cover;
    border-radius: 12px;
    display: block;
}

.best-img-wrap :deep(.rating-badge) {
    position: absolute;
    top: 6px;
    left: 6px;
}

.best-cat {
    font-size: 12px;
    color: var(--hp-primary);
    text-transform: uppercase;
}

.best-name {
    font-size: 20px;
    font-weight: 700;
    color: var(--hp-dark);
    margin: 4px 0 6px;
}

.best-desc {
    color: var(--hp-muted);
    font-size: 14px;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.best-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.best-price {
    color: var(--hp-primary);
    font-weight: 800;
    font-size: 17px;
}

.best-sold { font-size: 13px; color: var(--hp-muted); }
.best-sold strong { color: var(--hp-dark); }

/* Sold-out badge (góc trên phải ảnh, cùng tone với MenuCard để đồng bộ) */
.best-img-wrap { position: relative; }

.best-sold-badge {
    position: absolute;
    z-index: 1;
    top: 8px;
    right: 8px;
    padding: 5px 9px;
    border-radius: 999px;
    background: #8b1e24;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.2px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
}

/* Microcopy lý do hết (1 dòng, gọn) */
.best-sold-reason {
    margin: 6px 0 0;
    padding: 6px 10px;
    font-size: 12px;
    color: var(--hp-dark);
    background: #fff5f3;
    border-left: 3px solid var(--hp-primary);
    border-radius: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Item hết: khử hiệu ứng hover translateX */
.best-item--sold-out { opacity: 0.92; }
.best-item--sold-out:hover { transform: none; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); }

.best-skel { height: 120px; }
.empty { text-align: center; color: var(--hp-muted); padding: 30px; }

.best-more {
    text-align: center;
    margin-top: 28px;
}

@media (max-width: 700px) {
    .best-item {
        grid-template-columns: 40px 100px 1fr;
        gap: 12px;
    }
    .best-img { width: 100px; height: 80px; }
    .best-name { font-size: 16px; }
}
</style>
