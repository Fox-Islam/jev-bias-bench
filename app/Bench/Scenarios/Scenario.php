<?php

declare(strict_types=1);

namespace App\Bench\Scenarios;

use App\Bench\Personas\Persona;

/**
 * A decision Jev is asked to make about a person.
 *
 * The case facts are fixed and drawn from `variants` independently of who the
 * person is, so every difference in the answers traces back to the persona. The
 * variants also double as a validity check: a question whose answers do not move
 * between the weak and strong variant is not measuring anything, and any persona
 * effect found on it would be noise dressed up as bias.
 */
final readonly class Scenario
{
    /**
     * @param  array<string, array<string, mixed>>  $variants  variant key => case facts
     * @param  list<Question>  $questions
     * @param  (callable(array<string, mixed>, Persona): array<string, mixed>)|null  $adapt
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $domain,
        public string $subjectRole,
        public string $stateKey,
        public array $variants,
        public array $questions,
        public mixed $adapt = null,
    ) {}

    /**
     * The case as this person would face it.
     *
     * Case facts are meant to be identical for everyone, and for almost every
     * attribute they are. The exception is arithmetic: a CV claiming nine years
     * of work cannot belong to a 22-year-old, and a model that notices will mark
     * the younger person down for the impossibility rather than for their age.
     * Where a scenario can say so it caps the facts here instead, and the
     * counterfactual sweep is tuned so the cap never has to fire — which a test
     * holds it to.
     *
     * @return array<string, mixed>
     */
    public function facts(string $variant, Persona $persona): array
    {
        $facts = $this->variants[$variant];

        return $this->adapt === null ? $facts : ($this->adapt)($facts, $persona);
    }

    /** True when this person's age forced the case to be changed for them. */
    public function adapted(string $variant, Persona $persona): bool
    {
        return $this->facts($variant, $persona) !== $this->variants[$variant];
    }

    /** @return list<string> */
    public function variantKeys(): array
    {
        return array_keys($this->variants);
    }

    public function question(string $key): ?Question
    {
        foreach ($this->questions as $question) {
            if ($question->key === $key) {
                return $question;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    public function questionPayloads(): array
    {
        $payloads = [];
        foreach ($this->questions as $question) {
            $payloads[$question->key] = $question->payload();
        }

        return $payloads;
    }
}
