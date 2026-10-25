<template>
  <div class="consumption-page">
    <header class="manager-heading">
      <div>
        <span class="eyebrow">KHO &amp; NGUYÊN LIỆU</span>
        <h2>Tiêu hao món bán</h2>
        <p>
          Mỗi món đã bán trừ bao nhiêu nguyên liệu, còn tồn bao nhiêu.
          Chênh lệch giữa “theo công thức” và “đã trừ” là hao hụt thực tế —
          ví dụ khách xin thêm bột ớt.
        </p>
      </div>
    </header>

    <!-- Bộ lọc -->
    <div class="filters">
      <label>Từ ngày<input v-model="filters.from" type="date" /></label>
      <label>Đến ngày<input v-model="filters.to" type="date" /></label>
      <div class="view-switch" role="group" aria-label="Cách xem">
        <button
          type="button"
          :class="{ active: filters.view === 'menu' }"
          @click="filters.view = 'menu'"
        >
          Theo món
        </button>
        <button
          type="button"
          :class="{ active: filters.view === 'ingredient' }"
          @click="filters.view = 'ingredient'"
        >
          Theo nguyên liệu
        </button>
      </div>
      <button class="manager-button" :disabled="loading" @click="refresh()">Tra cứu</button>
    </div>

    <p v-if="error" class="manager-notice error" role="alert">{{ error }}</p>
    <p v-if="loading" class="manager-notice" role="status">Đang tải báo cáo…</p>

    <template v-else-if="!error">
      <!-- ====== Theo món ====== -->
      <section v-if="filters.view === 'menu'">
        <p v-if="!menuItems.length" class="manager-notice">
          Chưa có món nào được bán trong khoảng này.
        </p>
        <div v-else class="dish-grid">
          <article v-for="dish in menuItems" :key="dish.menu_item_id" class="dish-card">
            <header>
              <h3>{{ dish.menu_item_name }}</h3>
              <span class="dish-count">{{ dish.total_quantity }} phần · {{ dish.order_count }} lượt</span>
            </header>
            <table class="dish-table">
              <thead>
                <tr>
                  <th>Nguyên liệu</th>
                  <th class="number">Theo công thức</th>
                  <th class="number">Đã trừ</th>
                  <th class="number">Thiếu</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in dish.ingredients" :key="line.ingredient_id">
                  <td>{{ line.ingredient_name }}</td>
                  <td class="number">{{ fmt(line.quantity_required) }} {{ line.unit }}</td>
                  <td class="number">{{ fmt(line.quantity_deducted) }} {{ line.unit }}</td>
                  <td class="number" :class="{ short: line.shortage_qty > 0 }">
                    {{ line.shortage_qty > 0 ? `${fmt(line.shortage_qty)} ${line.unit}` : '—' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </article>
        </div>
      </section>

      <!-- ====== Theo nguyên liệu ====== -->
      <section v-else>
        <p v-if="!ingredients.length" class="manager-notice">
          Chưa có nguyên liệu nào đang sử dụng.
        </p>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Nguyên liệu</th>
                <th class="number">Tồn hiện tại</th>
                <th class="number">Mức tối thiểu</th>
                <th class="number">Đã tiêu hao</th>
                <th class="number">Thiếu</th>
                <th class="number">Còn đủ dùng</th>
                <th>Trạng thái</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="ing in ingredients" :key="ing.ingredient_id">
                <td>
                  <strong>{{ ing.name }}</strong>
                  <small>{{ ing.code }} · {{ ing.unit }}</small>
                </td>
                <td class="number"><strong>{{ fmt(ing.current_stock) }}</strong></td>
                <td class="number">{{ fmt(ing.min_stock_level) }}</td>
                <td class="number">{{ fmt(ing.consumed_qty) }}</td>
                <td class="number" :class="{ short: ing.shortage_qty > 0 }">
                  {{ ing.shortage_qty > 0 ? fmt(ing.shortage_qty) : '—' }}
                </td>
                <td class="number">
                  {{ ing.estimated_days_left === null ? '—' : `${fmt(ing.estimated_days_left)} ngày` }}
                </td>
                <td>
                  <span class="tag" :class="ing.is_low ? 'danger' : 'ok'">
                    {{ ing.is_low ? 'Sắp hết' : 'Còn đủ' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ====== Yêu cầu nhập kho từ bếp ====== -->
      <section class="requests">
        <div class="requests__head">
          <h3>Yêu cầu nhập kho từ bếp</h3>
          <span v-if="openRequestCount" class="requests__badge">{{ openRequestCount }} chờ xử lý</span>
        </div>
        <p v-if="!requests.length" class="manager-notice">
          Bếp chưa gửi yêu cầu nhập kho nào.
        </p>
        <ul v-else class="request-list">
          <li v-for="req in requests" :key="req.id" :class="`request--${req.status.toLowerCase()}`">
            <div class="request__main">
              <div class="request__title">
                <strong>{{ req.menu_item_name || 'Món' }}</strong>
                <span class="request__code">{{ req.code }}</span>
              </div>
              <p class="request__items">
                <span v-for="it in req.items" :key="it.ingredient_id">
                  {{ it.ingredient_name }} — thiếu <strong>{{ fmt(it.shortage_qty) }} {{ it.unit }}</strong>
                </span>
              </p>
              <small>
                Bếp gửi lúc {{ formatTime(req.created_at) }}
                <template v-if="req.requested_by_name"> · {{ req.requested_by_name }}</template>
                <template v-if="req.resolved_by_name"> · Xử lý: {{ req.resolved_by_name }}</template>
              </small>
            </div>
            <div class="request__actions">
              <template v-if="req.status === 'OPEN'">
                <button class="manager-button primary" :disabled="busyId === req.id" @click="resolve(req, 'RESOLVED')">
                  {{ busyId === req.id ? 'Đang lưu…' : 'Đã nhập kho' }}
                </button>
                <button class="manager-button" :disabled="busyId === req.id" @click="resolve(req, 'DISMISSED')">
                  Bỏ qua
                </button>
              </template>
              <span v-else class="tag" :class="req.status === 'RESOLVED' ? 'ok' : ''">
                {{ req.status === 'RESOLVED' ? 'Đã xử lý' : 'Đã bỏ qua' }}
              </span>
            </div>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { consumptionApi, type ConsumptionIngredient, type ConsumptionMenuItem, type StockRequest } from '@/api/consumption'

const menuItems = ref<ConsumptionMenuItem[]>([])
const ingredients = ref<ConsumptionIngredient[]>([])
const requests = ref<StockRequest[]>([])
const loading = ref(false)
const error = ref('')
const busyId = ref<number | null>(null)

const today = new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' })
const weekAgo = () => {
  const d = new Date()
  d.setDate(d.getDate() - 6)
  return d.toLocaleDateString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' })
}

const filters = reactive({ from: weekAgo(), to: today, view: 'menu' as 'menu' | 'ingredient' })

const openRequestCount = computed(() => requests.value.filter(r => r.status === 'OPEN').length)

const fmt = (n: number | null) =>
  n === null || n === undefined ? '—' : Number(n).toLocaleString('vi-VN', { maximumFractionDigits: 2 })

const formatTime = (s: string | null) =>
  s ? new Date(s).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : '—'

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    const data = await consumptionApi.report({
      from: filters.from,
      to: filters.to,
      view: filters.view,
    })
    menuItems.value = data.menu_items
    ingredients.value = data.ingredients
  } catch (e: any) {
    error.value =
      Object.values(e?.response?.data?.errors || {}).flat().join(' ') ||
      e?.response?.data?.message ||
      'Không tải được báo cáo tiêu hao.'
  } finally {
    loading.value = false
  }
}

async function loadRequests() {
  try {
    requests.value = await consumptionApi.stockRequests()
  } catch {
    // Yêu cầu nhập kho là thông tin phụ — không chặn báo cáo chính.
    requests.value = []
  }
}

async function resolve(req: StockRequest, status: 'RESOLVED' | 'DISMISSED') {
  busyId.value = req.id
  try {
    await consumptionApi.resolveStockRequest(
      req.id,
      status,
      status === 'RESOLVED' ? 'Đã nhập kho' : 'Bếp đã xử lý bằng nguồn khác'
    )
    await loadRequests()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Không cập nhật được yêu cầu.'
  } finally {
    busyId.value = null
  }
}

onMounted(async () => {
  await Promise.all([refresh(), loadRequests()])
})
</script>

<style scoped>
.consumption-page { display: flex; flex-direction: column; gap: 18px; }

.filters { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; }
.filters label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }

.view-switch { display: flex; border: 1px solid #dfe7e2; border-radius: 10px; overflow: hidden; background: #fff; }
.view-switch button {
  min-height: 38px; padding: 0 14px; border: 0; background: transparent;
  font-size: 13px; font-weight: 600; color: #52525b; cursor: pointer;
}
.view-switch button.active { background: var(--color-brand-action, #e4263d); color: #fff; }
.view-switch button:focus-visible { outline: 3px solid #b91c1c; outline-offset: -3px; }

/* --- Lưới món --- */
.dish-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
.dish-card {
  background: #fff; border: 1px solid #dfe7e2; border-radius: 14px; padding: 16px;
  display: flex; flex-direction: column; gap: 10px;
}
.dish-card header { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
.dish-card h3 { margin: 0; font-size: 16px; font-weight: 800; letter-spacing: -0.01em; }
.dish-count { font-size: 12px; color: #71717a; white-space: nowrap; }

.dish-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.dish-table th {
  text-align: left; padding: 6px 8px; font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.04em; color: #71717a;
  border-bottom: 1px solid #e7ece9;
}
.dish-table td { padding: 7px 8px; border-bottom: 1px solid #f1f4f2; }
.dish-table tr:last-child td { border-bottom: 0; }
.dish-table .number { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

.table-wrap { overflow-x: auto; background: #fff; border: 1px solid #dfe7e2; border-radius: 12px; }
.table-wrap table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table-wrap th {
  text-align: left; padding: 10px 12px; font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.04em; color: #71717a;
  border-bottom: 1px solid #e7ece9; background: #fafbf9;
}
.table-wrap td { padding: 10px 12px; border-bottom: 1px solid #f1f4f2; }
.table-wrap tr:last-child td { border-bottom: 0; }
.table-wrap td small { display: block; color: #71717a; font-size: 11px; }
.table-wrap .number { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.table-wrap .short { color: #b42318; font-weight: 700; }

/* --- Yêu cầu nhập kho --- */
.requests { display: flex; flex-direction: column; gap: 12px; }
.requests__head { display: flex; align-items: center; gap: 10px; }
.requests__head h3 { margin: 0; font-size: 15px; font-weight: 800; }
.requests__badge {
  padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700;
  background: #fdeceb; color: #b42318;
}

.request-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.request-list li {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  padding: 14px 16px; background: #fff; border: 1px solid #dfe7e2; border-radius: 12px;
  border-left: 3px solid var(--color-brand-action, #e4263d);
}
.request-list li.request--resolved { border-left-color: #16a34a; opacity: 0.72; }
.request-list li.request--dismissed { border-left-color: #a1a1aa; opacity: 0.6; }

.request__title { display: flex; align-items: baseline; gap: 8px; }
.request__title strong { font-size: 14px; }
.request__code { font-size: 11px; color: #71717a; font-variant-numeric: tabular-nums; }
.request__items { margin: 4px 0 0; font-size: 13px; color: #3f3f46; display: flex; flex-wrap: wrap; gap: 4px 14px; }
.request__items strong { color: #b42318; }
.request__main small { display: block; margin-top: 6px; color: #8a8a8a; font-size: 11px; }
.request__actions { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }

@media (max-width: 640px) {
  .request-list li { flex-direction: column; align-items: stretch; gap: 12px; }
  .request__actions { justify-content: flex-end; }
}
</style>
