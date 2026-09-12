<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

final class SeasonLifecycleService
{
    public function evaluate(Season $season, SimulationDate $date): SeasonTransition
    {
        $started = false;
        $completed = false;
        $current = $season;

        if ($current->status() === SeasonStatus::Upcoming && !$date->isBefore($current->startDate())) {
            $current = $current->activate();
            $started = true;
        }
        if ($current->status() === SeasonStatus::Active && !$date->isBefore($current->endDate())) {
            $current = $current->complete();
            $completed = true;
        }

        return new SeasonTransition($current, $started, $completed);
    }
}
