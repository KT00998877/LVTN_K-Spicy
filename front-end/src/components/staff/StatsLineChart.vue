<template>
    <div class="stats-chart-wrapper" :aria-label="ariaLabel">
        <canvas ref="canvasEl" />
    </div>
</template>

<script setup lang="ts">
/**
 * StatsLineChart — wrapper around Chart.js line chart cho dashboard statistics.
 *  - Hiển thị doanh thu / số đơn theo thời gian.
 *  - Brand màu: chili red (#d71920) cho revenue.
 */
import { Chart, registerables, type ChartConfiguration } from 'chart.js'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

Chart.register(...registerables)

const props = defineProps<{
    labels: string[]
    data: number[]
    label: string
    ariaLabel: string
    /** Optional second dataset (vd: số đơn) - line chart thứ 2. */
    secondary?: { label: string; data: number[] } | null
    /** Format tooltip value, default: number.toLocaleString. */
    formatValue?: (n: number) => string
    height?: number
}>()

const canvasEl = ref<HTMLCanvasElement | null>(null)
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let chart: any = null

function defaultFormat(n: number): string {
    return n.toLocaleString('vi-VN')
}

function buildConfig(): ChartConfiguration {
    const datasets = [
        {
            label: props.label,
            data: props.data,
            borderColor: '#d71920',
            backgroundColor: 'rgba(215, 25, 32, 0.12)',
            fill: true,
            tension: 0.32,
            borderWidth: 2.5,
            pointRadius: 3,
            pointHoverRadius: 5,
            pointBackgroundColor: '#d71920',
            pointBorderColor: '#fff',
            pointBorderWidth: 1.5,
        },
    ]
    if (props.secondary) {
        datasets.push({
            label: props.secondary.label,
            data: props.secondary.data,
            borderColor: '#ffb000',
            backgroundColor: 'rgba(255, 176, 0, 0.08)',
            fill: false,
            tension: 0.32,
            borderWidth: 2,
            pointRadius: 2,
            pointHoverRadius: 4,
            pointBackgroundColor: '#ffb000',
            pointBorderColor: '#fff',
            pointBorderWidth: 1.5,
        })
    }
    return {
        type: 'line',
        data: { labels: props.labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: !!props.secondary,
                    position: 'top',
                    align: 'end',
                    labels: {
                        boxWidth: 14,
                        boxHeight: 8,
                        font: { size: 12, family: 'inherit' },
                        color: '#374151',
                    },
                },
                tooltip: {
                    backgroundColor: '#111111',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 10,
                    cornerRadius: 8,
                    titleFont: { size: 13, weight: 600 },
                    bodyFont: { size: 12 },
                    callbacks: {
                        label: (ctx) => {
                            const v = ctx.parsed.y ?? 0
                            const fmt = props.formatValue ?? defaultFormat
                            return `${ctx.dataset.label}: ${fmt(v)}`
                        },
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#6b7280', font: { size: 11 } },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: '#f3f4f6', drawTicks: false },
                    border: { display: false },
                    ticks: {
                        color: '#6b7280',
                        font: { size: 11 },
                        callback: (v) => Number(v).toLocaleString('vi-VN'),
                    },
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
        if (props.secondary && chart.data.datasets[1]) {
            chart.data.datasets[1].data = props.secondary.data
        }
        chart.update('none')
        return
    }
    chart = new Chart(canvasEl.value, buildConfig())
}

onMounted(() => {
    render()
})

watch(
    () => [props.labels, props.data, props.secondary?.data],
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
