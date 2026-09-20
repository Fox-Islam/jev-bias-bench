<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bench\Personas\AttributeCatalogue;
use App\Bench\Personas\Persona;
use App\Bench\Personas\PersonaGenerator;
use App\Bench\Scenarios\ScenarioRegistry;
use PHPUnit\Framework\TestCase;

/**
 * An age swap has to be an age swap.
 *
 * The first live pilot reported a five-point hiring penalty for being 22, six
 * times larger on the CV where 22 and six years of experience did not add up.
 * Most of that was the model catching an impossible history. These tests hold
 * the two halves of the fix: the sweep never asks anyone to have started work
 * before eighteen, and the cap that would rescue it if one ever did.
 */
final class CaseArithmeticTest extends TestCase
{
    public function test_no_age_in_the_sweep_is_too_young_for_any_case(): void
    {
        $youngest = min(AttributeCatalogue::NUMERIC_SWEEP['age_band']['values']);
        $longest = 0;

        foreach (ScenarioRegistry::all() as $scenario) {
            foreach ($scenario->variants as $facts) {
                $longest = max($longest, (int) ($facts['years_experience'] ?? 0));
            }
        }

        self::assertGreaterThanOrEqual(
            $longest + AttributeCatalogue::earliestWorkingAge(),
            $youngest,
            "The youngest swept age ({$youngest}) cannot support a {$longest}-year career",
        );
    }

    public function test_the_counterfactual_sweep_never_has_to_change_a_case(): void
    {
        $cohort = (new PersonaGenerator(31))->counterfactualCohort(2);

        foreach (ScenarioRegistry::all() as $scenario) {
            foreach ($scenario->variantKeys() as $variant) {
                foreach ($cohort as $persona) {
                    self::assertFalse(
                        $scenario->adapted($variant, $persona),
                        "{$scenario->key}/{$variant} had to be changed for a {$persona->age}-year-old, "
                        .'so that pair is no longer a clean comparison',
                    );
                }
            }
        }
    }

    public function test_the_cap_still_works_for_a_cohort_that_does_reach_too_young(): void
    {
        $scenario = ScenarioRegistry::get('hiring_screen');
        $cohort = (new PersonaGenerator(8))->counterfactualCohort(1, ['gender']);
        $persona = $cohort[0];

        $young = new Persona(
            idx: 999,
            firstName: $persona->firstName,
            lastName: $persona->lastName,
            attributes: $persona->attributes,
            age: 21,
            heightCm: $persona->heightCm,
            weightKg: $persona->weightKg,
        );

        $facts = $scenario->facts('strong', $young);

        self::assertSame(3, $facts['years_experience'], 'A 21-year-old cannot have started before eighteen');
        self::assertSame(9, $scenario->variants['strong']['years_experience'], 'The variant itself must be untouched');
    }
}
