<?php

declare(strict_types=1);

/**
 * Performance benchmark for EzPhp\Cache\ArrayDriver.
 *
 * Measures the overhead of cache set, get, has, and remember operations
 * using the in-memory ArrayDriver — no filesystem or Redis involved.
 *
 * Exits with code 1 if the per-iteration time exceeds the defined threshold,
 * allowing CI to detect performance regressions automatically.
 *
 * Usage:
 *   php benchmarks/array-driver.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use EzPhp\Cache\ArrayDriver;

const ITERATIONS = 10000;
const OPS_PER_ITER = 6;
const THRESHOLD_MS = 0.5; // per-iteration upper bound in milliseconds

// ── Benchmark ─────────────────────────────────────────────────────────────────

$cache = new ArrayDriver();

// Pre-seed a value for get/has hits
$cache->set('user:1', ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'], 300);

// Warm-up
$cache->get('user:1');
$cache->has('user:1');
$cache->set('temp', 'value', 60);

$start = hrtime(true);

for ($i = 0; $i < ITERATIONS; $i++) {
    // Cache hit
    $cache->get('user:1');
    // Cache miss
    $cache->get('user:missing', null);
    // Has check
    $cache->has('user:1');
    // Set with TTL
    $cache->set('iter:' . ($i % 100), $i, 60);
    // Remember hit (cached)
    $cache->remember('user:1', 300, static fn () => ['id' => 1]);
    // Remember miss (computes)
    $cache->remember('computed:' . $i, 60, static fn () => $i * 2);
}

$end = hrtime(true);

$totalMs = ($end - $start) / 1_000_000;
$perIter = $totalMs / ITERATIONS;

echo sprintf(
    "Cache ArrayDriver Benchmark\n" .
    "  Operations per iter  : %d (get hit, get miss, has, set, remember hit, remember miss)\n" .
    "  Iterations           : %d\n" .
    "  Total time           : %.2f ms\n" .
    "  Per iteration        : %.3f ms\n" .
    "  Threshold            : %.1f ms\n",
    OPS_PER_ITER,
    ITERATIONS,
    $totalMs,
    $perIter,
    THRESHOLD_MS,
);

if ($perIter > THRESHOLD_MS) {
    echo sprintf(
        "FAIL: %.3f ms exceeds threshold of %.1f ms\n",
        $perIter,
        THRESHOLD_MS,
    );
    exit(1);
}

echo "PASS\n";
exit(0);
