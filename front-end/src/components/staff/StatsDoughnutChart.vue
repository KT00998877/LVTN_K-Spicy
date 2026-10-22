<template>
    <div class="stats-chart-wrapper" :aria-label="ariaLabel">
        <canvas ref="canvasEl" />
    </div>
</template>

<script setup lang="ts">
/**
 * StatsDoughnutChart — Chart.js doughnut/pie cho payment methods, order status.
 *  - Mỗi slice có màu riêng (brand palette: chili red, golden, dark red, ...).
 *  - Click vào slice có thể được dùng để filter (tuỳ parent component).
 */
import { Chart, registerables, type ChartConfiguration } from 'chart.js'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

Chart.register(...registerables)

const props = defineProps<{
    labels: string[]
    data: number[]
    ariaLabel: string
    /** Optional màu tuỳ chỉnh theo thứ tự labels. */
    colors?: string[]
    /** Center text (vd: tổng tiền). */
    centerLabel?: string
    centerSubLabel?: string
    height?: number
}>()

const canvasEl = ref<HTMLCanvasElement | null>(null)
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let chart: any = null

const DEFAULT_COLORS = ['#d71920', '#ffb000', '#245440', '#5A3825', '#3B5BAB', '#8b5cf6', '#a80f17', '#1f8a4c']

function buildConfig(): ChartConfiguration {
    const colors = (props.colors && props.colors.length === props.data.length)
        ? props.colors
        : props.data.map((_, i) => DEFAULT_COLORS[i % DEFAULT_COLORS.length] ?? '#d71920')

    return {
        type: 'doughnut',
        data: {
            labels: props.labels,
            datasets: [
                {
                    data: props.data,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 8,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            // cast to any: chart.js v4 doughnut cutout is recognised at runtime but typing is strict.
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            ...({ cutout: '60%' } as any),
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 12,
                        padding: 12,
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
                    callbacks: {
                        label: (ctx) => {
                            const v = ctx.parsed
                            const total = props.data.reduce((s, x) => s + x, 0)
                            const pct = total > 0 ? ((v / total) * 100).toFixed(1) : '0.0'
                            return `${ctx.label}: ${v.toLocaleString('vi-VN')} (${pct}%)`
                        },
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
