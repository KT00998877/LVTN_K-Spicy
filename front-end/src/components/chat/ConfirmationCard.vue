<script setup lang="ts">
/**
 * ConfirmationCard - Phase C3
 * 
 * Displays a pending action for user confirmation.
 * Used for cart_add_item and reservation_create actions.
 */
import { computed } from 'vue'
import type { ConfirmationSummary } from '@/api/chatbot'

interface Props {
    actionId: number
    summary: ConfirmationSummary
    status?: 'pending' | 'confirmed' | 'cancelled' | 'expired' | 'failed'
    loading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    status: 'pending',
    loading: false,
})

const emit = defineEmits<{
    confirm: [actionId: number]
    cancel: [actionId: number]
}>()

const isPending = computed(() => props.status === 'pending')
const isConfirmed = computed(() => props.status === 'confirmed')
const isCancelled = computed(() => props.status === 'cancelled')
const isExpired = computed(() => props.status === 'expired')
const isFailed = computed(() => props.status === 'failed')

const statusLabel = computed(() => {
    switch (props.status) {
        case 'confirmed': return 'Đã xác nhận'
        case 'cancelled': return 'Đã hủy'
        case 'expired': return 'Đã hết hạn'
        case 'failed': return 'Thất bại'
        default: return ''
    }
})

const formattedPrice = computed(() => {
    const pb = props.summary.price_breakdown
    if (!pb) return null
    return new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
        minimumFractionDigits: 0,
    }).format(pb.subtotal)
})

function handleConfirm() {
    if (isPending.value && !props.loading) {
        emit('confirm', props.actionId)
    }
}

function handleCancel() {
    if (isPending.value && !props.loading) {
        emit('cancel', props.actionId)
    }
}
</script>

<template>
    <div class="confirmation-card" :class="[`confirmation-card--${status}`]">
        <!-- Header -->
        <div class="confirmation-card__header">
            <span class="confirmation-card__icon">{{ summary.icon }}</span>
            <span class="confirmation-card__title">{{ summary.title }}</span>
        </div>

        <!-- Details -->
        <div class="confirmation-card__details">
            <div 
                v-for="(item, index) in summary.items" 
                :key="index"
                class="confirmation-card__item"
            >
                <span class="confirmation-card__label">{{ item.label }}</span>
                <span class="confirmation-card__value">{{ item.value }}</span>
            </div>
        </div>

        <!-- Price breakdown -->
        <div v-if="formattedPrice" class="confirmation-card__price">
            <span class="confirmation-card__price-label">Tổng cộng:</span>
            <span class="confirmation-card__price-value">{{ formattedPrice }}</span>
        </div>

        <!-- Toppings -->
        <div v-if="summary.toppings?.length" class="confirmation-card__toppings">
            <span class="confirmation-card__toppings-label">Topping:</span>
            <span class="confirmation-card__toppings-value">{{ summary.toppings.join(', ') }}</span>
        </div>

        <!-- Status indicator -->
        <div v-if="!isPending" class="confirmation-card__status">
            <span class="confirmation-card__status-text">{{ statusLabel }}</span>
        </div>

        <!-- Actions -->
        <div v-if="isPending" class="confirmation-card__actions">
            <button 
                class="confirmation-card__btn confirmation-card__btn--cancel"
                :disabled="loading"
                @click="handleCancel"
            >
                {{ loading ? 'Đang hủy...' : 'Hủy' }}
            </button>
            <button 
                class="confirmation-card__btn confirmation-card__btn--confirm"
                :disabled="loading"
                @click="handleConfirm"
            >
                {{ loading ? 'Đang xác nhận...' : 'Xác nhận' }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.confirmation-card {
    background: #fff;
    border: 1px solid #e8d4c4;
    border-radius: 12px;
    padding: 14px 16px;
    margin-top: 8px;
    font-size: 14px;
}

.confirmation-card--confirmed {
    border-color: #4caf50;
    background: #f1f8f1;
}

.confirmation-card--cancelled {
    border-color: #9e9e9e;
    background: #f5f5f5;
    opacity: 0.7;
}

.confirmation-card--expired {
    border-color: #ff9800;
    background: #fff8e1;
}

.confirmation-card--failed {
    border-color: #f44336;
    background: #ffebee;
}

.confirmation-card__header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f0e0c0;
}

.confirmation-card__icon {
    font-size: 18px;
}

.confirmation-card__title {
    font-weight: 600;
    color: #2e2e2e;
}

.confirmation-card__details {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 10px;
}

.confirmation-card__item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.confirmation-card__label {
    color: #666;
    font-size: 13px;
}

.confirmation-card__value {
    color: #2e2e2e;
    font-weight: 500;
}

.confirmation-card__price {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 10px;
    border-top: 1px solid #f0e0c0;
    margin-top: 4px;
}

.confirmation-card__price-label {
    font-weight: 600;
    color: #2e2e2e;
}

.confirmation-card__price-value {
    font-weight: 700;
    color: #d32f2f;
    font-size: 16px;
}

.confirmation-card__toppings {
    margin-top: 8px;
    font-size: 13px;
    color: #666;
}

.confirmation-card__toppings-label {
    font-weight: 500;
}

.confirmation-card__status {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #f0e0c0;
    text-align: center;
}

.confirmation-card__status-text {
    font-size: 13px;
    font-weight: 600;
}

.confirmation-card--confirmed .confirmation-card__status-text {
    color: #4caf50;
}

.confirmation-card--cancelled .confirmation-card__status-text {
    color: #666;
}

.confirmation-card--expired .confirmation-card__status-text {
    color: #ff9800;
}

.confirmation-card--failed .confirmation-card__status-text {
    color: #f44336;
}

.confirmation-card__actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.confirmation-card__btn {
    flex: 1;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, opacity 0.15s ease;
}

.confirmation-card__btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.confirmation-card__btn--cancel {
    background: #f5f5f5;
    color: #666;
    border: 1px solid #e0e0e0;
}

.confirmation-card__btn--cancel:hover:not(:disabled) {
    background: #eeeeee;
}

.confirmation-card__btn--confirm {
    background: #d32f2f;
    color: #fff;
    border: none;
}

.confirmation-card__btn--confirm:hover:not(:disabled) {
    background: #b71c1c;
}
</style>
