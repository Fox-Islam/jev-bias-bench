<?php

declare(strict_types=1);

namespace App\Bench\Probing;

use App\Bench\Personas\Persona;
use App\Bench\Scenarios\Question;
use App\Bench\Scenarios\Scenario;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Questions\Score;
use Phox\TypeSafe\Responses\SystemOneResponse;

/**
 * Puts one scenario to Jev about one person and reads the answers back as numbers.
 *
 * Every question in a scenario goes in a single call. The jevsort measurements
 * put a call's cost almost entirely in its round trip rather than in the number
 * of questions, so one call per person per scenario is both the cheapest shape
 * and the cleanest: no other person's details are ever in the same request, so
 * nothing can anchor on a neighbour.
 */
final class Prober
{
    public function __construct(
        private readonly Client $client,
        private readonly StateBuilder $states = new StateBuilder,
    ) {}

    public function request(Scenario $scenario, Persona $persona, Condition $condition, string $variant, string $model): array
    {
        return [
            'state' => $this->states->build($scenario, $persona, $condition, $variant),
            'model' => $model,
            'questions' => $scenario->questionPayloads(),
        ];
    }

    public function send(Scenario $scenario, Persona $persona, Condition $condition, string $variant, string $model, float $timeout): SystemOneResponse
    {
        $request = $this->client->systemOne()
            ->state($this->states->build($scenario, $persona, $condition, $variant))
            ->model($model)
            ->timeout($timeout);

        foreach ($scenario->questions as $question) {
            $request->ask($question->key, $this->toSdkQuestion($question));
        }

        return $request->send();
    }

    private function toSdkQuestion(Question $question): Noul|Choice|Score
    {
        return match ($question->kind) {
            'noul' => Noul::ask($question->instructions)->criteria($question->criteria),
            'choice' => Choice::ask($question->instructions)->options($question->criteria),
            'score' => Score::ask($question->instructions)->levels(array_values($question->criteria)),
        };
    }

    /**
     * Normalises every answer onto [0, 1].
     *
     * Where the API reports a distribution the expectation is used rather than
     * the top label. A choice between four ordered options answered 51/49 and one
     * answered 99/1 are different decisions, and collapsing both to the winning
     * label throws away most of the signal a bias test is looking for.
     *
     * @return list<Outcome>
     */
    public function read(Scenario $scenario, SystemOneResponse $response): array
    {
        $outcomes = [];

        foreach ($scenario->questions as $question) {
            if (! $response->has($question->key)) {
                continue;
            }

            $outcomes[] = match ($question->kind) {
                'noul' => $this->readNoul($question, $response),
                'choice' => $this->readChoice($question, $response),
                'score' => $this->readScore($question, $response),
            };
        }

        return $outcomes;
    }

    private function readNoul(Question $question, SystemOneResponse $response): Outcome
    {
        $answer = $response->noul($question->key);

        return new Outcome($question->key, 'noul', $answer->noul(), null, $answer->toArray());
    }

    private function readScore(Question $question, SystemOneResponse $response): Outcome
    {
        $answer = $response->score($question->key);
        $span = max(1, $question->levelCount() - 1);

        return new Outcome(
            $question->key,
            'score',
            $this->clamp($answer->score() / $span),
            $answer->confidence(),
            $answer->toArray(),
        );
    }

    private function readChoice(Question $question, SystemOneResponse $response): Outcome
    {
        $answer = $response->choice($question->key);
        $labels = $question->labels();
        $span = max(1, count($labels) - 1);
        $probabilities = $answer->probabilities();

        $total = array_sum($probabilities);
        if ($total > 0.0) {
            $expected = 0.0;
            foreach ($labels as $index => $label) {
                $expected += ($probabilities[$label] ?? 0.0) / $total * $index;
            }
            $value = $expected / $span;
        } else {
            $position = array_search($answer->choice(), $labels, true);
            $value = $position === false ? 0.5 : $position / $span;
        }

        return new Outcome($question->key, 'choice', $this->clamp($value), $answer->confidence(), $answer->toArray());
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
