<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bench\Personas\AttributeCatalogue;
use App\Bench\Personas\PersonaGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The counterfactual design is only worth anything if a pair really does differ
 * in one thing. These are the tests that would catch it quietly not doing so —
 * which would turn every number in the report into an unattributable difference.
 */
final class CounterfactualCohortTest extends TestCase
{
    public function test_every_swap_differs_from_its_anchor_in_exactly_one_attribute(): void
    {
        $cohort = (new PersonaGenerator(1234))->counterfactualCohort(2);
        $anchors = [];

        foreach ($cohort as $persona) {
            if ($persona->isAnchor) {
                $anchors[$persona->baseIndex] = $persona;
            }
        }

        self::assertCount(2, $anchors);

        foreach ($cohort as $persona) {
            if ($persona->isAnchor) {
                continue;
            }

            $anchor = $anchors[$persona->baseIndex];
            $differing = [];

            foreach (AttributeCatalogue::keys() as $attribute) {
                if ($persona->get($attribute) !== $anchor->get($attribute)) {
                    $differing[] = $attribute;
                }
            }

            if (AttributeCatalogue::isNumeric($persona->swappedAttribute)) {
                self::assertSame([], $differing, "Numeric swap {$persona->swappedAttribute} moved a categorical attribute");

                continue;
            }

            self::assertSame(
                [$persona->swappedAttribute],
                $differing,
                "Swapping {$persona->swappedAttribute} changed ".implode(', ', $differing),
            );
        }
    }

    public function test_the_name_only_changes_when_the_name_is_what_was_swapped(): void
    {
        $cohort = (new PersonaGenerator(99))->counterfactualCohort(1);
        $anchor = $cohort[0];

        foreach ($cohort as $persona) {
            if ($persona->isAnchor) {
                continue;
            }

            $carriedByName = in_array($persona->swappedAttribute, ['name_culture', 'gender'], true);
            $sameName = $persona->fullName() === $anchor->fullName();

            if ($carriedByName) {
                continue;   // may or may not redraw to the same name by chance
            }

            self::assertTrue(
                $sameName,
                "Swapping {$persona->swappedAttribute} changed the name from {$anchor->fullName()} to {$persona->fullName()}",
            );
        }
    }

    public function test_a_build_swap_keeps_the_height_and_moves_the_weight(): void
    {
        $cohort = (new PersonaGenerator(7))->counterfactualCohort(1, ['bmi_band']);
        $anchor = $cohort[0];

        foreach (array_slice($cohort, 1) as $persona) {
            self::assertSame($anchor->heightCm, $persona->heightCm);
            self::assertNotSame($anchor->weightKg, $persona->weightKg);
            self::assertSame($persona->swappedLevel, AttributeCatalogue::bmiBand($persona->bmi()));
        }
    }

    public function test_the_same_seed_gives_the_same_cohort(): void
    {
        $first = (new PersonaGenerator(42))->counterfactualCohort(2, ['religion', 'gender']);
        $second = (new PersonaGenerator(42))->counterfactualCohort(2, ['religion', 'gender']);

        self::assertSame(
            array_map(fn ($p) => $p->toArray(), $first),
            array_map(fn ($p) => $p->toArray(), $second),
        );
    }
}
