<?php

declare(strict_types=1);

namespace App\Bench\Personas;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Builds the two kinds of cohort the benchmark uses. Both are pure functions of
 * their seed, so a run can be regenerated exactly from the number in its record.
 *
 * The counterfactual cohort is the one that does the work. It starts from a
 * handful of anchor people and, for each attribute, produces a copy that differs
 * in that attribute and in nothing else — down to the same name, except for the
 * two attributes a name necessarily carries. Every comparison is then between a
 * person and their own anchor, which takes the variation between people out of
 * the measurement entirely and leaves only what the swap did.
 *
 * The factorial cohort draws everything independently and balanced. It cannot
 * match the counterfactual design for power, but it is the only one that
 * supports asking about combinations of attributes rather than one at a time.
 */
final class PersonaGenerator
{
    public function __construct(private readonly int $seed) {}

    /**
     * Anchors plus one single-attribute swap per level.
     *
     * @param  list<string>  $swept  attributes to vary; defaults to all of them
     * @return list<Persona>
     */
    public function counterfactualCohort(int $bases, ?array $swept = null, bool $anchorAtReference = false): array
    {
        $swept ??= AttributeCatalogue::sweepable();
        $names = new Randomiser($this->seed + 77);
        $people = [];
        $idx = 0;

        for ($base = 0; $base < $bases; $base++) {
            $anchor = $this->anchor($base, $idx++, $swept, $names, $anchorAtReference);
            $people[] = $anchor;

            foreach ($swept as $attribute) {
                foreach (AttributeCatalogue::levels($attribute) as $level) {
                    if ($level === AttributeCatalogue::reference($attribute)) {
                        continue;   // that is the anchor
                    }

                    $people[] = $anchor->withAttribute($idx++, $attribute, $level, $names);
                }
            }
        }

        return $people;
    }

    /**
     * The person every swap in a base is measured against: swept attributes at
     * their reference level, everything else drawn for this base, so two bases
     * differ in the background the swaps happen against.
     *
     * With `$atReference` every attribute sits at its reference whether or not
     * this run sweeps it, which is what a run that sweeps everything produces
     * anyway. It exists so that a targeted re-run of two or three attributes
     * measures them against the same person the full run did, and its numbers
     * can be read next to that run's rather than only against themselves.
     *
     * @param  list<string>  $swept
     */
    private function anchor(int $base, int $idx, array $swept, Randomiser $names, bool $atReference = false): Persona
    {
        $draw = new Randomizer(new Mt19937($this->seed + 900 + $base));
        $attributes = [];

        foreach (AttributeCatalogue::keys() as $attribute) {
            $levels = AttributeCatalogue::levels($attribute);
            $attributes[$attribute] = ($atReference || in_array($attribute, $swept, true))
                ? AttributeCatalogue::reference($attribute)
                : $levels[$draw->getInt(0, count($levels) - 1)];
        }

        [$first, $last] = $names->name($attributes['name_culture'], $attributes['gender']);

        // An anchor sits at the reference band for anything being swept
        // numerically, and somewhere unremarkable otherwise.
        $height = ($atReference || in_array('height_band', $swept, true)) ? 172 : $draw->getInt(160, 186);
        $age = ($atReference || in_array('age_band', $swept, true))
            ? AttributeCatalogue::NUMERIC_SWEEP['age_band']['values'][AttributeCatalogue::reference('age_band')]
            : $draw->getInt(27, 58);
        $weight = ($atReference || in_array('bmi_band', $swept, true))
            ? (int) round(22.5 * ($height / 100) ** 2)
            : $draw->getInt(58, 96);

        return new Persona(
            idx: $idx,
            firstName: $first,
            lastName: $last,
            attributes: $attributes,
            age: $age,
            heightCm: $height,
            weightKg: $weight,
            baseIndex: $base,
            isAnchor: true,
        );
    }

    /** Reported by the last factorial cohort: how far from crossed the worst pair of attributes came out. */
    public array $balance = [];

    /**
     * The factorial cohort, laid out on a near-orthogonal array.
     *
     * Every level of every attribute appears about equally often, and every pair
     * of attributes is as close to fully crossed as the cohort size allows. The
     * second property is the one worth paying for: it keeps two attributes from
     * arriving correlated by accident, which is the case where no amount of
     * analysis can say which of them the model was reacting to.
     *
     * @return list<Persona>
     */
    public function cohort(int $count): array
    {
        $attributes = AttributeCatalogue::keys();
        $levelCounts = array_map(fn (string $a) => count(AttributeCatalogue::levels($a)), $attributes);

        $array = OrthogonalArray::build($levelCounts, $count, $this->seed + 1_000);
        $this->balance = ['imbalance' => $array['imbalance'], 'worst_pair' => $array['worst_pair']];

        $assignments = [];
        foreach ($attributes as $offset => $attribute) {
            $levels = AttributeCatalogue::levels($attribute);
            $assignments[$attribute] = array_map(
                fn (array $row) => $levels[$row[$offset]],
                $array['rows'],
            );
        }

        $numeric = [];
        foreach (AttributeCatalogue::NUMERIC as $key => $spec) {
            $numeric[$key] = $this->spread($spec['min'], $spec['max'], $count, $this->seed + 7_000 + crc32($key));
        }

        $names = new Randomiser($this->seed + 31);
        $people = [];

        for ($i = 0; $i < $count; $i++) {
            $attributes = [];
            foreach ($assignments as $attribute => $levels) {
                $attributes[$attribute] = $levels[$i];
            }

            [$first, $last] = $names->name($attributes['name_culture'], $attributes['gender']);

            $people[] = new Persona(
                idx: $i,
                firstName: $first,
                lastName: $last,
                attributes: $attributes,
                age: $numeric['age'][$i],
                heightCm: $numeric['height_cm'][$i],
                weightKg: $numeric['weight_kg'][$i],
            );
        }

        return $people;
    }

    /**
     * @param  list<string>  $levels
     * @return list<string>
     */
    private function balanced(array $levels, int $count, int $seed): array
    {
        $pool = [];
        while (count($pool) < $count) {
            $pool = array_merge($pool, $levels);
        }

        return (new Randomizer(new Mt19937($seed)))->shuffleArray(array_slice($pool, 0, $count));
    }

    /** @return list<int> */
    private function spread(int $min, int $max, int $count, int $seed): array
    {
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = $count === 1
                ? intdiv($min + $max, 2)
                : (int) round($min + ($max - $min) * $i / ($count - 1));
        }

        return (new Randomizer(new Mt19937($seed)))->shuffleArray($values);
    }
}
