<template>
    <div class="d-flex flex-wrap align-center" style="gap: 24px;">
        <!-- SVG donut -->
        <div class="flex-shrink-0" style="width: 160px; height: 160px;">
            <svg viewBox="0 0 160 160" style="width: 100%; height: 100%;">
                <!-- empty state ring when no data -->
                <circle
                    v-if="!resolvedSlices.length"
                    cx="80" cy="80" :r="radius"
                    fill="none"
                    stroke="currentColor"
                    stroke-opacity="0.1"
                    :stroke-width="thickness"
                />

                <!-- arc slices -->
                <path
                    v-for="(slice, i) in arcs"
                    :key="i"
                    :d="slice.d"
                    :fill="slice.color"
                    stroke="rgb(var(--v-theme-surface))"
                    stroke-width="2"
                    @mouseenter="hovered = i"
                    @mouseleave="hovered = null"
                    style="cursor: pointer; transition: fill-opacity 0.15s;"
                    :fill-opacity="hovered === null || hovered === i ? 0.85 : 0.45"
                />

                <!-- centre label: hovered slice value or total -->
                <text x="80" y="74" text-anchor="middle" dominant-baseline="middle" class="text-caption" font-size="11" fill="currentColor" opacity="0.7">
                    {{ hovered !== null ? resolvedSlices[hovered]?.label : 'Total' }}
                </text>
                <text x="80" y="90" text-anchor="middle" dominant-baseline="middle" font-size="14" font-weight="600" fill="currentColor">
                    {{ hovered !== null ? fmtValue(resolvedSlices[hovered]?.value) : fmtValue(total) }}
                </text>
            </svg>
        </div>

        <!-- Legend -->
        <div class="flex-grow-1" style="min-width: 120px;">
            <div
                v-if="!resolvedSlices.length"
                class="text-caption text-medium-emphasis"
            >No data</div>
            <div
                v-for="(slice, i) in resolvedSlices"
                :key="i"
                class="d-flex align-center mb-1"
                style="gap: 8px; cursor: pointer;"
                @mouseenter="hovered = i"
                @mouseleave="hovered = null"
            >
                <div
                    :style="{
                        width: '12px',
                        height: '12px',
                        borderRadius: '2px',
                        background: slice.color,
                        flexShrink: 0,
                        opacity: hovered === null || hovered === i ? 1 : 0.4,
                        transition: 'opacity 0.15s',
                    }"
                />
                <span
                    class="text-caption flex-grow-1"
                    :style="{ opacity: hovered === null || hovered === i ? 1 : 0.55 }"
                >{{ slice.label }}</span>
                <span class="text-caption font-weight-medium text-right" style="min-width: 60px; white-space: nowrap;">
                    {{ fmtValue(slice.value) }}
                </span>
                <span class="text-caption text-medium-emphasis" style="min-width: 36px; text-align: right;">
                    {{ pct(slice.value) }}%
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'

/**
 * props.slices: Array<{ label: string, value: number, color?: string }>
 *   Slices are rendered in the order given. Negative / zero values are filtered out.
 *
 * props.currency: string  ISO currency code for Intl.NumberFormat (default 'CAD').
 */
const props = defineProps({
    slices: {
        type: Array,
        default: () => [],
    },
    currency: {
        type: String,
        default: 'CAD',
    },
})

// Default colour palette (categorical, Vuetify-ish).
const DEFAULT_COLORS = [
    '#1e88e5',
    '#43a047',
    '#e53935',
    '#fb8c00',
    '#8e24aa',
    '#00acc1',
    '#f4511e',
    '#3949ab',
    '#00897b',
    '#c0ca33',
]

const radius = 58
const thickness = 28
const cx = 80
const cy = 80

const hovered = ref(null)

// Filter out non-positive and assign colours.
const resolvedSlices = computed(() =>
    props.slices
        .filter((s) => s.value > 0)
        .map((s, i) => ({
            ...s,
            color: s.color || DEFAULT_COLORS[i % DEFAULT_COLORS.length],
        })),
)

const total = computed(() => resolvedSlices.value.reduce((sum, s) => sum + s.value, 0))

/**
 * Build an SVG arc path for a donut slice.
 * startAngle / endAngle in radians, measured from -π/2 (top of circle).
 */
function arcPath(startAngle, endAngle, r, strokeW, cx, cy) {
    const innerR = r - strokeW / 2
    const outerR = r + strokeW / 2

    const cos = Math.cos
    const sin = Math.sin

    // Outer arc: start → end (large-arc-flag when > π)
    const ox1 = cx + outerR * cos(startAngle)
    const oy1 = cy + outerR * sin(startAngle)
    const ox2 = cx + outerR * cos(endAngle)
    const oy2 = cy + outerR * sin(endAngle)

    // Inner arc: end → start (reverse)
    const ix1 = cx + innerR * cos(endAngle)
    const iy1 = cy + innerR * sin(endAngle)
    const ix2 = cx + innerR * cos(startAngle)
    const iy2 = cy + innerR * sin(startAngle)

    const largeArc = endAngle - startAngle > Math.PI ? 1 : 0

    return [
        `M ${ox1} ${oy1}`,
        `A ${outerR} ${outerR} 0 ${largeArc} 1 ${ox2} ${oy2}`,
        `L ${ix1} ${iy1}`,
        `A ${innerR} ${innerR} 0 ${largeArc} 0 ${ix2} ${iy2}`,
        'Z',
    ].join(' ')
}

const arcs = computed(() => {
    const slices = resolvedSlices.value
    const tot = total.value
    if (!slices.length || tot === 0) return []

    const result = []
    let angle = -Math.PI / 2   // start at top

    for (const slice of slices) {
        const sweep = (slice.value / tot) * 2 * Math.PI
        const endAngle = angle + sweep

        // Skip invisible tiny slices (< 0.001 rad) to avoid degenerate paths.
        if (sweep > 0.001) {
            result.push({
                d: arcPath(angle, endAngle, radius, thickness, cx, cy),
                color: slice.color,
            })
        }
        angle = endAngle
    }
    return result
})

function fmtValue(v) {
    if (v == null) return '—'
    return Number(v).toLocaleString('en-CA', { style: 'currency', currency: props.currency, maximumFractionDigits: 0 })
}

function pct(v) {
    const t = total.value
    if (!t) return '0'
    return ((v / t) * 100).toFixed(1)
}
</script>
