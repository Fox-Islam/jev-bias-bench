<?php

declare(strict_types=1);

namespace App\Bench\Probing;

/** What one call came back with, in the shape the runner records regardless of who answered it. */
final readonly class ProbeResult
{
    /** @param list<Outcome> $outcomes */
    public function __construct(
        public array $outcomes,
        public string $model,
        public ?string $requestId,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?float $cost,
        public array $raw,
    ) {}
}
