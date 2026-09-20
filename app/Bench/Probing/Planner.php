<?php

declare(strict_types=1);

namespace App\Bench\Probing;

use App\Bench\Personas\Persona;
use App\Bench\Personas\PersonaGenerator;
use App\Bench\Scenarios\ScenarioRegistry;
use App\Models\Person;
use App\Models\Probe;
use App\Models\Run;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Writes out every call a run will make before any of them are sent.
 *
 * Planning up front rather than deciding as it goes is what makes a run
 * resumable and auditable: the full design exists in the database, an
 * interrupted run picks up exactly where it stopped, and the set of calls that
 * were meant to happen can be compared with the set that did.
 */
final class Planner
{
    public function plan(string $name, RunProfile $profile, int $seed, string $provider, string $model): Run
    {
        $run = Run::create([
            'name' => $name,
            'preset' => $profile->name,
            'design' => $profile->design,
            'seed' => $seed,
            'provider' => $provider,
            'model' => $model,
            'scenario_keys' => $profile->scenarioKeys,
            'conditions' => $profile->conditionValues(),
            'persona_count' => $profile->personas,
            'bases' => $profile->bases,
            'replicates' => $profile->replicates,
            'status' => 'planned',
        ]);

        $people = $this->people($run, $profile, $seed);
        $this->probes($run, $profile, $people, $seed);

        return $run->refresh();
    }

    /** @return array<int, int> persona index => database id */
    private function people(Run $run, RunProfile $profile, int $seed): array
    {
        $generator = new PersonaGenerator($seed);
        $cohort = $profile->isCounterfactual()
            ? $generator->counterfactualCohort($profile->bases, $profile->swept, $profile->anchorAtReference)
            : $generator->cohort($profile->personas);

        $rows = [];
        foreach ($cohort as $persona) {
            $rows[] = [
                'run_id' => $run->id,
                'idx' => $persona->idx,
                'full_name' => $persona->fullName(),
                'base_index' => $persona->baseIndex,
                'is_anchor' => $persona->isAnchor,
                'swapped_attribute' => $persona->swappedAttribute,
                'swapped_level' => $persona->swappedLevel,
                'profile' => json_encode($persona->toArray()),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            Person::insert($chunk);
        }

        $run->forceFill(['persona_count' => count($rows)])->save();

        return Person::where('run_id', $run->id)->orderBy('idx')->pluck('id', 'idx')->all();
    }

    /**
     * @param  array<int, int>  $people
     */
    private function probes(Run $run, RunProfile $profile, array $people, int $seed): void
    {
        $now = now();
        $batch = [];
        $indexes = array_keys($people);
        $anchors = $profile->isCounterfactual() ? $this->anchorIndexes($run) : [];

        foreach ($profile->scenarioKeys as $offset => $scenarioKey) {
            $scenario = ScenarioRegistry::get($scenarioKey);
            $variants = $this->variants($scenario->variantKeys(), $profile, $indexes, $seed + 500 + $offset, $offset);

            foreach ($indexes as $idx) {
                foreach ($profile->conditions as $condition) {
                    $this->add($batch, $this->row($run, $people[$idx], $scenarioKey, $condition->value, 0, $variants[$idx], $now));
                }
            }

            $repeated = in_array(Condition::Full, $profile->conditions, true)
                ? Condition::Full
                : $profile->conditions[array_key_last($profile->conditions)];

            $subjects = array_slice($indexes, 0, min($profile->replicateSubjects, count($indexes)));
            for ($replicate = 1; $replicate < $profile->replicates; $replicate++) {
                foreach ($subjects as $idx) {
                    $this->add($batch, $this->row($run, $people[$idx], $scenarioKey, $repeated->value, $replicate, $variants[$idx], $now));
                }
            }

            // Anchors get asked repeatedly in every condition, because every
            // swap in their base is measured against them and one unlucky
            // anchor answer would tilt all of those comparisons together.
            foreach ($anchors as $idx) {
                foreach ($profile->conditions as $condition) {
                    for ($replicate = 1; $replicate < $profile->anchorReplicates; $replicate++) {
                        $this->add($batch, $this->row($run, $people[$idx], $scenarioKey, $condition->value, $replicate, $variants[$idx], $now));
                    }
                }
            }
        }

        // Workers claim probes in id order, so rows written next to each other
        // run next to each other. Left in planning order that puts every
        // repeat of a cell in one tight, cache-warm cluster — and the spread of
        // those repeats is the null every finding is tested against. Measured
        // both ways, a clustered null understates a real difference by 1.9x on
        // Jev and 3.3x on Claude Opus 5, which is enough to turn noise into
        // findings. Shuffling scatters each cell's repeats across the whole run,
        // so the null covers the same span of time the comparisons do.
        $order = new Randomizer(new Mt19937($seed + 11));

        DB::transaction(function () use ($batch, $order) {
            foreach (array_chunk($order->shuffleArray(array_values($batch)), 500) as $chunk) {
                Probe::insert($chunk);
            }
        });
    }

    /**
     * Keyed by the cell it fills, because an anchor that is also a replicate
     * subject would otherwise be asked for the same cell twice — once by each
     * block — and collide on the unique index.
     */
    private function add(array &$batch, array $row): void
    {
        $batch[implode('|', [$row['persona_id'], $row['scenario_key'], $row['condition'], $row['replicate']])] = $row;
    }

    /** @return list<int> the cohort positions of the anchor people */
    private function anchorIndexes(Run $run): array
    {
        return Person::where('run_id', $run->id)->where('is_anchor', true)->orderBy('idx')->pluck('idx')->all();
    }

    /**
     * Which case each person's probe uses.
     *
     * In a counterfactual run everyone built from the same anchor must face the
     * identical case, or the pair is no longer a pair. The variant is therefore
     * fixed per base and rotated across bases and scenarios, so the run still
     * covers weak, mid and strong without ever splitting a pair across two of
     * them. A factorial run has no pairs to protect and balances the variants
     * across people instead.
     *
     * @param  list<string>  $variantKeys
     * @param  list<int>  $indexes
     * @return array<int, string>
     */
    private function variants(array $variantKeys, RunProfile $profile, array $indexes, int $seed, int $offset): array
    {
        if ($profile->isCounterfactual()) {
            $perBase = max(1, intdiv(count($indexes), max(1, $profile->bases)));
            $out = [];
            foreach ($indexes as $position => $idx) {
                $base = intdiv($position, $perBase);
                $out[$idx] = $variantKeys[($base + $offset) % count($variantKeys)];
            }

            return $out;
        }

        $pool = [];
        while (count($pool) < count($indexes)) {
            $pool = array_merge($pool, $variantKeys);
        }
        $shuffled = (new Randomizer(new Mt19937($seed)))->shuffleArray(array_slice($pool, 0, count($indexes)));

        return array_combine($indexes, $shuffled);
    }

    private function row(Run $run, int $personId, string $scenarioKey, string $condition, int $replicate, string $variant, $now): array
    {
        return [
            'run_id' => $run->id,
            'persona_id' => $personId,
            'scenario_key' => $scenarioKey,
            'condition' => $condition,
            'replicate' => $replicate,
            'case_variant' => $variant,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
