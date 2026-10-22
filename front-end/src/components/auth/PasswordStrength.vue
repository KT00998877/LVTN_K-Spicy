<script setup lang="ts">
import { computed } from 'vue'
import type { PasswordStrengthInfo } from '@/types/auth'

const props = defineProps<{
    password: string
}>()

/**
 * Đánh giá độ mạnh của password (frontend-side, không thay thế backend).
 * Thuật toán: đếm số loại ký tự + độ dài.
 */
const info = computed<PasswordStrengthInfo>(() => {
    const pwd = props.password
    if (!pwd) {
        return { level: 'empty', score: 0, hint: '' }
    }

    let score = 0
    const hasLower = /[a-z]/.test(pwd)
    const hasUpper = /[A-Z]/.test(pwd)
    const hasDigit = /\d/.test(pwd)
    const hasSymbol = /[^A-Za-z0-9]/.test(pwd)
    const len = pwd.length

    // Cộng điểm theo độ đa dạng
    if (hasLower) score++
    if (hasUpper) score++
    if (hasDigit) score++
    if (hasSymbol) score++

    // Cộng điểm theo độ dài
    if (len >= 8) score++
    if (len >= 12) score++
    if (len >= 16) score++

    // Trừ điểm nếu quá ngắn
    if (len < 6) score = Math.max(0, score - 1)

    // Map sang level
    let level: PasswordStrengthInfo['level']
    let hint: string

    if (score <= 2) {
        level = 'weak'
        hint = 'Yếu — dễ bị bẻ khóa'
    } else if (score === 3) {
        level = 'fair'
        hint = 'Trung bình — nên thêm ký tự đặc biệt'
    } else if (score === 4 || score === 5) {
        level = 'good'
        hint = 'Tốt — đủ mạnh để dùng'
    } else {
        level = 'strong'
        hint = 'Rất mạnh — xuất sắc!'
    }

    // Giới hạn score tối đa 4 để hiển thị
    const finalScore = Math.min(4, Math.max(0, Math.floor(score / 2))) as 0 | 1 | 2 | 3 | 4

    return { level, score: finalScore, hint }
})

const colorMap: Record<string, string> = {
    empty: '#e5e7eb',
    weak: '#dc2626',
    fair: '#f59e0b',
    good: '#3b82f6',
    strong: '#16a34a',
}

const labelMap: Record<string, string> = {
    empty: '',
    weak: 'Yếu',
    fair: 'Trung bình',
    good: 'Tốt',
    strong: 'Rất mạnh',
}
</script>

<template>
    <div class="au-strength" v-if="password">
        <div class="au-strength-bar">
            <div
                class="au-strength-fill"
                :style="{
                    width: `${(info.score / 4) * 100}%`,
                    backgroundColor: colorMap[info.level],
                }"
            ></div>
        </div>
        <span class="au-strength-label" :style="{ color: colorMap[info.level] }">
            {{ labelMap[info.level] }}
        </span>
    </div>
    <p v-if="password" class="au-strength-hint" :style="{ color: colorMap[info.level] }">
        {{ info.hint }}
    </p>
</template>

<style scoped>
.au-strength-hint {
    margin: 4px 0 0;
    font-size: 11px;
}
</style>
