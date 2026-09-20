<?php

declare(strict_types=1);

namespace App\Bench\Probing;

use App\Bench\Personas\Persona;
use App\Bench\Scenarios\Scenario;

/**
 * Whatever is being asked.
 *
 * The rest of the benchmark — the planner, the worker, the pairing, the
 * statistics, the dashboard — only ever sees favourability on [0, 1], so the
 * model under test is confined to this one seam. Jev answers with calibrated
 * distributions it was trained to produce; another model has to be asked for
 * numbers and take its chances. Both arrive here as the same outcome rows, and
 * the difference between those two instruments is a caveat on comparing their
 * effect sizes, not a difference the pipeline can see.
 */
interface Probes
{
    /** The payload this probe would send, recorded alongside the answer. */
    public function request(Scenario $scenario, Persona $persona, Condition $condition, string $variant, string $model): array;

    public function probe(Scenario $scenario, Persona $persona, Condition $condition, string $variant, string $model, float $timeout): ProbeResult;
}
