import { nextTick, watch, onMounted, onScopeDispose } from 'vue'
import { useRoute } from 'vue-router'

/** Select the correct list and bring a linked order into view after data arrives. */
export function useNotificationOrderTarget<T extends { id: number }>(orders: () => T[], select: (order: T) => void) {
    const route = useRoute()
    let lastTarget = ''
    const focusOrder = async () => {
        const id = Number(route.query.order_id)
        const order = orders().find(order => order.id === id)
        if (!order) return
        const target = `${route.path}:${id}`
        if (target === lastTarget) return
        lastTarget = target
        select(order)
        await nextTick()
        const element = document.getElementById(`staff-order-${id}`)
        element?.scrollIntoView({ block: 'center', behavior: 'instant' })
        element?.focus({ preventScroll: true })
    }
    watch([() => route.query.order_id, orders], focusOrder, { immediate: true })
    const forceFocus = () => { lastTarget = ''; void focusOrder() }
    onMounted(() => window.addEventListener('staff:focus-order', forceFocus))
    onScopeDispose(() => window.removeEventListener('staff:focus-order', forceFocus))
    return route
}
