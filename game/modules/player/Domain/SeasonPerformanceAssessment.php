<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

final readonly class SeasonPerformanceAssessment
{
    /** @param array<string, int|float> $statistics */
    public function __construct(
        private string $classification,
        private int $score,
        private array $statistics,
        private string $reason,
    ) {
    }

    public function classification(): string
    {
        return $this->classification;
    }

    public function score(): int
    {
        return $this->score;
    }

    /** @return array<string, int|float> */
    public function statistics(): array
    {
        return $this->statistics;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array{classification:string,score:int,reason:string,statistics:array<string,int|float>} */
    public function toArray(): array
    {
        return [
            'classification' => $this->classification,
            'score' => $this->score,
            'reason' => $this->reason,
            'statistics' => $this->statistics,
        ];
    }
}
