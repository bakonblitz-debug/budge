<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class)->in('Feature');
uses(Tests\TestCase::class)->in('Unit');

expect()->extend('toBeWithin', function (float $expected, float $delta = 0.01) {
    return $this->toBeGreaterThanOrEqual($expected - $delta)
        ->toBeLessThanOrEqual($expected + $delta);
});

/**
 * Path to a test fixture. Real bank-statement PDFs are deliberately kept out of
 * the repo (they hold real financial data), so when a fixture is absent the
 * caller's test skips rather than erroring — a fresh clone stays green.
 *
 * ponytail: single-point guard — every fixture-dependent test routes through
 * here, so no per-file skip guards. Add synthetic sample PDFs to tests/Fixtures/
 * later to actually exercise the NBC parsers.
 */
function fixturePath(string $name): string
{
    $path = __DIR__.'/Fixtures/'.$name;

    if (! file_exists($path)) {
        test()->markTestSkipped("Statement fixture '{$name}' is not present (real bank PDFs are kept out of the repo).");
    }

    return $path;
}
