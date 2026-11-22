<template>
    <nav class="warehouse-tabs" :aria-label="section === 'receiving' ? 'Nhập hàng' : 'Kho hàng'">
        <RouterLink v-for="tab in tabs[section]" :key="tab.name" :to="{ name: tab.name }">
            {{ tab.label }}
        </RouterLink>
    </nav>
    <RouterView :key="$route.name" />
</template>

<script setup lang="ts">
defineProps<{ section: 'stock' | 'receiving' }>()

const tabs = {
    stock: [
        { name: 'manager-inventory', label: 'Tồn kho' },
        { name: 'manager-ingredients', label: 'Nguyên liệu' },
    ],
    receiving: [
        { name: 'manager-purchase-orders', label: 'Yêu cầu nhập hàng' },
        { name: 'manager-stock-imports', label: 'Phiếu nhập kho' },
        { name: 'manager-supplier-prices', label: 'Giá nhập' },
    ],
}
</script>

<style scoped>
.warehouse-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
}

.warehouse-tabs a {
    padding: 8px 14px;
    border-radius: 8px;
    color: var(--color-brand-ink);
    font-weight: 600;
    text-decoration: none;
}

.warehouse-tabs a:hover,
.warehouse-tabs a.router-link-exact-active {
    background: var(--color-brand-ink);
    color: var(--color-white);
}
</style>
