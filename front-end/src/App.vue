<script setup lang="ts">
import { RouterView } from 'vue-router'
import ChatBotWidget from '@/components/chat/ChatBotWidget.vue'
import LiveChatWidget from '@/components/chat/LiveChatWidget.vue'
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const showCustomerChat = computed(() => !route.path.startsWith('/staff'))
</script>

<template>
    <RouterView />
    <!-- Tư vấn trực tiếp chạy song song với trợ lý ảo. -->
    <LiveChatWidget v-if="showCustomerChat" />
    <ChatBotWidget v-if="showCustomerChat" />
</template>

<style>
/* Reset cơ bản để Navbar sticky hoạt động đúng */
body {
    margin: 0;
    background: var(--color-background);
}
</style>
