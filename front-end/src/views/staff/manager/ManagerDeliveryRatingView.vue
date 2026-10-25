<template>
  <div class="rating-page">
    <header class="manager-heading">
      <div>
        <span class="eyebrow">DỊCH VỤ KHÁCH HÀNG</span>
        <h2>Đánh giá dịch vụ giao hàng</h2>
        <p>
          Khách chấm điểm sau mỗi đơn giao hoàn thành. Dùng số liệu này để điều chỉnh
          chất lượng giao và phân công đơn cho từng shipper.
        </p>
      </div>
      <button class="manager-button" :disabled="loading" @click="load()">Làm mới</button>
    </header>

    <p v-if="error" class="manager-notice error" role="alert">{{ error }}</p>
    <p v-if="loading" class="manager-notice" role="status">Đang tải dữ liệu…</p>

    <template v-else-if="!error">
      <!-- ===== Tổng quan ===== -->
      <section class="kpi-row">
        <article class="kpi">
          <span class="kpi-label">Điểm trung bình</span>
          <strong class="kpi-value">{{ summary.average > 0 ? summary.average.toFixed(2) : '—' }}</strong>
          <span class="kpi-unit">trên 5 sao</span>
        </article>
        <article class="kpi">
          <span class="kpi-label">Số đánh giá</span>
          <strong class="kpi-value">{{ summary.count }}</strong>
          <span class="kpi-unit">đơn đã được chấm</span>
        </article>
        <article class="kpi">
          <span class="kpi-label">Đánh giá tích cực</span>
          <strong class="kpi-value">{{ positiveRate }}%</strong>
          <span class="kpi-unit">từ 4 sao trở lên</span>
        </article>
      </section>

      <p v-if="summary.count === 0" class="manager-notice">
        Chưa có đánh giá nào. Khách sẽ thấy mục chấm điểm giao hàng ngay trên trang chi tiết
        đơn sau khi đơn giao hoàn thành.
      </p>

      <template v-else>
        <!-- ===== Phân bố sao ===== -->
        <section class="panel">
          <h3>Phân bố điểm đánh giá</h3>
          <ul class="breakdown">
            <li v-for="star in [5, 4, 3, 2, 1]" :key="star">
              <span class="breakdown-label">{{ star }} sao</span>
              <span class="breakdown-bar">
                <span
                  class="breakdown-fill"
                  :class="star >= 4 ? 'good' : star === 3 ? 'mid' : 'bad'"
                  :style="{ width: barWidth(star) }"
                ></span>
              </span>
              <span class="breakdown-count">{{ summary.breakdown[star] ?? 0 }}</span>
            </li>
          </ul>
        </section>

        <!-- ===== Theo shipper ===== -->
        <section class="panel">
          <h3>Điểm theo shipper</h3>
          <div v-if="!summary.shipper_stats.length" class="manager-notice">
            Chưa có dữ liệu shipper.
          </div>
          <div v-else class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Shipper</th>
                  <th>Số đánh giá</th>
                  <th>Điểm trung bình</th>
                  <th>Đánh giá</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in summary.shipper_stats" :key="s.shipper_id ?? 'none'">
                  <td>
                    <strong>{{ s.shipper_name ?? 'Chưa gán shipper' }}</strong>
                  </td>
                  <td class="number">{{ s.count }}</td>
                  <td class="number">
                    <span class="avg" :class="avgClass(s.average)">{{ s.average.toFixed(2) }}</span>
                  </td>
                  <td>
                    <span class="stars">
                      <span v-for="i in 5" :key="i" :class="{ on: i <= Math.round(s.average) }">★</span>
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ===== Đánh giá mới nhất ===== -->
        <section class="panel">
          <h3>Đánh giá mới nhất</h3>
          <div v-if="!summary.recent.length" class="manager-notice">Chưa có đánh giá nào.</div>
          <ul v-else class="recent">
            <li v-for="r in summary.recent" :key="r.order_id">
              <div class="recent-head">
                <span class="stars">
                  <span v-for="i in 5" :key="i" :class="{ on: i <= r.rating }">★</span>
                </span>
                <strong>{{ r.order_code }}</strong>
                <span class="recent-shipper">{{ r.shipper_name ?? 'Chưa gán shipper' }}</span>
                <span class="recent-date">{{ formatDate(r.rated_at) }}</span>
              </div>
              <p v-if="r.comment" class="recent-comment">“{{ r.comment }}”</p>
            </li>
          </ul>
        </section>
      </template>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { fetchDeliveryRatingSummary } from '@/api/deliveryRating'
import type { DeliveryRatingSummary } from '@/types/deliveryRating'

const summary = ref<DeliveryRatingSummary>({
  count: 0,
  average: 0,
  breakdown: {},
  shipper_stats: [],
  recent: [],
})

const loading = ref(false)
const error = ref('')

const maxCount = computed(() =>
  Math.max(1, ...Object.values(summary.value.breakdown ?? {}).map((n) => Number(n))),
)

const positiveRate = computed(() => {
  const b = summary.value.breakdown ?? {}
  const positive = Number(b[4] ?? 0) + Number(b[5] ?? 0)
  if (summary.value.count === 0) return 0
  return Math.round((positive / summary.value.count) * 100)
})

function barWidth(star: number): string {
  const count = Number(summary.value.breakdown?.[star] ?? 0)
  return `${Math.round((count / maxCount.value) * 100)}%`
}

function avgClass(avg: number): string {
  if (avg >= 4) return 'good'
  if (avg >= 3) return 'mid'
  return 'bad'
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    summary.value = await fetchDeliveryRatingSummary({ limit: 30 })
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Không tải được dữ liệu đánh giá.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.rating-page {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.kpi-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px;
}

.kpi {
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 18px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.kpi-label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #64748b;
}

.kpi-value {
  font-size: 30px;
  font-weight: 800;
  color: var(--color-text);
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}

.kpi-unit {
  font-size: 12px;
  color: #94a3b8;
}

.panel {
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px;
}

.panel h3 {
  margin: 0 0 16px;
  font-size: 15px;
  color: var(--color-text);
}

.breakdown {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.breakdown li {
  display: grid;
  grid-template-columns: 62px 1fr 44px;
  align-items: center;
  gap: 12px;
}

.breakdown-label {
  font-size: 13px;
  color: #475569;
  font-weight: 600;
}

.breakdown-bar {
  height: 10px;
  background: #eef2f0;
  border-radius: 999px;
  overflow: hidden;
}

.breakdown-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  transition: width 0.3s ease;
}

.breakdown-fill.good {
  background: #16a34a;
}

.breakdown-fill.mid {
  background: #f59e0b;
}

.breakdown-fill.bad {
  background: #dc2626;
}

.breakdown-count {
  font-size: 13px;
  font-weight: 700;
  text-align: right;
  color: var(--color-text);
  font-variant-numeric: tabular-nums;
}

.table-wrap {
  overflow-x: auto;
}

table {
  width: 100%;
  min-width: 560px;
  border-collapse: collapse;
  font-size: 13px;
}

th,
td {
  text-align: left;
  padding: 12px 14px;
  border-bottom: 1px solid #edf1ee;
  vertical-align: top;
}

th {
  background: var(--color-brand-soft);
  color: #63736b;
  font-size: 12px;
}

.number {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.avg {
  font-weight: 700;
}

.avg.good {
  color: #166534;
}

.avg.mid {
  color: #b45309;
}

.avg.bad {
  color: #b42318;
}

.stars {
  letter-spacing: 1px;
  color: #d8dee4;
  font-size: 14px;
}

.stars .on {
  color: #f59e0b;
}

.recent {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.recent li {
  border: 1px solid #edf1ee;
  border-radius: 10px;
  padding: 14px;
  background: #fcfdfc;
}

.recent-head {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  font-size: 13px;
}

.recent-shipper {
  color: #64748b;
}

.recent-date {
  margin-left: auto;
  color: #94a3b8;
  font-size: 12px;
}

.recent-comment {
  margin: 8px 0 0;
  font-size: 13px;
  color: #475569;
  font-style: italic;
  line-height: 1.6;
}

@media (max-width: 640px) {
  .breakdown li {
    grid-template-columns: 58px 1fr 36px;
  }
  .recent-date {
    margin-left: 0;
  }
}
</style>
