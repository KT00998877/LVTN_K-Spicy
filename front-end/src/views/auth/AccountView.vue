<script setup lang="ts">
import '@/assets/rank.css'
import { useRealtimeRefresh } from '@/composables/useRealtimeRefresh'
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useOrderStore } from '@/stores/orders'
import type { OrderStatus } from '@/types/orders'

import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import PasswordStrength from '@/components/auth/PasswordStrength.vue'
import { changePassword as apiChangePw } from '@/api/auth'
import { fetchLoyaltyLedger } from '@/api/voucher'
import { fetchMyReviews } from '@/api/review'
import type { LoyaltyEntry } from '@/types/voucher'
import type { Review } from '@/types/review'

import ReviewList from '@/components/review/ReviewList.vue'
import LoyaltyTierPanel from '@/components/loyalty/LoyaltyTierPanel.vue'
import TierBadge from '@/components/loyalty/TierBadge.vue'

type TabKey = 'profile' | 'orders' | 'security' | 'loyalty' | 'tier' | 'reviews'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const orderStore = useOrderStore()
const { user, loading, validationErrors, error } = storeToRefs(authStore)
const { items: orders, loadingList, meta: orderMeta } = storeToRefs(orderStore)

// ===== Tab state =====
const activeTab = ref<TabKey>('profile')

// ===== Profile form =====
const profileForm = reactive({
    name: '',
    full_name: '',
    phone: '',
})
const profileMessage = ref<string | null>(null)
const profileEditing = ref(false)
const fieldErr = (k: 'name' | 'phone' | 'full_name'): string | undefined =>
    validationErrors.value[k]?.[0]

onMounted(() => {
    void authStore.refreshLiveProfile().catch(() => undefined)
    if (user.value) {
        profileForm.name = user.value.name || ''
        profileForm.full_name = user.value.full_name || ''
        profileForm.phone = user.value.phone || ''
    }
})

watch(user, () => { if (!profileEditing.value) onCancelEdit() })

async function onSaveProfile() {
    profileMessage.value = null
    const ok = await authStore.updateProfile({
        name: profileForm.name.trim(),
        full_name: profileForm.full_name.trim() || null,
        phone: profileForm.phone.trim() || null,
    })
    if (ok) {
        profileMessage.value = 'Cập nhật thông tin thành công!'
        profileEditing.value = false
        // sync lại form từ store
        if (user.value) {
            profileForm.name = user.value.name || ''
            profileForm.full_name = user.value.full_name || ''
            profileForm.phone = user.value.phone || ''
        }
    }
}

function onCancelEdit() {
    if (user.value) {
        profileForm.name = user.value.name || ''
        profileForm.full_name = user.value.full_name || ''
        profileForm.phone = user.value.phone || ''
    }
    profileEditing.value = false
}

// ===== Password form =====
const pwForm = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
})
const showCurrent = ref(false)
const showNew = ref(false)
const pwMessage = ref<string | null>(null)
const pwError = ref<string | null>(null)
const passwordErr = computed(() =>
    pwForm.password !== pwForm.password_confirmation && pwForm.password_confirmation
        ? 'Mật khẩu xác nhận không khớp'
        : '',
)

async function onChangePassword() {
    pwMessage.value = null
    pwError.value = null
    const ok = await apiChangePw({
        current_password: pwForm.current_password,
        password: pwForm.password,
        password_confirmation: pwForm.password_confirmation,
    })
        .then(() => true)
        .catch((err: { response?: { data?: { message?: string } } }) => {
            pwError.value = err.response?.data?.message ?? 'Đổi mật khẩu thất bại.'
            return false
        })
    if (ok) {
        pwMessage.value = 'Đổi mật khẩu thành công!'
        pwForm.current_password = ''
        pwForm.password = ''
        pwForm.password_confirmation = ''
    }
}

// ===== Logout =====
async function onLogout() {
    if (!confirm('Bạn có chắc muốn đăng xuất?')) return
    await authStore.logout()
    router.replace('/')
}

// ===== Orders =====
const orderFilter = ref<OrderStatus | ''>('')

async function loadOrders() {
    await orderStore.fetchList({
        status: (orderFilter.value || undefined) as OrderStatus | undefined,
        per_page: 10,
        page: 1,
    })
}

function onFilterChange() {
    loadOrders()
}

function viewOrderDetail(id: number) {
    router.push({ name: 'order-detail', params: { id } })
}

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
        maximumFractionDigits: 0,
    }).format(n)
}

function formatDate(iso: string | null): string {
    if (!iso) return '—'
    const d = new Date(iso)
    return d.toLocaleString('vi-VN', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

/** Map trạng thái đơn → nhãn + class màu */
const STATUS_LABELS: Record<OrderStatus, { label: string; cls: string; icon: string }> = {
    PENDING:             { label: 'Chờ xác nhận',        cls: 'pill pill-amber', icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>' },
    CONFIRMED:           { label: 'Đã xác nhận',          cls: 'pill pill-blue',  icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>' },
    PREPARING:           { label: 'Đang chế biến',         cls: 'pill pill-orange',icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>' },
    READY:               { label: 'Sẵn sàng',              cls: 'pill pill-cyan',  icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 13.87A4 4 0 0 1 7.41 6a5.11 5.11 0 0 1 1.05-1.54 5 5 0 0 1 7.08 0A5.11 5.11 0 0 1 16.59 6 4 4 0 0 1 18 13.87V21H6Z"></path><line x1="6" y1="17" x2="18" y2="17"></line></svg>' },
    READY_NOTIFY_SHIPPER:{ label: 'Sẵn sàng giao',        cls: 'pill pill-cyan',  icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 17H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-1"></path><polygon points="12 15 17 21 7 21 12 15"></polygon></svg>' },
    DELIVERING:          { label: 'Đang giao hàng',       cls: 'pill pill-blue',  icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>' },
    DELIVERED:           { label: 'Chờ bạn xác nhận',    cls: 'pill pill-cyan',  icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>' },
    SERVED:              { label: 'Đã phục vụ',            cls: 'pill pill-green', icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path><line x1="6" y1="1" x2="6" y2="4"></line><line x1="10" y1="1" x2="10" y2="4"></line><line x1="14" y1="1" x2="14" y2="4"></line></svg>' },
    COMPLETED:           { label: 'Hoàn thành',            cls: 'pill pill-green', icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>' },
    CANCELLED:           { label: 'Đã hủy',               cls: 'pill pill-red',   icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>' },
}

const TYPE_LABELS = {
    DINE_IN: 'Tại bàn',
    TAKEAWAY: 'Mang đi',
    ONLINE: 'Online',
} as const

// ===== Loyalty =====
const loyaltyEntries = ref<LoyaltyEntry[]>([])
const loyaltyMeta = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 })
const loyaltyLoading = ref(false)
const loyaltyError = ref<string | null>(null)

async function loadLoyalty(page = 1) {
    loyaltyLoading.value = true
    loyaltyError.value = null
    try {
        const data = await fetchLoyaltyLedger(page, 20)
        loyaltyEntries.value = data.items
        loyaltyMeta.value = data.meta
    } catch (e: { response?: { data?: { message?: string } } } | any) {
        loyaltyError.value = e?.response?.data?.message ?? 'Không tải được lịch sử điểm.'
    } finally {
        loyaltyLoading.value = false
    }
}

// ===== My Reviews =====
const myReviews = ref<Review[]>([])
const reviewsMeta = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 })
const reviewsLoading = ref(false)
const reviewsError = ref<string | null>(null)
const reviewsRefreshKey = ref(0)

async function loadMyReviews(page = 1) {
    reviewsLoading.value = true
    reviewsError.value = null
    try {
        const data = await fetchMyReviews(page, 10)
        myReviews.value = data.items
        reviewsMeta.value = data.meta
    } catch (e: any) {
        reviewsError.value = e?.response?.data?.message ?? 'Không tải được danh sách đánh giá.'
    } finally {
        reviewsLoading.value = false
    }
}

function reloadMyReviews() {
    reviewsRefreshKey.value++
    loadMyReviews(1)
}

const LOYALTY_TYPE_LABELS: Record<LoyaltyEntry['type'], { label: string; cls: string }> = {
    EARN:   { label: 'Cộng điểm',  cls: 'pill pill-green' },
    REDEEM: { label: 'Dùng điểm',  cls: 'pill pill-orange' },
    ADJUST: { label: 'Điều chỉnh', cls: 'pill pill-blue' },
}

// Auto-load orders khi user click vào tab orders
async function setTab(t: TabKey) {
    activeTab.value = t
    if (t === 'orders' && orders.value.length === 0 && !orderMeta.value.total) {
        await loadOrders()
    }
    if (t === 'loyalty' && loyaltyEntries.value.length === 0 && !loyaltyMeta.value.total) {
        await loadLoyalty()
    }
    if (t === 'reviews' && myReviews.value.length === 0 && !reviewsMeta.value.total) {
        await loadMyReviews()
    }
}

// Đọc query ?tab=orders để auto-open tab khi back từ OrderDetail
onMounted(() => {
    const tabFromQuery = route.query.tab as TabKey | undefined
    if (tabFromQuery === 'orders' || tabFromQuery === 'profile' || tabFromQuery === 'security' || tabFromQuery === 'loyalty' || tabFromQuery === 'tier' || tabFromQuery === 'reviews') {
        activeTab.value = tabFromQuery
    }
    if (activeTab.value === 'orders' && orders.value.length === 0 && !orderMeta.value.total) {
        loadOrders()
    }
    if (activeTab.value === 'loyalty' && loyaltyEntries.value.length === 0 && !loyaltyMeta.value.total) {
        loadLoyalty()
    }
    if (activeTab.value === 'reviews' && myReviews.value.length === 0 && !reviewsMeta.value.total) {
        loadMyReviews()
    }
})

// Watch query change (trường hợp navigate trong cùng route)
watch(() => route.query.tab, (newTab) => {
    if (newTab === 'orders' || newTab === 'profile' || newTab === 'security' || newTab === 'loyalty' || newTab === 'tier' || newTab === 'reviews') {
        activeTab.value = newTab as TabKey
        if (newTab === 'loyalty' && loyaltyEntries.value.length === 0 && !loyaltyMeta.value.total) {
            loadLoyalty()
        }
        if (newTab === 'reviews' && myReviews.value.length === 0 && !reviewsMeta.value.total) {
            loadMyReviews()
        }
    }
})
useRealtimeRefresh(() => orderStore.fetchList(orderStore.lastQuery, false, true), () => activeTab.value === 'orders' && !orderStore.loadingList)
</script>

<template>
    <NavbarHome />

    <main class="account-page rank-surface" :class="user?.tier ? `rank--${user.tier.code.toLowerCase()}` : undefined">
        <div class="account-container">
            <!-- Header -->
            <div class="account-header">
                <div class="account-avatar">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                </div>
                <div class="account-title">
                    <h1>{{ user?.full_name || user?.name }}</h1>
                    <p>{{ user?.email }}</p>
                </div>
                <div v-if="(user?.loyalty_points ?? 0) > 0" class="loyalty-pill" role="status" aria-label="Điểm thưởng">
                    <span class="loyalty-star" aria-hidden="true">★</span>
                    <div>
                        <strong>{{ user?.loyalty_points }}</strong>
                        <small>điểm thưởng</small>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="account-tabs">
                <button
                    type="button"
                    class="tab-btn"
                    :class="{ active: activeTab === 'profile' }"
                    @click="setTab('profile')"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    Thông tin cá nhân
                </button>
                <button
                    type="button"
                    class="tab-btn"
                    :class="{ active: activeTab === 'orders' }"
                    @click="setTab('orders')"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Đơn hàng của tôi
                </button>
                <button
                    type="button"
                    class="tab-btn"
                    :class="{ active: activeTab === 'security' }"
                    @click="setTab('security')"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    Bảo mật
                </button>
                <button
                    type="button"
                    class="tab-btn"
                    :class="{ active: activeTab === 'loyalty' }"
                    @click="setTab('loyalty')"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    Điểm thưởng
                </button>
                <button
                    type="button"
                    class="tab-btn"
                    :class="{ active: activeTab === 'tier' }"
                    @click="setTab('tier')"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                    Hạng thành viên
                    <TierBadge v-if="user && user.tier" :tier="user.tier" size="xs" />
                </button>
                <button
                    type="button"
                    class="tab-btn"
                    :class="{ active: activeTab === 'reviews' }"
                    @click="setTab('reviews')"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon><line x1="12" y1="17" x2="12" y2="17.01"></line></svg>
                    Đánh giá của tôi
                </button>
            </div>

            <!-- ============== TAB: PROFILE ============== -->
            <section v-show="activeTab === 'profile'" class="account-card">
                <div class="card-head">
                    <h2>Thông tin tài khoản</h2>
                    <button
                        v-if="!profileEditing"
                        type="button"
                        class="btn-edit"
                        @click="profileEditing = true"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        Chỉnh sửa
                    </button>
                </div>

                <div v-if="profileMessage" class="alert alert-success">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <span>{{ profileMessage }}</span>
                </div>
                <div v-if="error && activeTab === 'profile'" class="alert alert-error">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <span>{{ error }}</span>
                </div>

                <form v-if="profileEditing" class="au-form" @submit.prevent="onSaveProfile">
                    <div class="au-field">
                        <label class="au-label">Họ tên <span class="req">*</span></label>
                        <input
                            v-model="profileForm.name"
                            type="text"
                            class="au-input"
                            :class="{ 'has-error': !!fieldErr('name') }"
                            maxlength="100"
                            required
                        />
                        <span v-if="fieldErr('name')" class="au-error-msg">{{ fieldErr('name') }}</span>
                    </div>
                    <div class="au-field">
                        <label class="au-label">Họ tên đầy đủ</label>
                        <input
                            v-model="profileForm.full_name"
                            type="text"
                            class="au-input"
                            :class="{ 'has-error': !!fieldErr('full_name') }"
                            maxlength="100"
                            placeholder="Nguyễn Văn A"
                        />
                        <span v-if="fieldErr('full_name')" class="au-error-msg">{{ fieldErr('full_name') }}</span>
                    </div>
                    <div class="au-field">
                        <label class="au-label">Số điện thoại</label>
                        <input
                            v-model="profileForm.phone"
                            type="tel"
                            class="au-input"
                            :class="{ 'has-error': !!fieldErr('phone') }"
                            placeholder="0901234567"
                        />
                        <span v-if="fieldErr('phone')" class="au-error-msg">{{ fieldErr('phone') }}</span>
                        <span v-else class="au-hint">Định dạng: 0xxxxxxxxx hoặc +84xxxxxxxxx</span>
                    </div>
                    <div class="au-field">
                        <label class="au-label">Email</label>
                        <input
                            type="email"
                            class="au-input"
                            :value="user?.email"
                            disabled
                        />
                        <span class="au-hint">Email không thể thay đổi.</span>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" @click="onCancelEdit">
                            Hủy
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="loading">
                            {{ loading ? 'Đang lưu...' : 'Lưu thay đổi' }}
                        </button>
                    </div>
                </form>

                <div v-else class="info-grid">
                    <div class="info-item">
                        <label>Họ tên</label>
                        <span>{{ user?.name || '—' }}</span>
                    </div>
                    <div class="info-item">
                        <label>Họ tên đầy đủ</label>
                        <span>{{ user?.full_name || '—' }}</span>
                    </div>
                    <div class="info-item">
                        <label>Email</label>
                        <span>{{ user?.email }}</span>
                    </div>
                    <div class="info-item">
                        <label>Số điện thoại</label>
                        <span>{{ user?.phone || '—' }}</span>
                    </div>
                    <div class="info-item">
                        <label>Trạng thái</label>
                        <span class="status-badge" :class="user?.is_active ? 'active' : 'inactive'">
                            {{ user?.is_active ? 'Hoạt động' : 'Đã khóa' }}
                        </span>
                    </div>
                    <div class="info-item">
                        <label>Ngày tham gia</label>
                        <span>{{ user?.created_at ? new Date(user.created_at).toLocaleDateString('vi-VN') : '—' }}</span>
                    </div>
                </div>
            </section>

            <!-- ============== TAB: ORDERS ============== -->
            <section v-show="activeTab === 'orders'" class="account-card">
                <div class="card-head">
                    <h2>Đơn hàng của tôi</h2>
                    <button class="btn-edit" @click="loadOrders" :disabled="loadingList">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                        Tải lại
                    </button>
                </div>

                <div class="order-filter">
                    <button
                        type="button"
                        class="filter-chip"
                        :class="{ active: orderFilter === '' }"
                        @click="orderFilter = ''; onFilterChange()"
                    >
                        Tất cả
                    </button>
                    <button
                        v-for="key in (['PENDING','CONFIRMED','PREPARING','READY','SERVED','DELIVERING','DELIVERED','COMPLETED','CANCELLED'] as OrderStatus[])"
                        :key="key"
                        type="button"
                        class="filter-chip"
                        :class="{ active: orderFilter === key }"
                        @click="orderFilter = key; onFilterChange()"
                    >
                        {{ STATUS_LABELS[key].label }}
                    </button>
                </div>

                <div v-if="loadingList && orders.length === 0" class="order-state">
                    <div class="spinner"></div>
                    <p>Đang tải đơn hàng...</p>
                </div>

                <div v-else-if="!orderMeta.total" class="order-state empty">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M16 16s-1.5-2-4-2-4 2-4 2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg>
                    <h3>Chưa có đơn hàng nào</h3>
                    <p>Bạn chưa đặt món nào. Hãy khám phá thực đơn của chúng tôi!</p>
                    <router-link to="/thuc-don" class="btn btn-primary" style="text-decoration:none; display:inline-block; margin-top: 16px;">
                        Khám phá thực đơn
                    </router-link>
                </div>

                <div v-else class="order-list">
                    <article
                        v-for="o in orders"
                        :key="o.id"
                        class="order-card"
                        @click="viewOrderDetail(o.id)"
                    >
                        <div class="order-card-head">
                            <div class="order-id">
                                <span class="order-code">#{{ o.code }}</span>
                                <span class="order-date">{{ formatDate(o.placed_at) }}</span>
                            </div>
                            <div :class="STATUS_LABELS[o.status].cls" v-html="STATUS_LABELS[o.status].icon + ' ' + STATUS_LABELS[o.status].label">
                            </div>
                        </div>
                        <div class="order-card-body">
                            <div class="order-meta">
                                <span class="meta-tag">{{ TYPE_LABELS[o.order_type] }}</span>
                                <span class="meta-tag">{{ o.item_count }} món</span>
                            </div>
                            <div class="order-total">
                                {{ formatCurrency(o.total_amount) }}
                            </div>
                        </div>
                        <div class="order-card-foot">
                            <span>Nhấn để xem chi tiết</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </div>
                    </article>
                </div>

                <div v-if="orderMeta.last_page > 1" class="order-pagination">
                    <span>Trang {{ orderMeta.current_page }} / {{ orderMeta.last_page }} · Tổng {{ orderMeta.total }} đơn</span>
                </div>
            </section>

            <!-- ============== TAB: SECURITY ============== -->
            <section v-show="activeTab === 'security'" class="account-card">
                <div class="card-head">
                    <h2>Đổi mật khẩu</h2>
                </div>
                
                <div v-if="pwMessage" class="alert alert-success">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <span>{{ pwMessage }}</span>
                </div>
                <div v-if="pwError" class="alert alert-error">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <span>{{ pwError }}</span>
                </div>

                <form class="au-form" @submit.prevent="onChangePassword">
                    <div class="au-field">
                        <label class="au-label">Mật khẩu hiện tại</label>
                        <div class="au-input-wrap">
                            <input
                                v-model="pwForm.current_password"
                                :type="showCurrent ? 'text' : 'password'"
                                class="au-input"
                                style="padding-right: 44px;"
                                autocomplete="current-password"
                                required
                            />
                            <button type="button" class="au-pw-toggle" @click="showCurrent = !showCurrent">
                                <svg v-if="showCurrent" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                                <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>
                    <div class="au-field">
                        <label class="au-label">Mật khẩu mới</label>
                        <div class="au-input-wrap">
                            <input
                                v-model="pwForm.password"
                                :type="showNew ? 'text' : 'password'"
                                class="au-input"
                                style="padding-right: 44px;"
                                autocomplete="new-password"
                                required
                            />
                            <button type="button" class="au-pw-toggle" @click="showNew = !showNew">
                                <svg v-if="showNew" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                                <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                        <PasswordStrength :password="pwForm.password" />
                    </div>
                    <div class="au-field">
                        <label class="au-label">Xác nhận mật khẩu mới</label>
                        <input
                            v-model="pwForm.password_confirmation"
                            type="password"
                            class="au-input"
                            :class="{ 'has-error': !!passwordErr }"
                            autocomplete="new-password"
                            required
                        />
                        <span v-if="passwordErr" class="au-error-msg">{{ passwordErr }}</span>
                    </div>
                    <button type="submit" class="btn btn-primary" :disabled="loading || !!passwordErr" style="margin-top: 16px;">
                        Lưu mật khẩu mới
                    </button>
                </form>

                <div class="card-head" style="margin-top: 48px; border-top: 1px solid var(--color-gray-200); padding-top: 32px;">
                    <h2>Phiên đăng nhập</h2>
                </div>
                <p style="color: var(--color-gray-500); margin: 0 0 20px; font-size: 15px;">
                    Đăng xuất sẽ thu hồi phiên làm việc hiện tại trên thiết bị này.
                </p>
                <button class="btn btn-danger-outline" @click="onLogout">
                    Đăng xuất khỏi tài khoản
                </button>
            </section>
        <!-- ============== TAB: LOYALTY ============== -->
            <section v-show="activeTab === 'loyalty'" class="account-card">
                <div class="card-head">
                    <h2>Điểm thưởng của bạn</h2>
                    <button class="btn-edit" @click="loadLoyalty(1)" :disabled="loyaltyLoading">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                        Tải lại
                    </button>
                </div>

                <div class="loyalty-summary">
                    <div>
                        <div class="loyalty-current">
                            <span class="loyalty-current-num">{{ user?.loyalty_points ?? 0 }}</span>
                            <span class="loyalty-current-label">điểm hiện có</span>
                        </div>
                        <p class="loyalty-help">
                            Mỗi đơn <strong>10.000đ</strong> được <strong>1 điểm</strong>. Dùng điểm tại bước thanh toán:
                            1 điểm = <strong>1.000đ</strong>, tối đa 30% tiền món.
                        </p>
                    </div>
                </div>

                <h3 class="loyalty-subhead">Lịch sử giao dịch</h3>

                <div v-if="loyaltyLoading && loyaltyEntries.length === 0" class="order-state">
                    <div class="spinner"></div>
                    <p>Đang tải lịch sử điểm...</p>
                </div>

                <div v-else-if="loyaltyError" class="alert alert-error">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <span>{{ loyaltyError }}</span>
                </div>

                <div v-else-if="!loyaltyMeta.total" class="order-state empty">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    <h3>Chưa có giao dịch điểm nào</h3>
                    <p>Bạn sẽ nhận điểm khi xác nhận "Tôi đã nhận hàng" cho đơn giao/mang đi.</p>
                </div>

                <div v-else class="loyalty-list">
                    <article
                        v-for="entry in loyaltyEntries"
                        :key="entry.id"
                        class="loyalty-item"
                    >
                        <div class="loyalty-item-left">
                            <span :class="LOYALTY_TYPE_LABELS[entry.type].cls">
                                {{ LOYALTY_TYPE_LABELS[entry.type].label }}
                            </span>
                            <div>
                                <p class="loyalty-item-reason">{{ entry.reason || '—' }}</p>
                                <p class="loyalty-item-date">{{ entry.created_at ? new Date(entry.created_at).toLocaleString('vi-VN') : '—' }}</p>
                            </div>
                        </div>
                        <div class="loyalty-item-right">
                            <span :class="entry.points >= 0 ? 'loyalty-positive' : 'loyalty-negative'">
                                {{ entry.points >= 0 ? '+' : '' }}{{ entry.points }}
                            </span>
                            <span class="loyalty-balance-after">Sau GD: {{ entry.balance_after }}</span>
                        </div>
                    </article>

                    <div v-if="loyaltyMeta.last_page > 1" class="loyalty-pagination">
                        <button
                            v-for="p in loyaltyMeta.last_page"
                            :key="p"
                            type="button"
                            class="loyalty-page-btn"
                            :class="{ active: loyaltyMeta.current_page === p }"
                            :disabled="loyaltyLoading"
                            @click="loadLoyalty(p)"
                        >
                            {{ p }}
                        </button>
                    </div>
                </div>
            </section>

        <!-- ============== TAB: TIER (HẠNG THÀNH VIÊN) ============== -->
            <section v-show="activeTab === 'tier'" class="account-card">
                <LoyaltyTierPanel />
            </section>

            <!-- ============== TAB: REVIEWS ============== -->
            <section v-show="activeTab === 'reviews'" class="account-card">
                <div class="card-head">
                    <h2>Đánh giá của tôi</h2>
                    <button class="btn-edit" @click="reloadMyReviews" :disabled="reviewsLoading">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                        Tải lại
                    </button>
                </div>

                <p style="color: var(--color-gray-500); margin: 0 0 24px; font-size: 14px;">
                    Những đánh giá bạn đã viết cho các món ăn sau khi hoàn thành đơn hàng.
                </p>

                <div v-if="reviewsLoading && myReviews.length === 0" class="order-state">
                    <div class="spinner"></div>
                    <p>Đang tải đánh giá...</p>
                </div>

                <div v-else-if="reviewsError" class="alert alert-error">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <span>{{ reviewsError }}</span>
                </div>

                <div v-else-if="!reviewsMeta.total" class="order-state empty">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    <h3>Chưa có đánh giá nào</h3>
                    <p>Sau khi hoàn thành đơn hàng, bạn có thể đánh giá món ăn từ trang thực đơn.</p>
                    <router-link to="/thuc-don" class="btn btn-primary" style="text-decoration:none; display:inline-block; margin-top: 16px;">
                        Khám phá thực đơn
                    </router-link>
                </div>

                <div v-else :key="reviewsRefreshKey">
                    <ReviewList
                        :items="myReviews"
                        :show-summary="false"
                        :show-menu-item-info="true"
                        empty-text="Bạn chưa viết đánh giá nào."
                    />

                    <div v-if="reviewsMeta.last_page > 1" class="loyalty-pagination">
                        <button
                            v-for="p in reviewsMeta.last_page"
                            :key="p"
                            type="button"
                            class="loyalty-page-btn"
                            :class="{ active: reviewsMeta.current_page === p }"
                            :disabled="reviewsLoading"
                            @click="loadMyReviews(p)"
                        >
                            {{ p }}
                        </button>
                    </div>
                </div>
            </section>

        </div>
    </main>

    <FooterHome />
</template>

<style scoped>
.account-page {
    min-height: 80vh;
    padding: var(--navbar-height) 20px 80px;
}
@media (max-width: 768px) {
    .account-page {
        padding: var(--navbar-height-mobile) 20px 80px;
    }
}

.account-container {
    max-width: 900px;
    margin: 0 auto;
}

.account-page .account-container { position: relative; z-index: 1; }
.account-page[class*="rank--"] .account-title h1 { color: var(--rank-text); }
.account-page[class*="rank--"] .account-title p { color: var(--rank-muted); }
.account-page[class*="rank--"] .account-tabs { background: var(--color-surface); border-radius: var(--radius-lg); padding: 12px; }
.account-page .account-header { padding-top: 32px; }
.account-title { min-width: 0; }
.account-title h1, .account-title p { overflow-wrap: anywhere; }

/* Header */
.account-header {
    display: flex;
    align-items: center;
    gap: 24px;
    margin-bottom: 32px;
}

.account-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: var(--color-chili-red);
    color: var(--color-white);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(215, 25, 32, 0.3);
}

.account-title h1 {
    font-size: 32px;
    color: var(--color-charcoal-black);
    margin: 0 0 8px;
    font-weight: 800;
    letter-spacing: -1px;
}

.account-title p {
    color: var(--color-gray-500);
    margin: 0;
    font-size: 16px;
}

/* Loyalty points pill */
.loyalty-pill {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 12px;
    padding: 10px 18px;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 1px solid #f59e0b;
    border-radius: 999px;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
}
.loyalty-star {
    font-size: 24px;
    color: #f59e0b;
    line-height: 1;
}
.loyalty-pill strong {
    display: block;
    font-size: 18px;
    color: #92400e;
    font-weight: 800;
    line-height: 1;
}
.loyalty-pill small {
    color: #92400e;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Tabs */
.account-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 32px;
    background: var(--color-white);
    padding: 8px;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm);
    overflow-x: auto;
    border: 1px solid var(--color-gray-100);
}

.tab-btn {
    flex: 1;
    min-width: max-content;
    padding: 12px 24px;
    border: 0;
    background: transparent;
    border-radius: var(--radius-lg);
    font-size: 15px;
    font-weight: 600;
    color: var(--color-gray-500);
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.tab-btn:hover {
    background: var(--color-gray-50);
    color: var(--color-charcoal-black);
}

.tab-btn.active {
    background: var(--color-brand-primary);
    color: var(--color-white);
    box-shadow: var(--shadow-sm);
}

/* Cards */
.account-card {
    background: var(--color-white);
    border-radius: var(--radius-2xl);
    padding: 40px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--color-gray-100);
}

.card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
}

.card-head h2 {
    font-size: 24px;
    color: var(--color-charcoal-black);
    margin: 0;
    font-weight: 800;
}

.btn-edit {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: var(--color-gray-100);
    color: var(--color-charcoal-black);
    border: none;
    border-radius: var(--radius-full);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-edit:hover {
    background: var(--color-gray-200);
}

.btn-danger-outline {
    background: transparent;
    border: 1px solid var(--color-chili-red);
    color: var(--color-chili-red);
    padding: 12px 24px;
    border-radius: var(--radius-full);
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-danger-outline:hover {
    background: var(--color-chili-red);
    color: var(--color-white);
}

/* Profile info grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.info-item label {
    font-size: 13px;
    color: var(--color-gray-500);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.info-item span {
    font-size: 16px;
    color: var(--color-charcoal-black);
    font-weight: 600;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: var(--radius-full);
    font-size: 14px;
    font-weight: 600;
    width: fit-content;
}

.status-badge.active {
    background: rgba(22, 163, 74, 0.1);
    color: #16a34a;
}

.status-badge.inactive {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
}

/* Alerts */
.alert {
    padding: 16px;
    border-radius: var(--radius-lg);
    font-size: 15px;
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 24px;
    font-weight: 500;
}

.alert-success { 
    background: rgba(22, 163, 74, 0.1); 
    color: #166534; 
}
.alert-error { 
    background: rgba(220, 38, 38, 0.1); 
    color: #991b1b; 
}

/* Form */
.au-form { 
    display: flex; 
    flex-direction: column; 
    gap: 20px; 
    max-width: 600px;
}

.au-field { 
    display: flex; 
    flex-direction: column; 
    gap: 8px; 
}

.au-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-charcoal-black);
}

.req { color: var(--color-chili-red); }

.au-input {
    padding: 14px 16px;
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    font-size: 15px;
    transition: all 0.2s;
    background: var(--color-white);
    color: var(--color-charcoal-black);
    font-family: inherit;
}

.au-input:focus { 
    outline: none; 
    border-color: var(--color-chili-red); 
    box-shadow: 0 0 0 4px rgba(215, 25, 32, 0.1);
}
.au-input:disabled { 
    background: var(--color-gray-50); 
    color: var(--color-gray-500); 
}

.au-input.has-error { border-color: #dc2626; }

.au-error-msg {
    color: #dc2626;
    font-size: 13px;
    margin-top: 4px;
}

.au-hint {
    color: var(--color-gray-500);
    font-size: 13px;
    margin-top: 4px;
}

.au-input-wrap { position: relative; }

.au-pw-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: 0;
    cursor: pointer;
    color: var(--color-gray-400);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px;
    transition: color 0.2s;
}

.au-pw-toggle:hover {
    color: var(--color-charcoal-black);
}

.form-actions {
    display: flex;
    gap: 16px;
    margin-top: 16px;
}

.btn {
    padding: 14px 28px;
    border-radius: var(--radius-full);
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
}

.btn-primary {
    background: var(--color-chili-red);
    color: var(--color-white);
}

.btn-primary:hover {
    box-shadow: 0 4px 12px rgba(215, 25, 32, 0.3);
}

.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    box-shadow: none;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--color-gray-300);
    color: var(--color-charcoal-black);
}

.btn-outline:hover {
    background: var(--color-gray-50);
}

/* Order filter */
.order-filter {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

.filter-chip {
    padding: 8px 16px;
    border: 1px solid var(--color-gray-200);
    background: var(--color-white);
    border-radius: var(--radius-full);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    color: var(--color-gray-500);
}

.filter-chip:hover { 
    background: var(--color-gray-50); 
    color: var(--color-charcoal-black);
}

.filter-chip.active {
    background: var(--color-brand-primary);
    color: var(--color-white);
    border-color: var(--color-charcoal-black);
}

/* Spinner */
.spinner {
    width: 40px;
    height: 40px;
    border: 3px solid var(--color-gray-200);
    border-top-color: var(--color-chili-red);
    border-radius: 50%;
    margin: 0 auto 16px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.order-state {
    text-align: center;
    padding: 80px 20px;
    color: var(--color-gray-500);
    background: var(--color-gray-50);
    border-radius: var(--radius-xl);
    border: 1px dashed var(--color-gray-300);
}

.order-state.empty svg {
    color: var(--color-gray-300);
    margin-bottom: 24px;
}

.order-state.empty h3 {
    font-size: 24px;
    color: var(--color-charcoal-black);
    margin: 0 0 12px;
}

.order-state.empty p {
    font-size: 16px;
    margin: 0;
}

/* Order list */
.order-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.order-card {
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-xl);
    padding: 24px;
    cursor: pointer;
    transition: all 0.2s;
    background: var(--color-white);
}

.order-card:hover {
    border-color: var(--color-gray-300);
    box-shadow: var(--shadow-sm);
    transform: translateY(-2px);
}

.order-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.order-id {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.order-code {
    font-weight: 800;
    font-size: 18px;
    color: var(--color-charcoal-black);
}

.order-date {
    font-size: 14px;
    color: var(--color-gray-500);
}

.order-card-body {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 0;
    border-top: 1px dashed var(--color-gray-200);
    border-bottom: 1px dashed var(--color-gray-200);
    margin-bottom: 16px;
}

.order-meta { 
    display: flex; 
    gap: 10px; 
    flex-wrap: wrap; 
}

.meta-tag {
    background: var(--color-gray-50);
    color: var(--color-gray-600);
    font-size: 13px;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: var(--radius-full);
}

.order-total {
    font-weight: 800;
    color: var(--color-chili-red);
    font-size: 20px;
}

.order-card-foot {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    color: var(--color-gray-500);
    font-size: 14px;
    font-weight: 500;
    transition: color 0.2s;
}

.order-card:hover .order-card-foot {
    color: var(--color-chili-red);
}

/* Pills (status) */
.pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: var(--radius-full);
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
}

/* Use SVG within pill */
.pill :deep(svg) {
    flex-shrink: 0;
}

.pill-amber  { background: rgba(217, 119, 6, 0.1); color: #b45309; }
.pill-blue   { background: rgba(37, 99, 235, 0.1); color: #1d4ed8; }
.pill-orange { background: rgba(234, 88, 12, 0.1); color: #c2410c; }
.pill-cyan   { background: rgba(8, 145, 178, 0.1); color: #0e7490; }
.pill-green  { background: rgba(22, 163, 74, 0.1); color: #15803d; }
.pill-gray   { background: var(--color-gray-100); color: var(--color-gray-600); }
.pill-red    { background: rgba(220, 38, 38, 0.1); color: #b91c1c; }

.order-pagination {
    margin-top: 24px;
    text-align: center;
    color: var(--color-gray-500);
    font-size: 14px;
    font-weight: 500;
}

@media (max-width: 768px) {
  .account-card {
        padding: 24px;
    }
    .info-grid { 
        grid-template-columns: 1fr; 
    }
    .order-card-head { 
        flex-direction: column; 
        align-items: flex-start; 
        gap: 16px; 
  }
}

/* ===== Loyalty ===== */
.loyalty-summary {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 1px solid #f59e0b;
    border-radius: var(--radius-xl);
    padding: 24px;
    margin-bottom: 32px;
}
.loyalty-current {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 8px;
}
.loyalty-current-num {
    font-size: 36px;
    font-weight: 800;
    color: #92400e;
    line-height: 1;
}
.loyalty-current-label {
    font-size: 14px;
    color: #92400e;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}
.loyalty-help {
    color: #92400e;
    font-size: 14px;
    margin: 0;
    line-height: 1.6;
}
.loyalty-subhead {
    font-size: 18px;
    font-weight: 700;
    color: var(--color-charcoal-black);
    margin: 0 0 16px;
}
.loyalty-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.loyalty-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 20px;
    border: 1px solid var(--color-gray-200);
    border-radius: var(--radius-lg);
    background: var(--color-white);
}
.loyalty-item-left {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}
.loyalty-item-reason {
    font-size: 14px;
    color: var(--color-charcoal-black);
    font-weight: 600;
    margin: 0 0 4px;
    line-height: 1.4;
}
.loyalty-item-date {
    font-size: 12px;
    color: var(--color-gray-500);
    margin: 0;
}
.loyalty-item-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    text-align: right;
}
.loyalty-positive {
    font-size: 18px;
    font-weight: 800;
    color: #15803d;
}
.loyalty-negative {
    font-size: 18px;
    font-weight: 800;
    color: #c2410c;
}
.loyalty-balance-after {
    font-size: 11px;
    color: var(--color-gray-500);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.loyalty-pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    margin-top: 16px;
}
.loyalty-page-btn {
    width: 36px;
    height: 36px;
    border: 1px solid var(--color-gray-200);
    background: var(--color-white);
    border-radius: var(--radius-md, 8px);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    color: var(--color-gray-600);
}
.loyalty-page-btn:hover:not(:disabled) {
    background: var(--color-gray-50);
}
.loyalty-page-btn.active {
    background: var(--color-brand-primary);
    color: var(--color-white);
    border-color: var(--color-charcoal-black);
}
.loyalty-page-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
