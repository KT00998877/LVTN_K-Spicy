<template>
    <div class="manager-inventory">
        <header class="manager-heading"><div><span class="eyebrow">QUẢN LÝ NHÀ HÀNG</span><h2>Kho và nguyên liệu</h2><p>Theo dõi nguyên liệu và hoạt động nhập xuất kho.</p></div></header>
        <div class="toolbar">
            <div style="flex: 1;"></div>
            <span class="badge badge-info">Tính năng quản lý kho đang phát triển</span>
        </div>

        <!-- EXPORT — Xuất báo cáo tồn kho -->
        <section class="export-section" style="margin: 12px 0;">
            <ExportPanel :default-type="'inventory'" />
        </section>

        <div class="empty-state">
            <h3>Chưa có chức năng quản lý kho</h3>
            <p>Nhập kho, xuất kho và kiểm kê hiện chưa được triển khai.</p>
            <p>Bạn có thể xem <strong>tồn kho hiện tại</strong> qua báo cáo bên trên hoặc quản lý <router-link to="/staff/manager/menu">thực đơn</router-link>.</p>
        </div>
    </div>
</template>

<script setup lang="ts">
import ExportPanel from '@/components/report/ExportPanel.vue'
</script>

<style scoped>
.manager-inventory { display: flex; flex-direction: column; gap: 16px; }
.toolbar {
    display: flex;
    gap: 10px;
    align-items: center;
}
.empty-state {
    background: #fff;
    padding: 40px;
    border-radius: 12px;
    text-align: center;
    color: #6b7280;
}
.empty-state a { color: #7c3aed; }
.badge { padding: 4px 10px; border-radius: 12px; font-size: 12px; }
.badge-info { background: #e0e7ff; color: #4338ca; }
</style>
