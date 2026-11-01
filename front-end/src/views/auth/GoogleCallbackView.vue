<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const error = ref('')

onMounted(async () => {
    const ticket = typeof route.query.ticket === 'string' ? route.query.ticket : ''
    const reason = typeof route.query.error === 'string' ? route.query.error : ''
    const nonce = sessionStorage.getItem('google_login_nonce') || ''
    sessionStorage.removeItem('google_login_nonce')
    window.history.replaceState(window.history.state, '', route.path)
    if (!ticket) {
        error.value = reason === 'cancelled' ? 'Bạn đã hủy đăng nhập Google.'
            : reason === 'account_unavailable' ? 'Tài khoản này không thể đăng nhập bằng Google.'
            : reason === 'unverified_email' ? 'Email Google chưa được xác minh.'
            : reason === 'invalid_state' ? 'Phiên đăng nhập đã hết hạn. Hãy bấm Google để thử lại.'
            : reason === 'google_unavailable' ? 'Máy chủ chưa kết nối được với Google. Vui lòng thử lại.'
            : 'Không thể hoàn tất đăng nhập Google. Vui lòng thử lại.'
        return
    }
    if (await auth.loginWithGoogleTicket(ticket, nonce)) {
        const saved = sessionStorage.getItem('google_login_redirect') || '/'
        sessionStorage.removeItem('google_login_redirect')
        await router.replace(saved.startsWith('/') && !saved.startsWith('//') ? saved : '/')
    } else error.value = auth.error || 'Không thể đăng nhập bằng Google. Vui lòng thử lại.'
})
</script>

<template>
    <main class="google-callback">
        <h1>Đăng nhập bằng Google</h1>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-else role="status">Đang hoàn tất đăng nhập…</p>
        <RouterLink v-if="error" to="/dang-nhap">Quay lại đăng nhập</RouterLink>
    </main>
</template>

<style scoped>
.google-callback { max-width: 520px; margin: 80px auto; padding: 24px; text-align: center; }
</style>
