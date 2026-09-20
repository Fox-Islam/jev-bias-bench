<?php

declare(strict_types=1);

namespace App\Bench\Analysis;

use App\Bench\Scenarios\ScenarioRegistry;
use App\Models\Outcome;
use App\Models\Person;
use App\Models\Run;

/**
 * Every answered question of a run, flattened into rows the analysis can slice.
 *
 * Loading it all once and working in memory is deliberate: the tests below
 * re-read the same values thousands of times, and a bias run is small enough
 * (tens of thousands of rows at the largest profile) that the alternative is
 * thousands of round trips to Postgres for no benefit.
 */
final class Dataset
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<int, array<string, string>> persona id => factor levels */
    public array $factors = [];

    /** @var array<int, string> persona id => display name */
    public array $names = [];

    /** @var array<int, array{base: int, anchor: bool, attribute: ?string, level: ?string}> */
    public array $pairs = [];

    /** @var array<int, int> base index => the anchor persona's id */
    public array $anchors = [];

    /** @var array<string, int> favourable direction per "scenario.question" */
    public array $direction = [];

    public function __construct(public readonly Run $run)
    {
        foreach (Person::where('run_id', $run->id)->get() as $person) {
            $this->factors[$person->id] = $person->factors();
            $this->names[$person->id] = $person->full_name;
            $this->pairs[$person->id] = [
                'base' => (int) $person->base_index,
                'anchor' => (bool) $person->is_anchor,
                'attribute' => $person->swapped_attribute,
                'level' => $person->swapped_level,
            ];

            if ($person->is_anchor) {
                $this->anchors[(int) $person->base_index] = $person->id;
            }
        }

        foreach (ScenarioRegistry::all() as $scenario) {
            foreach ($scenario->questions as $question) {
                $this->direction[$scenario->key.'.'.$question->key] = $question->favourable;
            }
        }

        Outcome::where('run_id', $run->id)
            ->select(['persona_id', 'scenario_key', 'question_key', 'condition', 'replicate', 'case_variant', 'kind', 'value', 'confidence'])
            ->orderBy('id')
            ->chunk(5000, function ($chunk) {
                foreach ($chunk as $outcome) {
                    $key = $outcome->scenario_key.'.'.$outcome->question_key;
                    $favourable = ($this->direction[$key] ?? 1) === 1
                        ? (float) $outcome->value
                        : 1.0 - (float) $outcome->value;

                    $this->rows[] = [
                        'persona_id' => $outcome->persona_id,
                        'scenario' => $outcome->scenario_key,
                        'question' => $outcome->question_key,
                        'measure' => $key,
                        'condition' => $outcome->condition,
                        'replicate' => (int) $outcome->replicate,
                        'variant' => $outcome->case_variant,
                        'kind' => $outcome->kind,
                        'value' => (float) $outcome->value,
                        'favourability' => $favourable,
                        'confidence' => $outcome->confidence === null ? null : (float) $outcome->confidence,
                    ];
                }
            });
    }

    /** @return list<array<string, mixed>> */
    public function where(callable $predicate): array
    {
        return array_values(array_filter($this->rows, $predicate));
    }

    /** The main design: one observation per person per cell, replicates excluded. */
    public function primary(): array
    {
        return $this->where(fn (array $row) => $row['replicate'] === 0);
    }

    /** @return list<string> */
    public function measures(): array
    {
        $keys = [];
        foreach ($this->rows as $row) {
            $keys[$row['measure']] = true;
        }

        return array_keys($keys);
    }

    /** @return list<string> */
    public function conditions(): array
    {
        $keys = [];
        foreach ($this->rows as $row) {
            $keys[$row['condition']] = true;
        }

        return array_keys($keys);
    }

    public function factor(int $personaId, string $name): ?string
    {
        return $this->factors[$personaId][$name] ?? null;
    }

    public function isCounterfactual(): bool
    {
        return $this->run->design === 'counterfactual';
    }

    /** The persona this one is a one-attribute copy of, or null for an anchor. */
    public function anchorOf(int $personaId): ?int
    {
        $pair = $this->pairs[$personaId] ?? null;

        return $pair === null || $pair['anchor'] ? null : ($this->anchors[$pair['base']] ?? null);
    }
}
