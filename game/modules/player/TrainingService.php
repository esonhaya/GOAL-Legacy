<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\DevelopmentApplicationResult;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;

final class TrainingService
{
    public function __construct(private readonly PlayerDevelopmentService $development, private readonly ?PlayerAvailabilityService $availability = null)
    {
    }

    public function complete(DatabaseInterface $database, TrainingRequest $request): DevelopmentApplicationResult
    {
        if ($this->availability === null) {
            return $this->development->applyTraining($database, $request);
        }
        [$result, $changes] = $this->completeWithChanges($database, $request);
        $this->development->dispatchTrainingResult($result);
        $this->availability?->dispatchChanges($changes);

        return $result;
    }

    /** @return array{0:DevelopmentApplicationResult,1:list<array{event:string,payload:array<string,mixed>}>} */
    public function completeWithChanges(DatabaseInterface $database, TrainingRequest $request): array
    {
        if ($this->availability === null) {
            return [$this->development->applyTraining($database, $request), []];
        }
        $changes = [];
        $result = $database->transaction(function () use ($database, $request, &$changes): DevelopmentApplicationResult {
            $assessment = $this->availability->assess($database, $request->playerId(), $request->endDate());
            $result = $assessment->isUnavailable()
                ? $this->development->skipTrainingInTransaction($database, $request)
                : $this->development->applyTrainingInTransaction($database, $request);
            $weeks = max(1, intdiv($request->startDate()->daysUntil($request->endDate()), 7));
            $changes = $this->availability->applyTrainingInTransaction($database, $request->playerId(), $request->blockId(), $request->endDate(), $weeks, $request->intensity());

            return $result;
        });
        return [$result, $changes];
    }
}
