<?php

namespace App\Support;

/**
 * Shared basic statistics used across the analyzer/detector services:
 * population median/mean/stddev, and coefficient of variation (stddev/mean).
 *
 * Convention every former per-service copy already followed: empty input
 * always returns 0.0 (never null/NaN); stddev is the POPULATION variant
 * (divides by n, not n-1).
 */
class Stats
{
    /** @param  array<int, float|int>  $values */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** @param  array<int, float|int>  $values */
    public static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /** Population standard deviation (divides by n). @param  array<int, float|int>  $values */
    public static function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = self::mean($values);
        $variance = array_sum(array_map(fn ($v): float => ($v - $mean) ** 2, $values)) / $n;

        return sqrt($variance);
    }

    /**
     * Coefficient of variation (stddev / mean); 0.0 when mean is 0 or fewer
     * than 2 samples (never a divide-by-zero).
     *
     * @param  array<int, float|int>  $values
     */
    public static function cv(array $values): float
    {
        $mean = self::mean($values);
        if (count($values) < 2 || $mean == 0.0) {
            return 0.0;
        }

        return self::stddev($values) / $mean;
    }
}
