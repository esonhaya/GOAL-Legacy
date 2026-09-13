<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

final readonly class PerformanceEvaluation
{
    public function __construct(private int $score, private string $band)
    {
    }

    public function score(): int { return $this->score; }
    public function band(): string { return $this->band; }
}
