<?php

declare(strict_types=1);

namespace App\Bench\Probing;

/** One answer, normalised onto [0, 1] so answers of different kinds compare. */
final readonly class Outcome
{
    public function __construct(
        public string $questionKey,
        public string $kind,
        public float $value,
        public ?float $confidence,
        public array $raw,
    ) {}
}
