<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useStaffStore } from '@/stores/staff'
import type { StaffRole } from '@/types/staff'

import AuthLayout from '@/components/auth/AuthLayout.vue'
import SocialLogin from '@/components/auth/SocialLogin.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const { loading, error, validationErrors, justRegistered } = storeToRefs(authStore)

const form = reactive({
    identifier: '',
    password: '',
    remember: true,
})

const showPassword = ref(false)

const sessionExpired = computed(() => route.query.expired === '1')
const redirectTarget = computed(() => (route.query.redirect as string) || '/')

const identifierErr = computed(() => validationErrors.value.identifier?.[0] ?? validationErrors.value.email?.[0])
const passwordErr   = computed(() => validationErrors.value.password?.[0])

async function onSubmit() {
    const ok = await authStore.login({
        identifier: form.identifier.trim(),
        password: form.password,
    })
    if (ok) {
        const staff = useStaffStore()
        const roots: Record<StaffRole, string> = {
            WAITER: '/staff/waiter/tables', KITCHEN: '/staff/kitchen/queue',
            CASHIER: '/staff/cashier/invoices', SHIPPER: '/staff/shipper', MANAGER: '/staff/manager/dashboard',
        }
        router.replace(staff.currentRole ? roots[staff.currentRole] : redirectTarget.value)
    }
}

onMounted(() => {
    // Nếu vừa đăng ký xong, có thể có sẵn token → vào thẳng trang chủ
    if (justRegistered.value) {
        router.replace('/')
    }
})
</script>

<template>
    <AuthLayout
        title="Chào mừng quay lại!"
        subtitle="Đăng nhập bằng tài khoản khách hàng hoặc nhân viên để tiếp tục."
        :bullets="[
            'Đặt bàn trực tuyến nhanh chóng',
            'Tích điểm đổi quà sau mỗi đơn hàng',
            'Nhận thông báo ưu đãi sớm nhất',
        ]"
    >
        <template v-if="sessionExpired">
            <div class="au-alert au-alert-info" style="margin-bottom: 20px;">
                <span>⏰</span>
                <span>Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại để tiếp tục.</span>
            </div>
        </template>

        <div class="au-form-header">
            <h2 class="au-form-title">Đăng nhập</h2>
            <p class="au-form-sub">Nhập email/số điện thoại và mật khẩu của bạn</p>
        </div>

        <div v-if="error && !identifierErr && !passwordErr" class="au-alert au-alert-error" style="margin-bottom: 18px;">
            <span>⚠️</span>
            <span>{{ error }}</span>
        </div>

        <form class="au-form" @submit.prevent="onSubmit" novalidate>
            <div class="au-field">
                <label for="identifier" class="au-label">Email hoặc số điện thoại</label>
                <input
                    id="identifier"
                    v-model="form.identifier"
                    type="text"
                    class="au-input"
                    :class="{ 'has-error': !!identifierErr }"
                    placeholder="email@example.com hoặc 0901234567"
                    autocomplete="username"
                    required
                />
                <span v-if="identifierErr" class="au-error-msg">{{ identifierErr }}</span>
            </div>

            <div class="au-field">
                <label for="password" class="au-label">Mật khẩu</label>
                <div class="au-input-wrap">
                    <input
                        id="password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        class="au-input"
                        :class="{ 'has-error': !!passwordErr }"
                        style="padding-right: 44px;"
                        placeholder="••••••••"
                        autocomplete="current-password"
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
                <span v-if="passwordErr" class="au-error-msg">{{ passwordErr }}</span>
            </div>

            <div class="au-form-options">
                <label class="au-checkbox">
                    <input v-model="form.remember" type="checkbox" />
                    Ghi nhớ đăng nhập
                </label>
                <router-link to="/quen-mat-khau" class="au-link">
                    Quên mật khẩu?
                </router-link>
            </div>

            <button
                type="submit"
                class="au-btn au-btn-primary"
                :disabled="loading"
            >
                <span v-if="loading" class="au-spinner"></span>
                {{ loading ? 'Đang đăng nhập...' : 'Đăng nhập' }}
            </button>
        </form>

        <SocialLogin />

        <p class="au-form-footer">
            Chưa có tài khoản?
            <router-link to="/dang-ky" class="au-link">Đăng ký ngay</router-link>
        </p>
    </AuthLayout>
</template>
