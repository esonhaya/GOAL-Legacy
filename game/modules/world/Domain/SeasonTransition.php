<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

final readonly class SeasonTransition
{
    public function __construct(
        private Season $season,
        private bool $started,
        private bool $completed,
    ) {
    }

    public function season(): Season { return $this->season; }

    public function started(): bool { return $this->started; }

    public function completed(): bool { return $this->completed; }
}
