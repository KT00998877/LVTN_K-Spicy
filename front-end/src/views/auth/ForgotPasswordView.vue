<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'

import AuthLayout from '@/components/auth/AuthLayout.vue'
import { forgotPassword as apiForgot } from '@/api/auth'

const router = useRouter()
const authStore = useAuthStore()
const { loading } = storeToRefs(authStore)

const form = reactive({ email: '' })
const errorMsg = ref<string | null>(null)
const successMsg = ref<string | null>(null)

const emailErr = computed(() => {
    const v = authStore.validationErrors.email?.[0]
    return v
})

async function onSubmit() {
    errorMsg.value = null
    successMsg.value = null
    try {
        successMsg.value = await apiForgot({ email: form.email.trim() })
        authStore.validationErrors = {}
    } catch (err) {
        const e = err as { response?: { data?: { message?: string } } }
        errorMsg.value = e.response?.data?.message ?? 'Có lỗi xảy ra, vui lòng thử lại.'
    }
}
</script>

<template>
    <AuthLayout
        title="Quên mật khẩu?"
        subtitle="Nhập email đã đăng ký, chúng tôi sẽ gửi link đặt lại mật khẩu cho bạn."
        :bullets="[
            'Link đặt lại có hiệu lực trong 60 phút',
            'Chỉ dùng được 1 lần',
            'Có thể yêu cầu lại nếu chưa nhận được',
        ]"
    >
        <div class="au-form-header">
            <h2 class="au-form-title">Khôi phục mật khẩu</h2>
            <p class="au-form-sub">Kiểm tra cả hộp thư spam nếu không thấy email</p>
        </div>

        <div v-if="successMsg" class="au-alert au-alert-success" style="margin-bottom: 18px;">
            <span>✅</span>
            <span>{{ successMsg }}</span>
        </div>

        <div v-if="errorMsg" class="au-alert au-alert-error" style="margin-bottom: 18px;">
            <span>⚠️</span>
            <span>{{ errorMsg }}</span>
        </div>

        <form class="au-form" @submit.prevent="onSubmit" novalidate>
            <div class="au-field">
                <label for="email" class="au-label">Email đã đăng ký</label>
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

            <button type="submit" class="au-btn au-btn-primary" :disabled="loading">
                <span v-if="loading" class="au-spinner"></span>
                {{ loading ? 'Đang gửi...' : 'Gửi link đặt lại' }}
            </button>
        </form>

        <p class="au-form-footer">
            <router-link to="/dang-nhap" class="au-link">← Quay lại đăng nhập</router-link>
        </p>
    </AuthLayout>
</template>
