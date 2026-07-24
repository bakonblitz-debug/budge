<template>
    <div>
        <!-- Y axis + plot -->
        <div class="d-flex">
            <!-- Y axis labels -->
            <div
                class="position-relative flex-shrink-0 text-caption text-medium-emphasis"
                style="width: 56px; height: 240px;"
            >
                <div
                    v-for="(tick, i) in yTickItems"
                    :key="i"
                    class="position-absolute"
                    style="right: 6px; transform: translateY(-50%); white-space: nowrap;"
                    :style="{ top: tick.y + 'px' }"
                >{{ fmtAxis(tick.value) }}</div>
            </div>

            <!-- SVG plot -->
            <div class="position-relative flex-grow-1" style="height: 240px;">
                <svg
                    viewBox="0 0 1000 240"
                    preserveAspectRatio="none"
                    style="position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible;"
                >
                    <!-- horizontal gridlines -->
                    <line
                        v-for="(tick, i) in yTickItems"
                        :key="'gl' + i"
                        x1="0" :y1="tick.y" x2="1000" :y2="tick.y"
                        stroke="currentColor" stroke-opacity="0.1"
                    />
                    <!-- zero baseline -->
                    <line
                        x1="0" :y1="zeroY" x2="1000" :y2="zeroY"
                        stroke="currentColor" stroke-opacity="0.3" stroke-dasharray="4 4"
                    />

                    <!-- bars (dimmed when another month is hovered) -->
                    <template v-for="(col, ci) in columns" :key="'col' + ci">
                        <rect
                            v-for="(bar, bi) in col.bars"
                            :key="'bar' + bi"
                            :x="bar.x"
                            :y="bar.y"
                            :width="bar.w"
                            :height="bar.h"
                            :fill="bar.color"
                            :fill-opacity="hoveredCol === null || hoveredCol === ci ? 0.85 : 0.25"
                            rx="2"
                        />
                    </template>
                </svg>

                <!-- hover hit-areas (one per month) + tooltip -->
                <div class="position-absolute d-flex" style="inset: 0;" @mouseleave="hoveredCol = null">
                    <div
                        v-for="(col, i) in columns"
                        :key="'hit' + i"
                        style="flex: 1 1 0; height: 100%; cursor: pointer;"
                        @mouseenter="hoveredCol = i"
                    />
                </div>
                <div v-if="hoveredCol !== null" class="position-absolute px-2 py-1 rounded text-caption" :style="tooltipStyle">
                    <div class="font-weight-bold mb-1">{{ columns[hoveredCol].label }}</div>
                    <div v-for="k in keys" :key="k.key" class="d-flex align-center" style="gap: 6px;">
                        <span :style="{ width: '10px', height: '10px', borderRadius: '2px', background: k.color, flexShrink: 0 }" />
                        <span class="text-medium-emphasis">{{ k.label }}</span>
                        <span class="ml-auto font-weight-medium">{{ fmtVal(series[hoveredCol].values[k.key]) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- X axis labels -->
        <div class="d-flex text-caption text-medium-emphasis mt-1" style="margin-left: 56px;">
            <div
                v-for="(col, i) in columns"
                :key="'xl' + i"
                class="text-center"
                :style="{ flex: '1 1 0', minWidth: 0 }"
            >{{ col.label }}</div>
        </div>

        <!-- Legend -->
        <div class="d-flex flex-wrap mt-2" style="margin-left: 56px; gap: 12px;">
            <div v-for="(entry, i) in legend" :key="'leg' + i" class="d-flex align-center text-caption" style="gap: 4px;">
                <div :style="{ width: '12px', height: '12px', borderRadius: '2px', background: entry.color, flexShrink: 0 }" />
                <span class="text-medium-emphasis">{{ entry.label }}</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { niceTicks, fmtAxis } from './ticks.js'

/**
 * props.series: Array<{ label: string, values: { income?: number, spending?: number, net?: number, [key: string]: number } }>
 *   Each entry is one column (e.g. a month). `values` is a map of series-key → amount.
 *   For the trend use-case, keys are 'income', 'spending', 'net'.
 *
 * props.mode: 'grouped' | 'stacked'  (default 'grouped')
 *
 * props.keys: Array<{ key: string, label: string, color: string }>
 *   Which keys to render and in which colour. Defaults to income/spending/net palette.
 */
const props = defineProps({
    series: {
        type: Array,
        default: () => [],
    },
    mode: {
        type: String,
        default: 'grouped',
    },
    keys: {
        type: Array,
        default: () => [
            { key: 'income',   label: 'Income',   color: '#43a047' },
            { key: 'spending', label: 'Spending',  color: '#e53935' },
            { key: 'net',      label: 'Net',       color: '#1e88e5' },
        ],
    },
})

// Hover: highlight one month (column) and show its exact values.
const hoveredCol = ref(null)

function fmtVal(v) {
    return Number(v || 0).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}

const tooltipStyle = computed(() => {
    const n = props.series.length
    if (hoveredCol.value === null || !n) return {}
    return {
        left: Math.min(88, Math.max(12, ((hoveredCol.value + 0.5) / n) * 100)) + '%',
        top: '4px',
        transform: 'translate(-50%, 0)',
        background: 'rgb(var(--v-theme-surface))',
        boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
        whiteSpace: 'nowrap',
        pointerEvents: 'none',
        zIndex: 2,
        minWidth: '150px',
    }
})

const SVG_W = 1000
const SVG_H = 240
const TOP = 8
const BOTTOM = 232

// Compute the domain by collecting all values across all columns × keys.
const allValues = computed(() => {
    const vals = [0]
    for (const col of props.series) {
        for (const k of props.keys) {
            const v = col.values?.[k.key]
            if (v != null) vals.push(v)
        }
        if (props.mode === 'stacked') {
            // stacked: the top of the positive stack and the bottom of the negative stack
            let pos = 0; let neg = 0
            for (const k of props.keys) {
                const v = col.values?.[k.key] ?? 0
                if (v >= 0) pos += v; else neg += v
            }
            vals.push(pos, neg)
        }
    }
    return vals
})

const domainMin = computed(() => Math.min(...allValues.value))
const domainMax = computed(() => Math.max(...allValues.value))

const tickValues = computed(() => niceTicks(domainMin.value, domainMax.value, 4))
const domMin = computed(() => Math.min(...tickValues.value))
const domMax = computed(() => Math.max(...tickValues.value))

function yCoord(v) {
    const range = domMax.value - domMin.value || 1
    return BOTTOM - ((v - domMin.value) / range) * (BOTTOM - TOP)
}

const zeroY = computed(() => yCoord(0))

const yTickItems = computed(() =>
    tickValues.value.map((v) => ({ value: v, y: yCoord(v) })),
)

// Column layout
const columns = computed(() => {
    const n = props.series.length
    if (!n) return []

    const colPad = 4      // gap between columns
    const barPad = 2      // gap between bars within a group
    const colW = (SVG_W - colPad * (n + 1)) / n
    const activeKeys = props.keys

    return props.series.map((col, ci) => {
        const colX = colPad + ci * (colW + colPad)
        let bars = []

        if (props.mode === 'grouped') {
            const bw = Math.max(1, (colW - barPad * (activeKeys.length - 1)) / activeKeys.length)
            activeKeys.forEach((kDef, bi) => {
                const v = col.values?.[kDef.key] ?? 0
                const x = colX + bi * (bw + barPad)
                const top = yCoord(Math.max(0, v))
                const bot = yCoord(Math.min(0, v))
                bars.push({ x, y: top, w: bw, h: Math.max(1, bot - top), color: kDef.color })
            })
        } else {
            // stacked: positive values stack up, negative stack down
            let posY = zeroY.value
            let negY = zeroY.value
            activeKeys.forEach((kDef) => {
                const v = col.values?.[kDef.key] ?? 0
                if (v === 0) return
                const height = Math.abs(yCoord(v) - yCoord(0))
                if (v > 0) {
                    bars.push({ x: colX, y: posY - height, w: colW, h: Math.max(1, height), color: kDef.color })
                    posY -= height
                } else {
                    bars.push({ x: colX, y: negY, w: colW, h: Math.max(1, height), color: kDef.color })
                    negY += height
                }
            })
        }

        return { label: col.label, bars }
    })
})

const legend = computed(() => props.keys.map((k) => ({ label: k.label, color: k.color })))
</script>
