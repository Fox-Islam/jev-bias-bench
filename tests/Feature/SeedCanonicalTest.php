<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The loader restores the dump's own primary keys and rewinds the sequences to
 * match. Doing that while a run is being written hands the workers ids that are
 * already taken — and an earlier version of this command, told to make room,
 * deleted a run that was halfway through 12,000 calls. Hence the guards.
 */
final class SeedCanonicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_load_while_a_run_is_in_progress(): void
    {
        $this->makeRun('busy', 'running');

        $this->artisan('bench:seed-canonical')
            ->expectsOutputToContain('A run is in progress')
            ->assertExitCode(1);

        self::assertDatabaseHas('runs', ['name' => 'busy']);
    }

    public function test_it_refuses_to_overwrite_the_canonical_run_without_force(): void
    {
        $this->makeRun('jev-latest-deep', 'completed');

        $this->artisan('bench:seed-canonical')
            ->expectsOutputToContain('Already loaded')
            ->assertExitCode(1);
    }

    public function test_it_leaves_other_runs_alone(): void
    {
        $this->makeRun('jev-latest-deep', 'completed');

        // Parked well clear of the ids the dump restores, which is the same
        // thing the pre-flight check below tells a real user to do.
        $other = $this->makeRun('something-else', 'completed');
        $other->forceFill(['id' => 900_001])->save();

        $this->artisan('bench:seed-canonical', ['--force' => true])->assertExitCode(0);

        self::assertDatabaseHas('runs', ['name' => 'something-else']);
        self::assertDatabaseHas('runs', ['name' => 'jev-latest-deep']);
        self::assertSame(11984, Run::where('name', 'jev-latest-deep')->first()->probes()->count());
    }

    public function test_it_refuses_when_another_run_holds_an_id_the_dump_needs(): void
    {
        // The dump restores fixed primary keys; anyone who ran their own
        // benchmark first can be sitting on one of them.
        $squatter = $this->makeRun('mine', 'completed');
        $squatter->forceFill(['id' => 11])->save();

        $this->artisan('bench:seed-canonical')
            ->expectsOutputToContain('hold ids the dump needs')
            ->assertExitCode(1);

        self::assertDatabaseHas('runs', ['name' => 'mine']);
    }

    public function test_loading_leaves_the_sequences_ahead_of_the_rows(): void
    {
        $this->artisan('bench:seed-canonical')->assertExitCode(0);

        // An id handed out after the load must not collide with one the dump brought.
        $next = $this->makeRun('after-the-dump', 'completed');

        self::assertGreaterThan(
            Run::where('name', 'jev-latest-deep')->value('id'),
            $next->id,
            'The sequence was left behind the rows the dump inserted',
        );
    }

    private function makeRun(string $name, string $status): Run
    {
        return Run::create([
            'name' => $name,
            'preset' => 'jev-latest-deep',
            'design' => 'counterfactual',
            'seed' => 1,
            'provider' => 'typesafe',
            'model' => 'jev-latest',
            'scenario_keys' => [],
            'conditions' => [],
            'persona_count' => 0,
            'bases' => 1,
            'replicates' => 1,
            'status' => $status,
        ]);
    }
}
