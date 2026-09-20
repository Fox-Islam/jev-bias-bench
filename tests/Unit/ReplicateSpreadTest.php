<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bench\Probing\Planner;
use App\Bench\Probing\RunProfile;
use App\Models\Person;
use App\Models\Probe;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The repeats of a cell are the null that every finding is tested against, so
 * they have to sample the same conditions the comparisons do. Planned in order
 * they land adjacent, run adjacent, and measure only how much the model varies
 * within one cache-warm burst — which is 1.9x tighter than reality on Jev and
 * 3.3x on Claude, enough to promote noise to a finding.
 */
final class ReplicateSpreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cells_repeats_are_not_planned_next_to_each_other(): void
    {
        $run = app(Planner::class)->plan(
            name: 'spread',
            profile: RunProfile::named('smoke'),
            seed: 4242,
            provider: 'typesafe',
            model: 'jev-latest',
        );

        $positions = [];
        foreach (Probe::where('run_id', $run->id)->orderBy('id')->get()->values() as $position => $probe) {
            $cell = implode('|', [$probe->persona_id, $probe->scenario_key, $probe->condition, $probe->case_variant]);
            $positions[$cell][] = $position;
        }

        $gaps = [];
        foreach ($positions as $cell => $at) {
            if (count($at) < 2) {
                continue;
            }
            for ($i = 1; $i < count($at); $i++) {
                $gaps[] = $at[$i] - $at[$i - 1];
            }
        }

        self::assertNotEmpty($gaps, 'The smoke profile should plan some repeated cells');
        self::assertGreaterThan(
            1,
            array_sum($gaps) / count($gaps),
            'Repeats are planned adjacently, so they will run as one burst and the null will be too tight',
        );
    }

    public function test_the_same_seed_still_plans_the_same_run(): void
    {
        $first = $this->planned('one');
        $second = $this->planned('two');

        self::assertSame($first, $second, 'Shuffling must stay a pure function of the seed');
    }

    /** @return list<string> */
    private function planned(string $name): array
    {
        $run = app(Planner::class)->plan($name, RunProfile::named('smoke'), 99, 'typesafe', 'jev-latest');

        // Keyed on the persona's index in the cohort, not its row id, which the
        // database hands out afresh for every planned run.
        $idx = Person::where('run_id', $run->id)->pluck('idx', 'id');

        return Probe::where('run_id', $run->id)->orderBy('id')->get()
            ->map(fn (Probe $p) => implode('|', [$idx[$p->persona_id], $p->scenario_key, $p->condition, $p->replicate]))
            ->all();
    }
}
