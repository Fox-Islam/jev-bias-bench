<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bench\Probing\BatchRunner;
use App\Bench\Probing\OpenRouterProber;
use App\Models\Person;
use App\Models\Probe;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The batch path writes answers straight onto probes without going through the
 * worker, so the bookkeeping it does instead is worth pinning: a run is several
 * batches, the ids accumulate, and a probe already sent must never be sent
 * twice.
 */
final class BatchRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_ids_accumulate_across_chunks(): void
    {
        $run = $this->makeRun();
        $runner = $this->runner();

        $run->forceFill(['notes' => json_encode(['batches' => ['a', 'b']])])->save();

        self::assertSame(['a', 'b'], $runner->batchIds($run));
    }

    public function test_a_run_submitted_before_chunking_still_reads(): void
    {
        $run = $this->makeRun();
        $run->forceFill(['notes' => json_encode(['batch_id' => 'only-one'])])->save();

        self::assertSame(['only-one'], $this->runner()->batchIds($run));
    }

    public function test_a_run_with_no_batches_reads_as_empty(): void
    {
        self::assertSame([], $this->runner()->batchIds($this->makeRun()));
    }

    public function test_pending_ignores_probes_already_sent(): void
    {
        $run = $this->makeRun();
        $runner = $this->runner();

        $this->probe($run, 1, 'pending');
        $this->probe($run, 2, 'batched');
        $this->probe($run, 3, 'done');
        $this->probe($run, 4, 'retry');

        self::assertSame(2, $runner->pending($run), 'Only pending and retry are still owed to a batch');
    }

    private function runner(): BatchRunner
    {
        return new BatchRunner(
            apiKey: 'test',
            baseUrl: 'https://example.invalid/api/v1',
            prober: new OpenRouterProber(apiKey: 'test', baseUrl: 'https://example.invalid/api/v1'),
        );
    }

    private function makeRun(): Run
    {
        return Run::create([
            'name' => 'batched', 'preset' => 'smoke', 'design' => 'counterfactual', 'seed' => 1,
            'provider' => 'openrouter', 'model' => 'anthropic/claude-opus-5',
            'scenario_keys' => ['hiring_screen'], 'conditions' => ['full'],
            'persona_count' => 1, 'bases' => 1, 'replicates' => 1, 'status' => 'planned',
        ]);
    }

    private function probe(Run $run, int $personaOffset, string $status): void
    {
        $person = Person::create([
            'run_id' => $run->id, 'idx' => $personaOffset, 'full_name' => 'Test Person',
            'base_index' => 0, 'is_anchor' => true, 'profile' => ['idx' => $personaOffset],
        ]);

        Probe::create([
            'run_id' => $run->id, 'persona_id' => $person->id, 'scenario_key' => 'hiring_screen',
            'condition' => 'full', 'replicate' => 0, 'case_variant' => 'mid', 'status' => $status,
        ]);
    }
}
