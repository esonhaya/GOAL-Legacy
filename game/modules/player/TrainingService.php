<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\DevelopmentApplicationResult;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;

final class TrainingService
{
    public function __construct(private readonly PlayerDevelopmentService $development)
    {
    }

    public function complete(DatabaseInterface $database, TrainingRequest $request): DevelopmentApplicationResult
    {
        return $this->development->applyTraining($database, $request);
    }
}
