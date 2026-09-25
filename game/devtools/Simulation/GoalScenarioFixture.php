<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Simulation;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use RuntimeException;

/** An explicitly owned, isolated save used by Simulation Lab scenarios. */
final class GoalScenarioFixture
{
    /** @param list<string> $matchIds */
    public function __construct(
        private readonly string $directory,
        private readonly SaveStore $store,
        private readonly string $saveId,
        private DatabaseInterface $database,
        private readonly PlayerId $playerId,
        private readonly string $clubId,
        private readonly SeasonId $seasonId,
        private readonly array $matchIds,
    ) {
    }

    public function store(): SaveStore { return $this->store; }
    public function saveId(): string { return $this->saveId; }
    public function database(): DatabaseInterface { return $this->database; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function clubId(): string { return $this->clubId; }
    public function seasonId(): SeasonId { return $this->seasonId; }
    /** @return list<string> */
    public function matchIds(): array { return $this->matchIds; }

    public function reload(): void
    {
        unset($this->database);
        $this->database = $this->store->openDatabase($this->saveId);
    }

    public function close(): void
    {
        unset($this->database);
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException(sprintf('Unable to clean owned scenario artifact "%s".', $file));
            }
        }
        if (!rmdir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to clean owned scenario directory "%s".', $this->directory));
        }
    }
}
