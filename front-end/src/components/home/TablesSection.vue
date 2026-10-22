<script setup lang="ts">
import { computed } from 'vue'
import { useHomeStore } from '@/stores/home'

const store = useHomeStore()

const tableList = computed(() => {
    const o = store.tables
    if (!o) return [] as Array<{ key: string; label: string; count: number; tone: string }>
    return [
        { key: 'available',       label: 'Bàn trống',          count: o.summary.available, tone: 'green' },
        { key: 'busy',            label: 'Đang phục vụ',       count: o.summary.busy,      tone: 'red' },
        { key: 'reserved',        label: 'Đã đặt trước',       count: o.summary.reserved,  tone: 'amber' },
        { key: 'cleaning',        label: 'Đang dọn',           count: o.by_status.CLEANING,           tone: 'blue' },
        { key: 'waiting_payment', label: 'Chờ thanh toán',     count: o.by_status.WAITING_PAYMENT,    tone: 'purple' },
    ]
})

const percentAvailable = computed(() => {
    const o = store.tables
    if (!o || !o.total) return 0
    return Math.round((o.summary.available / o.total) * 100)
})
</script>

<template>
    <section id="tables" class="hp-section tables">
        <div class="hp-container">
            <h2 class="hp-section-title">Tình trạng bàn</h2>
            <p class="hp-section-subtitle">
                Hệ thống {{ store.tables?.total ?? 0 }} bàn — cập nhật theo thời gian thực
            </p>

            <div v-if="store.tables" class="tables-wrap">
                <div class="tables-summary">
                    <div class="big-stat">
                        <div class="big-number">{{ store.tables.summary.available }}</div>
                        <div class="big-label">bàn đang trống</div>
                    </div>
                    <div class="bar-wrap">
                        <div class="bar">
                            <div
                                class="bar-fill"
                                :style="{ width: percentAvailable + '%' }"
                            ></div>
                        </div>
                        <div class="bar-meta">
                            {{ percentAvailable }}% bàn trống / tổng {{ store.tables.total }} bàn
                        </div>
                    </div>
                </div>

                <div class="tables-list">
                    <div
                        v-for="t in tableList"
                        :key="t.key"
                        class="t-card"
                        :class="['tone-' + t.tone]"
                    >
                        <div class="t-count">{{ t.count }}</div>
                        <div class="t-label">{{ t.label }}</div>
                    </div>
                </div>

                <div class="tables-cta">
                    <a href="/dat-ban" class="hp-btn hp-btn-primary">
                        Đặt bàn ngay
                    </a>
                </div>
            </div>

            <div v-else-if="store.loading" class="hp-skeleton tables-skel"></div>
        </div>
    </section>
</template>

<style scoped>
.tables {
    background: linear-gradient(135deg, #fff5f5 0%, #fff 100%);
}

.tables-wrap {
    background: #fff;
    border-radius: var(--hp-radius);
    padding: 40px 30px;
    box-shadow: var(--hp-shadow);
}

.tables-summary {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 30px;
    align-items: center;
    margin-bottom: 36px;
    padding-bottom: 30px;
    border-bottom: 1px dashed var(--hp-border);
}

.big-stat { text-align: center; }
.big-number {
    font-size: 64px;
    font-weight: 800;
    color: var(--hp-primary);
    line-height: 1;
}
.big-label {
    color: var(--hp-muted);
    margin-top: 6px;
}

.bar-wrap { width: 100%; }
.bar {
    width: 100%;
    height: 14px;
    background: #f1d1d1;
    border-radius: 999px;
    overflow: hidden;
}
.bar-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--hp-primary), var(--hp-secondary));
    transition: width 0.6s ease;
}
.bar-meta {
    color: var(--hp-muted);
    font-size: 14px;
    margin-top: 10px;
}

.tables-list {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-bottom: 30px;
}

.t-card {
    padding: 22px 16px;
    border-radius: 12px;
    text-align: center;
    border-left: 4px solid;
    background: #fafafa;
}
.t-card.tone-green  { border-color: #2a9d8f; }
.t-card.tone-red    { border-color: #e63946; }
.t-card.tone-amber  { border-color: #f4a261; }
.t-card.tone-blue   { border-color: #457b9d; }
.t-card.tone-purple { border-color: #9b5de5; }

.t-count {
    font-size: 30px;
    font-weight: 800;
    color: var(--hp-dark);
}
.t-label {
    font-size: 13px;
    color: var(--hp-muted);
    margin-top: 4px;
}

.tables-cta {
    text-align: center;
}

.tables-skel { height: 280px; }

@media (max-width: 768px) {
    .tables-summary { grid-template-columns: 1fr; }
    .tables-list    { grid-template-columns: repeat(2, 1fr); }
}
</style>
