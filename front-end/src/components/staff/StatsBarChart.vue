<template>
    <div class="stats-chart-wrapper" :aria-label="ariaLabel">
        <canvas ref="canvasEl" />
    </div>
</template>

<script setup lang="ts">
/**
 * StatsBarChart — Chart.js horizontal bar (indexAxis: 'y') cho top products / categories.
 *  - Hiển thị top món, top nguyên liệu, top danh mục.
 *  - Hỗ trợ tên (label) + giá trị.
 */
import { Chart, registerables, type ChartConfiguration } from 'chart.js'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

Chart.register(...registerables)

const props = defineProps<{
    labels: string[]
    data: number[]
    label: string
    ariaLabel: string
    /** Định dạng giá trị khi hiển thị (mặc định: locale number). */
    formatValue?: (n: number) => string
    /** Màu cột. Mặc định: chili red. */
    color?: string
    height?: number
}>()

const canvasEl = ref<HTMLCanvasElement | null>(null)
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let chart: any = null

function buildConfig(): ChartConfiguration {
    const color = props.color ?? '#d71920'
    return {
        type: 'bar',
        data: {
            labels: props.labels,
            datasets: [
                {
                    label: props.label,
                    data: props.data,
                    backgroundColor: color,
                    borderRadius: 6,
                    maxBarThickness: 22,
                },
            ],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#111111',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: (ctx) => {
                            const v = ctx.parsed.x ?? 0
                            const fmt = props.formatValue ?? ((n: number) => n.toLocaleString('vi-VN'))
                            return `${fmt(v)}`
                        },
                    },
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: '#f3f4f6' },
                    border: { display: false },
                    ticks: {
                        color: '#6b7280',
                        font: { size: 11 },
                        callback: (v) => Number(v).toLocaleString('vi-VN'),
                    },
                },
                y: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: '#111111', font: { size: 12, weight: 600 } },
                },
            },
        },
    }
}

function render(): void {
    if (!canvasEl.value) return
    if (chart) {
        chart.data.labels = props.labels
        chart.data.datasets[0].data = props.data
        chart.update('none')
        return
    }
    chart = new Chart(canvasEl.value, buildConfig())
}

onMounted(render)
watch(
    () => [props.labels, props.data],
    () => render(),
    { deep: true }
)
onBeforeUnmount(() => {
    if (chart) {
        chart.destroy()
        chart = null
    }
})
</script>

<style scoped>
.stats-chart-wrapper {
    position: relative;
    width: 100%;
    height: v-bind('props.height + "px"');
    min-height: 220px;
}
</style>
