<?php

declare(strict_types=1);

namespace App\Bench\Scenarios;

/**
 * One question in a scenario, in the shape Jev models it.
 *
 * `criteria` is ordered, and every outcome is normalised onto [0, 1] using that
 * order, so a noul, a five-level score and a four-option choice can sit in the
 * same table. `favourable` says which end of that scale is good news for the
 * person being judged, so an effect can be reported as advantage or penalty
 * rather than as a bare number moving.
 */
final readonly class Question
{
    /**
     * @param  'noul'|'choice'|'score'  $kind
     * @param  array<string, string>|null  $criteria  label => description, in order
     * @param  int  $favourable  +1 when a higher value favours the subject, -1 when it harms them
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $instructions,
        public ?array $criteria = null,
        public int $favourable = 1,
        public string $measures = '',
    ) {}

    /** The payload this question contributes to a System One request. */
    public function payload(): array
    {
        return match ($this->kind) {
            /* A noul's criteria are keyed `true` and `false` on the wire, which is
               how the SDK models them; a noul with no criteria sends none. */
            'noul' => array_filter(['type' => 'noul', 'instructions' => $this->instructions, 'criteria' => $this->criteria]),
            'choice' => ['type' => 'choice', 'instructions' => $this->instructions, 'criteria' => $this->criteria],
            'score' => ['type' => 'score', 'instructions' => $this->instructions, 'criteria' => array_values($this->criteria ?? [])],
        };
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_keys($this->criteria ?? []);
    }

    public function levelCount(): int
    {
        return max(2, count($this->criteria ?? []));
    }

    public static function noul(string $key, string $instructions, int $favourable = 1, string $measures = '', ?array $criteria = null): self
    {
        return new self($key, 'noul', $instructions, $criteria, $favourable, $measures);
    }

    public static function choice(string $key, string $instructions, array $criteria, int $favourable = 1, string $measures = ''): self
    {
        return new self($key, 'choice', $instructions, $criteria, $favourable, $measures);
    }

    public static function score(string $key, string $instructions, array $levels, int $favourable = 1, string $measures = ''): self
    {
        return new self($key, 'score', $instructions, $levels, $favourable, $measures);
    }
}
