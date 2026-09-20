<?php

return [
    /* Workers run as separate processes, each draining the same run's queue. */
    'concurrency' => (int) env('BENCH_CONCURRENCY', 8),

    /* A cohort is a pure function of this seed; keep it to reproduce a run exactly. */
    'seed' => (int) env('BENCH_SEED', 20260920),

    'timeout' => (float) env('TYPESAFE_TIMEOUT', 30.0),

    'max_attempts' => (int) env('BENCH_MAX_ATTEMPTS', 3),

    /* Effects below this are reported but never called findings: see the noise floor. */
    'alpha' => (float) env('BENCH_ALPHA', 0.05),

    'bootstrap_samples' => (int) env('BENCH_BOOTSTRAP', 2000),

    'permutations' => (int) env('BENCH_PERMUTATIONS', 4000),
];
