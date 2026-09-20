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

    /*
     * Who is being measured. `jev` asks TypeSafe through the SDK and reads the
     * distributions it returns; `openrouter` puts the same scenarios to a chat
     * model and asks it to write the numbers down. The second is a weaker
     * instrument — see the note on OpenRouterProber — so effect sizes do not
     * compare across drivers, though the shape of the findings does.
     */
    'driver' => env('BENCH_DRIVER', 'jev'),

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('BENCH_OPENROUTER_MODEL', 'anthropic/claude-opus-5'),
        'reasoning_effort' => env('BENCH_REASONING_EFFORT'),
    ],
];
