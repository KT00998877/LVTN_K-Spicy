<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

const root = ref<HTMLElement | null>(null)
const visible = ref(false)
let observer: IntersectionObserver | null = null

onMounted(() => {
    if (!root.value || typeof IntersectionObserver === 'undefined') {
        visible.value = true
        return
    }
    observer = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) return
        visible.value = true
        observer?.disconnect()
    }, { rootMargin: '300px' })
    observer.observe(root.value)
})

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
    <div ref="root" :class="{ 'lazy-section--pending': !visible }">
        <slot v-if="visible" />
    </div>
</template>

<style scoped>
.lazy-section--pending {
    min-height: 480px;
}
</style>
