<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import { managerSecurity, type TwoFactorSetup } from '@/api/staff'

const loading = ref(true)
const saving = ref(false)
const enabled = ref(false)
const setup = ref<TwoFactorSetup | null>(null)
const error = ref('')
const success = ref('')
const confirmCode = ref('')
const disableForm = reactive({ password: '', code: '' })

function errorMessage(value: unknown): string {
    if (axios.isAxiosError(value)) {
        const errors = value.response?.data?.errors as Record<string, string[]> | undefined
        return errors?.code?.[0] || errors?.password?.[0] || value.response?.data?.message || 'Không thể cập nhật bảo mật.'
    }
    return 'Không thể cập nhật bảo mật.'
}

async function load() {
    loading.value = true
    error.value = ''
    try {
        const status = await managerSecurity.status()
        enabled.value = status.enabled
    } catch (value) {
        error.value = errorMessage(value)
    } finally {
        loading.value = false
    }
}

async function startSetup() {
    saving.value = true
    error.value = ''
    success.value = ''
    try {
        setup.value = await managerSecurity.setup()
    } catch (value) {
        error.value = errorMessage(value)
    } finally {
        saving.value = false
    }
}

async function confirmSetup() {
    saving.value = true
    error.value = ''
    try {
        await managerSecurity.confirm(confirmCode.value)
        setup.value = null
        confirmCode.value = ''
        enabled.value = true
        success.value = 'Xác thực hai lớp đã được bật.'
    } catch (value) {
        error.value = errorMessage(value)
    } finally {
        saving.value = false
    }
}

async function disable() {
    saving.value = true
    error.value = ''
    success.value = ''
    try {
        await managerSecurity.disable(disableForm.password, disableForm.code)
        disableForm.password = ''
        disableForm.code = ''
        enabled.value = false
        success.value = 'Xác thực hai lớp đã được tắt.'
    } catch (value) {
        error.value = errorMessage(value)
    } finally {
        saving.value = false
    }
}

onMounted(load)
</script>

<template>
    <main class="security-page" aria-labelledby="security-title">
        <header>
            <p class="eyebrow">Tài khoản quản lý</p>
            <h1 id="security-title">Bảo mật đăng nhập</h1>
            <p class="lead">Dùng ứng dụng xác thực để bảo vệ quyền quản lý sau bước nhập mật khẩu.</p>
        </header>

        <p v-if="loading" role="status">Đang tải trạng thái bảo mật...</p>
        <p v-if="error" class="notice notice--error" role="alert">{{ error }}</p>
        <p v-if="success" class="notice notice--success" role="status">{{ success }}</p>

        <section v-if="!loading && !enabled" class="security-section" aria-labelledby="enable-title">
            <h2 id="enable-title">Xác thực hai lớp đang tắt</h2>
            <p>Mỗi lần đăng nhập manager sẽ cần thêm mã 6 số thay đổi theo thời gian.</p>
            <button v-if="!setup" class="primary-button" type="button" :disabled="saving" @click="startSetup">
                {{ saving ? 'Đang khởi tạo...' : 'Thiết lập ứng dụng xác thực' }}
            </button>

            <form v-else class="setup-form" @submit.prevent="confirmSetup">
                <div class="setup-secret">
                    <span>Khóa thiết lập</span>
                    <code>{{ setup.secret }}</code>
                    <a :href="setup.provisioning_uri">Mở bằng ứng dụng xác thực</a>
                </div>
                <label for="confirm-2fa">Mã 6 số đầu tiên</label>
                <input id="confirm-2fa" v-model="confirmCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required />
                <button class="primary-button" type="submit" :disabled="saving || confirmCode.length !== 6">
                    {{ saving ? 'Đang xác nhận...' : 'Xác nhận và bật' }}
                </button>
            </form>
        </section>

        <section v-else-if="!loading" class="security-section" aria-labelledby="disable-title">
            <div class="status-line"><span aria-hidden="true"></span>Đang bảo vệ bằng ứng dụng xác thực</div>
            <h2 id="disable-title">Tắt xác thực hai lớp</h2>
            <p>Để tắt, xác nhận lại mật khẩu và mã hiện tại. Các phiên đăng nhập đang mở không bị ảnh hưởng.</p>
            <form class="disable-form" @submit.prevent="disable">
                <label for="disable-password">Mật khẩu hiện tại</label>
                <input id="disable-password" v-model="disableForm.password" type="password" autocomplete="current-password" required />
                <label for="disable-code">Mã xác minh</label>
                <input id="disable-code" v-model="disableForm.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required />
                <button class="danger-button" type="submit" :disabled="saving || disableForm.code.length !== 6 || !disableForm.password">
                    {{ saving ? 'Đang xử lý...' : 'Tắt xác thực hai lớp' }}
                </button>
            </form>
        </section>
    </main>
</template>

<style scoped>
.security-page { max-width: 760px; padding: 8px 0 48px; color: #241a17; }
.eyebrow { margin: 0 0 6px; color: #b3261e; font-size: 12px; font-weight: 800; text-transform: uppercase; }
h1 { margin: 0; font-size: clamp(28px, 4vw, 38px); letter-spacing: 0; }
.lead { max-width: 620px; margin: 10px 0 28px; color: #6c5b55; line-height: 1.6; }
.security-section { border-top: 1px solid #decfc9; padding-top: 24px; }
.security-section h2 { margin: 0 0 8px; font-size: 20px; }
.security-section p { color: #6c5b55; line-height: 1.55; }
.setup-form, .disable-form { display: grid; gap: 10px; max-width: 520px; margin-top: 22px; }
label { font-weight: 700; font-size: 14px; }
input { min-height: 44px; border: 1px solid #b9aaa4; border-radius: 6px; padding: 9px 12px; font: inherit; }
input:focus { outline: 3px solid rgba(179, 38, 30, 0.18); border-color: #b3261e; }
.setup-secret { display: grid; gap: 8px; padding: 16px; border-left: 4px solid #d93b28; background: #fff7f3; }
.setup-secret span { font-size: 13px; font-weight: 700; }
.setup-secret code { overflow-wrap: anywhere; font-size: 14px; }
.setup-secret a { width: fit-content; color: #9d2019; font-weight: 700; }
.primary-button, .danger-button { min-height: 44px; width: fit-content; border: 0; border-radius: 6px; padding: 10px 18px; color: #fff; font-weight: 800; cursor: pointer; }
.primary-button { background: #b3261e; }
.danger-button { margin-top: 8px; background: #7d1b15; }
button:disabled { cursor: wait; opacity: .62; }
.notice { padding: 12px 14px; border-left: 4px solid; }
.notice--error { border-color: #b3261e; background: #fff0ed; color: #7d1b15; }
.notice--success { border-color: #237a4b; background: #eef9f2; color: #155b36; }
.status-line { display: flex; align-items: center; gap: 9px; margin-bottom: 22px; color: #155b36; font-weight: 800; }
.status-line span { width: 10px; height: 10px; border-radius: 50%; background: #237a4b; }
@media (max-width: 640px) { .security-page { padding-inline: 2px; } .primary-button, .danger-button { width: 100%; } }
</style>
