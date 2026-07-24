<?php

use App\Support\Stats;

it('returns 0.0 for median/mean/stddev/cv of an empty list', function () {
    expect(Stats::median([]))->toBe(0.0)
        ->and(Stats::mean([]))->toBe(0.0)
        ->and(Stats::stddev([]))->toBe(0.0)
        ->and(Stats::cv([]))->toBe(0.0);
});

it('computes the median with the intdiv-midpoint rule for odd and even counts', function () {
    expect(Stats::median([5]))->toBe(5.0)
        ->and(Stats::median([3, 1, 2]))->toBe(2.0) // sorted [1,2,3], middle
        ->and(Stats::median([1, 2, 3, 4]))->toBe(2.5) // sorted, avg of the two middles
        ->and(Stats::median([4, 1, 3, 2]))->toBe(2.5);
});

it('computes the mean', function () {
    expect(Stats::mean([2, 4, 6]))->toBe(4.0)
        ->and(Stats::mean([5]))->toBe(5.0);
});

it('computes population standard deviation (divides by n, not n-1)', function () {
    // Values 2,4,4,4,5,5,7,9 → population variance 4, stddev 2 (textbook example).
    expect(Stats::stddev([2, 4, 4, 4, 5, 5, 7, 9]))->toBe(2.0)
        ->and(Stats::stddev([5]))->toBe(0.0) // fewer than 2 samples
        ->and(Stats::stddev([5, 5, 5]))->toBe(0.0); // no spread
});

it('computes coefficient of variation as stddev/mean, guarding zero-mean and <2 samples', function () {
    expect(Stats::cv([10, 10, 10]))->toBe(0.0) // no spread
        ->and(Stats::cv([5]))->toBe(0.0) // single sample
        ->and(Stats::cv([]))->toBe(0.0)
        ->and(Stats::cv([-1, 1]))->toBe(0.0); // mean is 0 → guarded, not a divide-by-zero

    // stddev([8,12]) = 2, mean = 10 → cv = 0.2
    expect(Stats::cv([8, 12]))->toBe(0.2);
});
