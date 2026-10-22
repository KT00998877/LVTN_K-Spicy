<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'

const props = defineProps<{ text: string; animate: boolean }>()
const emit = defineEmits<{ progress: []; complete: [] }>()
const visible = ref(props.animate ? '' : props.text)
let frame = 0

onMounted(() => {
    if (!props.animate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        visible.value = props.text
        emit('complete')
        return
    }
    const characters = Array.from(props.text)
    if (characters.length === 0) {
        emit('complete')
        return
    }
    const duration = Math.min(characters.length * 25, 6000)
    let started = 0
    let lastProgress = 0
    const step = (now: number) => {
        if (!started) started = now
        const count = Math.min(characters.length, Math.ceil((now - started) / duration * characters.length))
        visible.value = characters.slice(0, count).join('')
        if (now - lastProgress > 150) {
            emit('progress')
            lastProgress = now
        }
        if (count < characters.length) frame = requestAnimationFrame(step)
        else emit('complete')
    }
    frame = requestAnimationFrame(step)
})

onUnmounted(() => cancelAnimationFrame(frame))
</script>

<template>
    <span v-if="animate" class="sr-only">{{ text }}</span>
    <span :aria-hidden="animate ? 'true' : undefined">{{ visible }}</span>
</template>

<style scoped>
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
