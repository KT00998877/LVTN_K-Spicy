<script setup lang="ts">
import { useRoute } from 'vue-router'
import { googleStartUrl } from '@/api/auth'

const route = useRoute()

function handleGoogle() {
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    const bytes = crypto.getRandomValues(new Uint8Array(32))
    const nonce = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')
    sessionStorage.setItem('google_login_redirect', redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/')
    sessionStorage.setItem('google_login_nonce', nonce)
    window.location.assign(googleStartUrl(nonce))
}
</script>

<template>
    <div class="social-block">
        <div class="au-divider">Hoặc tiếp tục với</div>
        <div class="au-social">
            <button type="button" class="au-social-btn" @click="handleGoogle">
                <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Google
            </button>
        </div>
    </div>
</template>

<style scoped>
.social-block { width: 100%; }
</style>
