<?php

declare(strict_types=1);

namespace App\Bench\Probing;

use App\Bench\Personas\AttributeCatalogue;
use App\Bench\Scenarios\ScenarioRegistry;

/**
 * The size and shape of a run.
 *
 * Two designs are available. `counterfactual` builds a few anchor people and one
 * copy per attribute level that differs in that attribute alone; every
 * comparison is then a person against their own anchor, which removes the
 * variation between people from the measurement and is the only way to see an
 * effect at a few hundred calls. `factorial` draws everything independently, is
 * far weaker per call, and exists because it is the only design that can be
 * asked about combinations of attributes.
 *
 * `replicates` repeats a subset of cells unchanged. Those repeats do double duty:
 * they measure how much the model moves when nothing does, and their spread is
 * the null distribution every paired difference is tested against.
 */
final readonly class RunProfile
{
    /**
     * @param  list<string>  $scenarioKeys
     * @param  list<Condition>  $conditions
     * @param  list<string>|null  $swept
     */
    public function __construct(
        public string $name,
        public string $design,
        public int $bases,
        public int $personas,
        public array $scenarioKeys,
        public array $conditions,
        public int $replicateSubjects,
        public int $replicates,
        public int $anchorReplicates = 1,
        public ?array $swept = null,
        public bool $anchorAtReference = false,
    ) {}

    public static function named(string $name): self
    {
        return match ($name) {
            'smoke' => self::counterfactual(
                name: 'smoke',
                bases: 1,
                swept: ['name_culture', 'gender'],
                scenarios: ['hiring_screen'],
                conditions: [Condition::Full],
                replicateSubjects: 2,
                replicates: 2,
                anchorReplicates: 3,
            ),

            /* Two anchors, six attributes that the discrimination literature
               keeps finding effects on, and the two scenarios furthest apart in
               kind. Enough to tell whether there is anything here. */
            'pilot' => self::counterfactual(
                name: 'pilot',
                bases: 2,
                swept: ['name_culture', 'gender', 'religion', 'disability', 'age_band', 'immigration_status'],
                scenarios: ['hiring_screen', 'clinical_triage'],
                conditions: [Condition::Full, Condition::NameOnly],
                replicateSubjects: 10,
                replicates: 4,
                anchorReplicates: 8,
            ),

            'standard' => self::counterfactual(
                name: 'standard',
                bases: 3,
                swept: null,
                scenarios: ['hiring_screen', 'lending', 'clinical_triage', 'content_moderation'],
                conditions: [Condition::Full, Condition::NameOnly],
                replicateSubjects: 20,
                replicates: 3,
                anchorReplicates: 8,
            ),

            'deep' => self::counterfactual(
                name: 'deep',
                bases: 6,
                swept: null,
                scenarios: ['hiring_screen', 'lending', 'tenancy', 'clinical_triage', 'justice_bail', 'content_moderation', 'admissions', 'credibility'],
                conditions: [Condition::Full, Condition::NameOnly],
                replicateSubjects: 30,
                replicates: 3,
                anchorReplicates: 10,
            ),

            /* The independent-draw design, for questions about combinations of
               attributes that a one-at-a-time sweep cannot reach. */
            'factorial' => new self(
                name: 'factorial',
                design: 'factorial',
                bases: 0,
                personas: 160,
                scenarioKeys: ['hiring_screen', 'lending', 'clinical_triage', 'content_moderation'],
                conditions: [Condition::Blind, Condition::NameOnly, Condition::Full],
                replicateSubjects: 20,
                replicates: 3,
            ),

            default => throw new \InvalidArgumentException("Unknown profile [$name]. Try ".implode(', ', self::names()).'.'),
        };
    }

    /** @param list<string>|null $swept */
    private static function counterfactual(
        string $name,
        int $bases,
        ?array $swept,
        array $scenarios,
        array $conditions,
        int $replicateSubjects,
        int $replicates,
        int $anchorReplicates,
        bool $anchorAtReference = false,
    ): self {
        $attributes = $swept ?? AttributeCatalogue::sweepable();

        $perBase = 1;
        foreach ($attributes as $attribute) {
            $perBase += count(AttributeCatalogue::levels($attribute)) - 1;
        }

        return new self(
            name: $name,
            design: 'counterfactual',
            bases: $bases,
            personas: $bases * $perBase,
            scenarioKeys: $scenarios,
            conditions: $conditions,
            replicateSubjects: $replicateSubjects,
            replicates: $replicates,
            anchorReplicates: $anchorReplicates,
            swept: $attributes,
            anchorAtReference: $anchorAtReference,
        );
    }

    /**
     * A narrower version of this profile, for checking one scenario cheaply
     * before committing a full run to it.
     *
     * @param  list<string>|null  $scenarios
     * @param  list<string>|null  $swept
     */
    public function with(?array $scenarios = null, ?int $bases = null, ?array $swept = null, bool $anchorAtReference = false): self
    {
        if ($scenarios === null && $bases === null && $swept === null && ! $anchorAtReference) {
            return $this;
        }

        foreach ($scenarios ?? [] as $key) {
            ScenarioRegistry::get($key);   // throws on a typo rather than planning a run without it
        }

        foreach ($swept ?? [] as $attribute) {
            if (! in_array($attribute, AttributeCatalogue::sweepable(), true)) {
                throw new \InvalidArgumentException("Cannot sweep [$attribute]; it is not in the catalogue.");
            }
        }

        return self::counterfactual(
            name: $this->name,
            bases: $bases ?? $this->bases,
            swept: $swept ?? $this->swept,
            scenarios: $scenarios ?? $this->scenarioKeys,
            conditions: $this->conditions,
            replicateSubjects: $this->replicateSubjects,
            replicates: $this->replicates,
            anchorReplicates: $this->anchorReplicates,
            anchorAtReference: $anchorAtReference,
        );
    }

    /** @return list<string> */
    public static function names(): array
    {
        return ['smoke', 'pilot', 'standard', 'deep', 'factorial'];
    }

    public function probeCount(): int
    {
        $cells = count($this->scenarioKeys) * count($this->conditions);
        $main = $this->personas * $cells;
        $extra = min($this->replicateSubjects, $this->personas)
            * count($this->scenarioKeys)
            * max(0, $this->replicates - 1);

        // Every swap in a base is measured against that base's anchor, so the
        // anchor's own noise lands on all of them at once. Asking the anchors
        // repeatedly and averaging is the cheapest way to stop one unlucky
        // anchor answer tilting every comparison built on it.
        $anchors = $this->isCounterfactual()
            ? $this->bases * $cells * max(0, $this->anchorReplicates - 1)
            : 0;

        return $main + $extra + $anchors;
    }

    /** @return list<string> */
    public function conditionValues(): array
    {
        return array_map(fn (Condition $c) => $c->value, $this->conditions);
    }

    public function isCounterfactual(): bool
    {
        return $this->design === 'counterfactual';
    }
}
