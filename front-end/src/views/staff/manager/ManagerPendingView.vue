<template>
    <section>
        <header class="manager-heading"><div><span class="eyebrow">QUẢN LÝ NHÀ HÀNG</span><h2>{{ route.meta.title }}</h2><p>{{ route.meta.subtitle }}</p></div></header>
        <div class="manager-notice"><h3>Chức năng chưa triển khai</h3><p>Mục này đã được bố trí trong menu quản lý. Các thao tác và dữ liệu sẽ được bổ sung khi triển khai chức năng.</p><RouterLink to="/staff/manager/dashboard" class="manager-button">Về tổng quan</RouterLink></div>
    </section>
</template>
<script setup lang="ts">
import { RouterLink, useRoute } from 'vue-router'
const route = useRoute()
</script>
