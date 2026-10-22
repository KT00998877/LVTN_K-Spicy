<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import PasswordStrength from '@/components/auth/PasswordStrength.vue'
import { resetPassword as apiReset } from '@/api/auth'

const router = useRouter()
const route = useRoute()

const form = reactive({
    email: (route.query.email as string) || '',
    token: (route.query.token as string) || '',
    password: '',
    password_confirmation: '',
})

const showPassword = ref(false)
const loading = ref(false)
const errorMsg = ref<string | null>(null)
const successMsg = ref<string | null>(null)

const passwordErr = computed(() => form.password !== form.password_confirmation && form.password_confirmation ? 'Mật khẩu xác nhận không khớp' : '')

const isValid = computed(() => {
    return form.email && form.token && form.password.length >= 8
        && form.password === form.password_confirmation
})

async function onSubmit() {
    if (!isValid.value) return
    errorMsg.value = null
    successMsg.value = null
    loading.value = true
    try {
        successMsg.value = await apiReset({
            email: form.email,
            token: form.token,
            password: form.password,
            password_confirmation: form.password_confirmation,
        })
        // Sau 2s chuyển về trang đăng nhập
        setTimeout(() => router.replace('/dang-nhap'), 2000)
    } catch (err) {
        const e = err as { response?: { data?: { message?: string } } }
        errorMsg.value = e.response?.data?.message ?? 'Link không hợp lệ hoặc đã hết hạn.'
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <AuthLayout
        title="Đặt lại mật khẩu"
        subtitle="Nhập mật khẩu mới cho tài khoản của bạn."
        :bullets="[
            'Mật khẩu phải có ít nhất 8 ký tự',
            'Bao gồm chữ hoa, chữ thường, số và ký tự đặc biệt',
            'Sau khi đổi, bạn cần đăng nhập lại',
        ]"
    >
        <div class="au-form-header">
            <h2 class="au-form-title">Mật khẩu mới</h2>
            <p class="au-form-sub">Vui lòng nhập mật khẩu mới cho tài khoản <strong>{{ form.email }}</strong></p>
        </div>

        <div v-if="successMsg" class="au-alert au-alert-success" style="margin-bottom: 18px;">
            <span>✅</span>
            <span>{{ successMsg }} Đang chuyển sang trang đăng nhập...</span>
        </div>

        <div v-if="errorMsg" class="au-alert au-alert-error" style="margin-bottom: 18px;">
            <span>⚠️</span>
            <span>{{ errorMsg }}</span>
        </div>

        <form class="au-form" @submit.prevent="onSubmit" novalidate>
            <div class="au-field">
                <label for="new-password" class="au-label">Mật khẩu mới</label>
                <div class="au-input-wrap">
                    <input
                        id="new-password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        class="au-input"
                        style="padding-right: 44px;"
                        placeholder="≥ 8 ký tự"
                        autocomplete="new-password"
                        required
                    />
                    <button
                        type="button"
                        class="au-pw-toggle"
                        @click="showPassword = !showPassword"
                    >
                        {{ showPassword ? '🙈' : '👁' }}
                    </button>
                </div>
                <PasswordStrength :password="form.password" />
            </div>

            <div class="au-field">
                <label for="confirm" class="au-label">Xác nhận mật khẩu</label>
                <input
                    id="confirm"
                    v-model="form.password_confirmation"
                    type="password"
                    class="au-input"
                    :class="{ 'has-error': !!passwordErr }"
                    placeholder="Nhập lại mật khẩu"
                    autocomplete="new-password"
                    required
                />
                <span v-if="passwordErr" class="au-error-msg">{{ passwordErr }}</span>
            </div>

            <button type="submit" class="au-btn au-btn-primary" :disabled="loading || !isValid">
                <span v-if="loading" class="au-spinner"></span>
                {{ loading ? 'Đang xử lý...' : 'Đặt lại mật khẩu' }}
            </button>
        </form>

        <p class="au-form-footer">
            <router-link to="/dang-nhap" class="au-link">← Quay lại đăng nhập</router-link>
        </p>
    </AuthLayout>
</template>
