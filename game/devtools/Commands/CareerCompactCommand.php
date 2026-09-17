<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\SeasonCompactionService;

/** Maintenance entry point for upgrading an older completed-season save. */
final class CareerCompactCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'career:compact'; }

    public function description(): string { return 'Compact completed-season NPC evidence while preserving career history.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        if ($saveId === '') {
            $output->error('Usage: career:compact <save-id>');
            return 1;
        }
        $database = $this->services->saveStore()->openDatabase($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $compactor = new SeasonCompactionService();
        $count = 0;
        foreach ($worldService->seasonRepository($database)->all() as $season) {
            if ($season->status() !== SeasonStatus::Completed) {
                continue;
            }
            $next = $worldService->seasonRollover()?->nextSeason($season);
            if ($next === null) {
                continue;
            }
            $result = $compactor->compact($database, $season->id(), $next->startDate()->toIsoString());
            if (($result['compacted'] ?? false) === true) {
                ++$count;
                $output->write(sprintf('COMPACTED %s: stats=%d selections=%d evaluations=%d development=%d availability=%d.', $season->label(), $result['stats'], $result['selections'], $result['evaluations'], $result['development'], $result['availability']));
            }
        }
        $output->write(sprintf('CAREER COMPACTION — %d completed Season(s) processed for %s.', $count, $world->id()->value()));

        return 0;
    }
}
