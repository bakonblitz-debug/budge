/**
 * Shared chart utilities for hand-rolled SVG charts.
 */

/**
 * Generate "nice" rounded tick values spanning [min, max].
 * @param {number} min
 * @param {number} max
 * @param {number} count  target number of ticks
 * @returns {number[]}
 */
export function niceTicks(min, max, count = 4) {
    const range = max - min || 1
    const rawStep = range / count
    const mag = Math.pow(10, Math.floor(Math.log10(rawStep)))
    const norm = rawStep / mag
    const step = (norm < 1.5 ? 1 : norm < 3 ? 2 : norm < 7 ? 5 : 10) * mag
    const start = Math.floor(min / step) * step
    const end = Math.ceil(max / step) * step
    const ticks = []
    for (let v = start; v <= end + step / 1000; v += step) ticks.push(Math.round(v * 100) / 100)
    return ticks
}

/**
 * Compact dollar label for axes: $5.1k, $0, -$2k.
 * @param {number} v
 * @returns {string}
 */
export function fmtAxis(v) {
    const n = Number(v || 0)
    const sign = n < 0 ? '-' : ''
    const abs = Math.abs(n)
    if (abs >= 1000) return sign + '$' + (abs / 1000).toFixed(abs >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'k'
    return sign + '$' + abs.toFixed(0)
}
