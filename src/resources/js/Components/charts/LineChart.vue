<template>
    <div>
        <div class="d-flex">
            <!-- Y axis (dollar scale) -->
            <div class="position-relative flex-shrink-0 text-caption text-medium-emphasis" style="width: 56px; height: 240px;">
                <div
                    v-for="(tick, i) in computedYTicks"
                    :key="i"
                    class="position-absolute"
                    style="right: 6px; transform: translateY(-50%); white-space: nowrap;"
                    :style="{ top: tick.y + 'px' }"
                >{{ fmtAxis(tick.value) }}</div>
            </div>

            <!-- Plot -->
            <div
                ref="plot"
                class="position-relative flex-grow-1"
                style="height: 240px; cursor: crosshair;"
                @mousemove="onHover"
                @mouseleave="hover = null"
            >
                <svg
                    viewBox="0 0 1000 240"
                    preserveAspectRatio="none"
                    style="position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible;"
                >
                    <!-- horizontal gridlines at each Y tick -->
                    <line
                        v-for="(tick, i) in computedYTicks"
                        :key="i"
                        x1="0" :y1="tick.y" x2="1000" :y2="tick.y"
                        stroke="currentColor" stroke-opacity="0.1"
                    />
                    <!-- zero baseline -->
                    <line x1="0" :y1="computedZeroY" x2="1000" :y2="computedZeroY" stroke="currentColor" stroke-opacity="0.3" stroke-dasharray="4 4" />
                    <polyline :points="svgPoints" fill="none" :stroke="stroke" stroke-width="2" />
                    <!-- hover vertical guide -->
                    <line v-if="hover" :x1="hover.svgX" y1="0" :x2="hover.svgX" y2="240" stroke="currentColor" stroke-opacity="0.4" />
                </svg>

                <!-- markers -->
                <template v-for="(marker, i) in resolvedMarkers" :key="i">
                    <div
                        class="position-absolute rounded-circle"
                        :style="dotStyle(marker.coord, marker.color)"
                        :title="marker.title"
                    />
                </template>

                <!-- hover marker + tooltip -->
                <div
                    v-if="hover"
                    class="position-absolute rounded-circle"
                    :style="dotStyle(hover, stroke)"
                />
                <div v-if="hover" class="position-absolute px-2 py-1 rounded text-caption" :style="tooltipStyle">
                    <div class="font-weight-bold">{{ hoverLabel }}</div>
                    <div class="text-medium-emphasis">{{ hover.label }}</div>
                </div>
            </div>
        </div>

        <!-- X axis (dates) -->
        <div class="d-flex justify-space-between text-caption text-medium-emphasis mt-1" style="margin-left: 56px;">
            <span v-for="(tick, i) in computedXTicks" :key="i">{{ tick }}</span>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'

/**
 * props.points: Array<{ x: number (0–1000 SVG units or index), y: number, label: string, value: number }>
 *   Alternatively pass raw data as `series` and the component builds coords internally.
 *
 * Simplest usage: pass `series` (array of { date, balance }) and let the component
 * compute everything. For Forecast/Index we pass pre-computed coords.
 *
 * Required: either `points` (pre-computed SVG coords) or `series` (raw {value, label} array).
 *
 * props.yTicks: Array<{ value: number, y: number }> — optional override
 * props.xTicks: Array<string> — optional override (labels for X axis)
 * props.zeroY: number — optional override (SVG y coordinate of zero line)
 * props.stroke: string — line colour
 * props.markers: Array<{ coord: { svgX, svgY, x, y }, color: string, title: string }>
 * props.hoverFormat: (point) => string — formats hover value label
 */
const props = defineProps({
    // Pre-computed SVG coords: [{svgX, svgY, x, y (pct), label, value}]
    points: {
        type: Array,
        default: null,
    },
    yTicks: {
        type: Array,
        default: null,
    },
    xTicks: {
        type: Array,
        default: null,
    },
    zeroY: {
        type: Number,
        default: null,
    },
    stroke: {
        type: String,
        default: '#43a047',
    },
    // markers: [{coord: {svgX, svgY, x (pct), y (px)}, color, title}]
    markers: {
        type: Array,
        default: () => [],
    },
    // hoverFormat(point) => string
    hoverFormat: {
        type: Function,
        default: null,
    },
})

// Use provided ticks/zeroY or sensible fallbacks
const computedYTicks = computed(() => props.yTicks || [])
const computedXTicks = computed(() => props.xTicks || [])
const computedZeroY = computed(() => props.zeroY != null ? props.zeroY : 120)

// SVG polyline points string from props.points
const svgPoints = computed(() => {
    if (!props.points) return ''
    return props.points.map((p) => `${p.svgX},${p.svgY}`).join(' ')
})

// Markers: only those whose coord is non-null
const resolvedMarkers = computed(() =>
    props.markers.filter((m) => m.coord != null),
)

// Hover
const plot = ref(null)
const hover = ref(null)

function onHover(e) {
    if (!plot.value || !props.points || !props.points.length) return
    const rect = plot.value.getBoundingClientRect()
    const frac = (e.clientX - rect.left) / rect.width
    const pts = props.points
    const idx = Math.max(0, Math.min(pts.length - 1, Math.round(frac * (pts.length - 1))))
    hover.value = pts[idx]
}

const hoverLabel = computed(() => {
    if (!hover.value) return ''
    if (props.hoverFormat) return props.hoverFormat(hover.value)
    return String(hover.value.value ?? '')
})

function dotStyle(c, color) {
    return {
        left: c.x + '%',
        top: c.y + 'px',
        width: '10px',
        height: '10px',
        background: color,
        transform: 'translate(-50%, -50%)',
        boxShadow: '0 0 0 2px rgb(var(--v-theme-surface))',
        pointerEvents: 'none',
    }
}

const tooltipStyle = computed(() => {
    if (!hover.value) return {}
    return {
        left: Math.min(88, Math.max(12, hover.value.x)) + '%',
        top: Math.max(0, hover.value.y - 14) + 'px',
        transform: 'translate(-50%, -100%)',
        background: 'rgb(var(--v-theme-surface))',
        boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
        whiteSpace: 'nowrap',
        pointerEvents: 'none',
        zIndex: 2,
    }
})

// Compact dollar label for the axis: $5.1k, $0, -$2k.
function fmtAxis(v) {
    const n = Number(v || 0)
    const sign = n < 0 ? '-' : ''
    const abs = Math.abs(n)
    if (abs >= 1000) return sign + '$' + (abs / 1000).toFixed(abs >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'k'
    return sign + '$' + abs.toFixed(0)
}
</script>
