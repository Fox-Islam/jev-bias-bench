<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bench\Personas\PersonaGenerator;
use App\Bench\Probing\Condition;
use App\Bench\Probing\Prober;
use App\Bench\Probing\StateBuilder;
use App\Bench\Scenarios\ScenarioRegistry;
use Phox\TypeSafe\Testing\FakeAnswers;
use Phox\TypeSafe\Testing\FakeTypeSafe;
use Tests\TestCase;

final class ProbingTest extends TestCase
{
    public function test_a_condition_never_leaks_more_of_the_person_than_it_should(): void
    {
        $persona = (new PersonaGenerator(5))->counterfactualCohort(1, ['religion'])[0];
        $scenario = ScenarioRegistry::get('hiring_screen');
        $builder = new StateBuilder;

        $render = fn (Condition $condition) => json_encode(
            $builder->build($scenario, $persona, $condition, 'mid'),
            JSON_UNESCAPED_SLASHES,
        );

        $blind = $render(Condition::Blind);
        $nameOnly = $render(Condition::NameOnly);
        $attributesOnly = $render(Condition::AttributesOnly);

        self::assertStringNotContainsString($persona->lastName, $blind);
        self::assertStringNotContainsString($persona->pronouns(), $blind);

        self::assertStringContainsString($persona->lastName, $nameOnly);
        self::assertStringNotContainsString($persona->pronouns(), $nameOnly);

        self::assertStringNotContainsString($persona->lastName, $attributesOnly);
        self::assertStringContainsString($persona->pronouns(), $attributesOnly);
    }

    public function test_the_request_shape_is_the_same_whatever_the_condition(): void
    {
        $persona = (new PersonaGenerator(5))->counterfactualCohort(1, ['religion'])[0];
        $scenario = ScenarioRegistry::get('lending');
        $builder = new StateBuilder;

        $shapes = [];
        foreach (Condition::cases() as $condition) {
            $shapes[] = array_keys($builder->build($scenario, $persona, $condition, 'mid'));
        }

        self::assertCount(1, array_unique(array_map('json_encode', $shapes)));
    }

    public function test_answers_normalise_onto_the_same_scale_whatever_kind_they_are(): void
    {
        $fake = new FakeTypeSafe;
        $fake->reply(
            FakeAnswers::make()
                ->noul('advance', 0.8)
                ->noul('trust_unsupervised', 0.2)
                ->score('competence', 2.0)
                ->choice('salary_band', 'band_c', ['band_a' => 0.0, 'band_b' => 0.0, 'band_c' => 1.0, 'band_d' => 0.0])
                ->noul('culture_concern', 0.1),
        );

        $scenario = ScenarioRegistry::get('hiring_screen');
        $persona = (new PersonaGenerator(5))->counterfactualCohort(1, ['religion'])[0];
        $prober = new Prober($fake->client());

        $response = $prober->send($scenario, $persona, Condition::Full, 'mid', 'jev-latest', 10.0);
        $outcomes = [];
        foreach ($prober->read($scenario, $response) as $outcome) {
            $outcomes[$outcome->questionKey] = $outcome->value;
        }

        self::assertEqualsWithDelta(0.8, $outcomes['advance'], 1e-9);
        self::assertEqualsWithDelta(0.5, $outcomes['competence'], 1e-9, 'level 2 of a five-level rubric is the midpoint');
        self::assertEqualsWithDelta(2 / 3, $outcomes['salary_band'], 1e-9, 'the third of four ordered options');

        foreach ($outcomes as $key => $value) {
            self::assertGreaterThanOrEqual(0.0, $value, "{$key} fell below zero");
            self::assertLessThanOrEqual(1.0, $value, "{$key} rose above one");
        }
    }

    public function test_a_split_choice_lands_between_its_options_rather_than_on_the_winner(): void
    {
        $fake = new FakeTypeSafe;
        $fake->reply(
            FakeAnswers::make()->choice('salary_band', 'band_c', [
                'band_a' => 0.0, 'band_b' => 0.5, 'band_c' => 0.5, 'band_d' => 0.0,
            ]),
        );

        $scenario = ScenarioRegistry::get('hiring_screen');
        $persona = (new PersonaGenerator(5))->counterfactualCohort(1, ['religion'])[0];
        $prober = new Prober($fake->client());

        $response = $prober->send($scenario, $persona, Condition::Full, 'mid', 'jev-latest', 10.0);
        $value = null;
        foreach ($prober->read($scenario, $response) as $outcome) {
            if ($outcome->questionKey === 'salary_band') {
                $value = $outcome->value;
            }
        }

        self::assertEqualsWithDelta(1.5 / 3, $value, 1e-9);
    }

    public function test_every_scenario_question_is_ordered_and_has_a_direction(): void
    {
        foreach (ScenarioRegistry::all() as $scenario) {
            self::assertNotEmpty($scenario->variants, "{$scenario->key} has no case variants");
            self::assertNotEmpty($scenario->questions, "{$scenario->key} asks nothing");

            foreach ($scenario->questions as $question) {
                self::assertContains($question->favourable, [1, -1], "{$scenario->key}.{$question->key} has no direction");

                if ($question->kind !== 'noul') {
                    self::assertGreaterThanOrEqual(
                        2,
                        count($question->criteria ?? []),
                        "{$scenario->key}.{$question->key} needs at least two ordered options",
                    );
                }
            }

            // Every variant must describe the case in the same terms, or a
            // difference between variants could be a difference in what was
            // said rather than in the merits.
            $shapes = array_map(fn (array $facts) => array_keys($facts), $scenario->variants);
            self::assertCount(1, array_unique(array_map('json_encode', $shapes)), "{$scenario->key} variants disagree on their fields");
        }
    }
}
