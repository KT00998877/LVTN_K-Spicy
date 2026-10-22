<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'

import AuthLayout from '@/components/auth/AuthLayout.vue'
import PasswordStrength from '@/components/auth/PasswordStrength.vue'
import SocialLogin from '@/components/auth/SocialLogin.vue'

const router = useRouter()
const authStore = useAuthStore()
const { loading, error, validationErrors } = storeToRefs(authStore)

const form = reactive({
    name: '',
    full_name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    agree: false,
})

const showPassword = ref(false)
const showConfirm = ref(false)

const nameErr     = computed(() => validationErrors.value.name?.[0])
const emailErr    = computed(() => validationErrors.value.email?.[0])
const phoneErr    = computed(() => validationErrors.value.phone?.[0])
const passwordErr = computed(() => validationErrors.value.password?.[0])

const canSubmit = computed(() => {
    return form.name.trim().length >= 2
        && form.email.includes('@')
        && form.password.length >= 8
        && form.password === form.password_confirmation
        && form.agree
})

async function onSubmit() {
    if (!canSubmit.value) return
    const ok = await authStore.register({
        name: form.name.trim(),
        full_name: form.full_name.trim() || form.name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim() || undefined,
        password: form.password,
        password_confirmation: form.password_confirmation,
    })
    if (ok) {
        router.replace('/')
    }
}
</script>

<template>
    <AuthLayout
        title="Tạo tài khoản"
        subtitle="Đăng ký để trở thành thành viên và nhận ngay ưu đãi sinh nhật 20%."
        :bullets="[
            'Tích điểm đổi quà hấp dẫn',
            'Ưu đãi độc quyền cho thành viên',
            'Đặt bàn chỉ với 1 cú nhấp',
        ]"
    >
        <div class="au-form-header">
            <h2 class="au-form-title">Đăng ký tài khoản</h2>
            <p class="au-form-sub">Điền thông tin bên dưới để bắt đầu</p>
        </div>

        <div v-if="error" class="au-alert au-alert-error" style="margin-bottom: 18px;">
            <span>⚠️</span>
            <span>{{ error }}</span>
        </div>

        <form class="au-form" @submit.prevent="onSubmit" novalidate>
            <div class="au-field">
                <label for="name" class="au-label">Họ và tên <span style="color: var(--au-error)">*</span></label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="au-input"
                    :class="{ 'has-error': !!nameErr }"
                    placeholder="Nguyễn Văn A"
                    autocomplete="name"
                    required
                />
                <span v-if="nameErr" class="au-error-msg">{{ nameErr }}</span>
            </div>

            <div class="au-field">
                <label for="email" class="au-label">Email <span style="color: var(--au-error)">*</span></label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="au-input"
                    :class="{ 'has-error': !!emailErr }"
                    placeholder="email@example.com"
                    autocomplete="email"
                    required
                />
                <span v-if="emailErr" class="au-error-msg">{{ emailErr }}</span>
            </div>

            <div class="au-field">
                <label for="phone" class="au-label">Số điện thoại</label>
                <input
                    id="phone"
                    v-model="form.phone"
                    type="tel"
                    class="au-input"
                    :class="{ 'has-error': !!phoneErr }"
                    placeholder="0901234567"
                    autocomplete="tel"
                />
                <span v-if="phoneErr" class="au-error-msg">{{ phoneErr }}</span>
            </div>

            <div class="au-field">
                <label for="password" class="au-label">Mật khẩu <span style="color: var(--au-error)">*</span></label>
                <div class="au-input-wrap">
                    <input
                        id="password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        class="au-input"
                        :class="{ 'has-error': !!passwordErr }"
                        style="padding-right: 44px;"
                        placeholder="Tối thiểu 8 ký tự"
                        autocomplete="new-password"
                        required
                    />
                    <button
                        type="button"
                        class="au-pw-toggle"
                        @click="showPassword = !showPassword"
                        :aria-label="showPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'"
                    >
                        {{ showPassword ? '🙈' : '👁' }}
                    </button>
                </div>
                <PasswordStrength :password="form.password" />
                <span v-if="passwordErr" class="au-error-msg">{{ passwordErr }}</span>
                <small style="color: var(--au-muted); font-size: 11px; margin-top: 2px;">
                    ≥ 8 ký tự, có chữ hoa, chữ thường, số và ký tự đặc biệt.
                </small>
            </div>

            <div class="au-field">
                <label for="password_confirmation" class="au-label">Xác nhận mật khẩu <span style="color: var(--au-error)">*</span></label>
                <div class="au-input-wrap">
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :type="showConfirm ? 'text' : 'password'"
                        class="au-input"
                        :class="{ 'has-error': form.password_confirmation && form.password !== form.password_confirmation }"
                        style="padding-right: 44px;"
                        placeholder="Nhập lại mật khẩu"
                        autocomplete="new-password"
                        required
                    />
                    <button
                        type="button"
                        class="au-pw-toggle"
                        @click="showConfirm = !showConfirm"
                        :aria-label="showConfirm ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'"
                    >
                        {{ showConfirm ? '🙈' : '👁' }}
                    </button>
                </div>
                <span
                    v-if="form.password_confirmation && form.password !== form.password_confirmation"
                    class="au-error-msg"
                >
                    Mật khẩu xác nhận không khớp
                </span>
            </div>

            <label class="au-checkbox" style="margin-top: -4px;">
                <input v-model="form.agree" type="checkbox" />
                Tôi đồng ý với
                <a href="#" class="au-link">Điều khoản</a>
                và
                <a href="#" class="au-link">Chính sách bảo mật</a>
            </label>

            <button
                type="submit"
                class="au-btn au-btn-primary"
                :disabled="loading || !canSubmit"
            >
                <span v-if="loading" class="au-spinner"></span>
                {{ loading ? 'Đang tạo tài khoản...' : 'Tạo tài khoản' }}
            </button>
        </form>

        <SocialLogin />

        <p class="au-form-footer">
            Đã có tài khoản?
            <router-link to="/dang-nhap" class="au-link">Đăng nhập</router-link>
        </p>
    </AuthLayout>
</template>
