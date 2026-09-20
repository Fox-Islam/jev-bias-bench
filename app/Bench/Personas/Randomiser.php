<?php

declare(strict_types=1);

namespace App\Bench\Personas;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded name draws.
 *
 * Kept behind its own object because a counterfactual pair is only clean if the
 * name is redrawn the same way every time the cohort is regenerated from a seed.
 */
final class Randomiser
{
    private Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /** @return array{0: string, 1: string} */
    public function name(string $culture, string $gender): array
    {
        $firsts = NamePools::FIRST_NAMES[$culture][$gender] ?? NamePools::FIRST_NAMES['anglo'][$gender];
        $lasts = NamePools::SURNAMES[$culture] ?? NamePools::SURNAMES['anglo'];

        return [
            $firsts[$this->randomizer->getInt(0, count($firsts) - 1)],
            $lasts[$this->randomizer->getInt(0, count($lasts) - 1)],
        ];
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /** @param list<mixed> $values */
    public function shuffle(array $values): array
    {
        return $this->randomizer->shuffleArray($values);
    }
}
