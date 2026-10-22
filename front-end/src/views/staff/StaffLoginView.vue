<template>
    <div class="staff-login">
        <div class="login-bg"></div>
        <div class="login-card">
            <div class="login-header">
                <img src="/logo/logo.png" class="brand-logo" width="80" height="80" alt="K-SPICY" />
                <h1 class="brand-title">Mì Cay Hàn Quốc</h1>
                <p class="brand-subtitle">Hệ thống quản lý nhà hàng</p>
            </div>

            <div v-if="expiredNotice" class="expired-banner">
                ⚠️ Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại để tiếp tục.
            </div>

            <div class="login-section">
                <h2 class="section-title">Đăng nhập nhân viên</h2>
                <p class="section-desc">
                    Nhập email + mật khẩu được cấp để vào khu vực phục vụ.
                </p>

                <form class="login-form" @submit.prevent="handleLogin">
                    <div class="form-group">
                        <label class="form-label" for="email">Email</label>
                        <input
                            id="email"
                            v-model="email"
                            type="email"
                            class="form-input"
                            placeholder="waiter@micay.local"
                            autocomplete="username"
                            :disabled="loading"
                            required
                        />
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Mật khẩu</label>
                        <input
                            id="password"
                            v-model="password"
                            type="password"
                            class="form-input"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            :disabled="loading"
                            required
                        />
                    </div>

                    <div v-if="error" class="error-box">
                        ⚠️ {{ error }}
                    </div>

                    <button
                        type="submit"
                        class="btn-login"
                        :disabled="loading || !canSubmit"
                    >
                        <span v-if="loading">Đang đăng nhập...</span>
                        <span v-else>Đăng nhập</span>
                    </button>
                </form>

                <div class="quick-accounts">
                    <h3 class="quick-title">Tài khoản demo (DatabaseSeeder)</h3>
                    <div class="quick-list">
                        <button
                            v-for="acc in demoAccounts"
                            :key="acc.email"
                            type="button"
                            class="quick-card"
                            :disabled="loading"
                            @click="fillDemo(acc)"
                        >
                            <div class="quick-role">
                                {{ ROLE_LABELS[acc.role].icon }}
                                {{ ROLE_LABELS[acc.role].label }}
                            </div>
                            <div class="quick-email">{{ acc.email }}</div>
                            <div class="quick-pass">Mật khẩu: {{ acc.password }}</div>
                        </button>
                    </div>
                </div>
            </div>

            <div class="login-footer">
                <RouterLink to="/" class="back-home">← Về trang chủ khách hàng</RouterLink>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useStaffStore } from '@/stores/staff'
import { ROLE_LABELS, type StaffRole } from '@/types/staff'

const router = useRouter()
const route = useRoute()
const staffStore = useStaffStore()

const email = ref('')
const password = ref('')
const loading = computed(() => staffStore.loading)
const error = computed(() => staffStore.error)

const canSubmit = computed(() => !!email.value && !!password.value)

/**
 * Nếu redirect từ guard với ?expired=1 → hiển thị banner nhắc nhở.
 */
const expiredNotice = computed(() => route.query.expired === '1')

interface DemoAccount {
    email: string
    password: string
    role: StaffRole
    label: string
}

const demoAccounts: DemoAccount[] = [
    { email: 'manager@micay.local',  password: '00998877', role: 'MANAGER', label: 'Quản lý' },
    { email: 'waiter@micay.local',   password: '00998877', role: 'WAITER',  label: 'Phục vụ' },
    { email: 'kitchen@micay.local',  password: '00998877', role: 'KITCHEN', label: 'Bếp' },
    { email: 'cashier@micay.local',  password: '00998877', role: 'CASHIER', label: 'Thu ngân' },
]

function fillDemo(acc: DemoAccount): void {
    email.value = acc.email
    password.value = acc.password
}

async function handleLogin(): Promise<void> {
    if (!canSubmit.value) return
    try {
        const session = await staffStore.login(email.value.trim(), password.value)
        const rootByRole: Record<StaffRole, string> = {
            WAITER:  '/staff/waiter/tables',
            KITCHEN: '/staff/kitchen/queue',
            CASHIER: '/staff/cashier/invoices',
            SHIPPER: '/staff/shipper', MANAGER: '/staff/manager/dashboard',
        }
        router.push(rootByRole[session.role] ?? '/staff/login')
    } catch {
        // staffStore.error đã được set, UI tự render message
    }
}
</script>

<style scoped>
.staff-login {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    position: relative;
    background: var(--color-brand-dark);
}

.login-bg {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 20% 30%, rgba(249, 115, 22, 0.15), transparent 50%),
        radial-gradient(circle at 80% 70%, rgba(124, 58, 237, 0.15), transparent 50%);
    z-index: 0;
}

.login-card {
    position: relative;
    z-index: 1;
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 36px;
    max-width: 720px;
    width: 100%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
}

.login-header {
    text-align: center;
    margin-bottom: 28px;
}

.brand-logo { width: 80px; height: 80px; object-fit: contain; margin: 0 auto; border-radius: var(--radius-button); }
.brand-title {
    margin: 12px 0 4px;
    font-size: 28px;
    font-weight: 800;
    color: #111827;
}
.brand-subtitle {
    margin: 0;
    color: #6b7280;
    font-size: 14px;
}

.login-section + .login-section {
    margin-top: 28px;
    padding-top: 28px;
    border-top: 1px solid #e5e7eb;
}

.expired-banner {
    background: #fef3c7;
    color: #92400e;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid #fde68a;
    margin-bottom: 16px;
}

.section-title {
    font-size: 16px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 6px;
}
.section-desc {
    font-size: 13px;
    color: #6b7280;
    margin: 0 0 18px;
}

.login-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-label {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 6px;
}

.form-input {
    padding: 11px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    background: #fff;
}
.form-input:focus {
    outline: none;
    border-color: var(--color-brand-action);
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15);
}
.form-input:disabled {
    background: #f3f4f6;
    color: #9ca3af;
}

.error-box {
    background: #fee2e2;
    color: #991b1b;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid #fecaca;
}

.btn-login {
    background: var(--color-brand-action);
    color: #fff;
    border: none;
    padding: 13px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.15s;
    margin-top: 4px;
}
.btn-login:hover:not(:disabled) { background: var(--color-brand-action-hover); }
.btn-login:disabled { opacity: 0.5; cursor: not-allowed; }

.quick-accounts {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px dashed #e5e7eb;
}

.quick-title {
    font-size: 12px;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    margin: 0 0 10px;
    letter-spacing: 0.5px;
}

.quick-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 8px;
}

.quick-card {
    text-align: left;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px 12px;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.15s;
}
.quick-card:hover:not(:disabled) {
    background: #fff;
    border-color: var(--color-brand-action);
    transform: translateY(-1px);
}
.quick-card:disabled { opacity: 0.5; cursor: not-allowed; }

.quick-role {
    font-size: 12px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 4px;
}
.quick-email {
    font-size: 11px;
    color: #4b5563;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.quick-pass {
    font-size: 10px;
    color: #9ca3af;
    margin-top: 2px;
}

.login-footer {
    margin-top: 24px;
    padding-top: 18px;
    border-top: 1px solid #e5e7eb;
    text-align: center;
    font-size: 12px;
    color: #9ca3af;
}

.back-home {
    color: var(--color-brand-action);
    text-decoration: none;
    font-weight: 500;
}
.back-home:hover { text-decoration: underline; }

@media (max-width: 600px) {
    .login-card { padding: 24px 18px; }
    .quick-list { grid-template-columns: 1fr 1fr; }
}
</style>
