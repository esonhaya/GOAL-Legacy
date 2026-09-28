<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\SeasonArchiveService;
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
        $archiver = new SeasonArchiveService();
        $count = 0;
        $physicalCompactionRecommended = false;
        foreach ($worldService->seasonRepository($database)->all() as $season) {
            if ($season->status() !== SeasonStatus::Completed) {
                continue;
            }
            $next = $worldService->seasonRollover()?->nextSeason($season);
            if ($next === null) {
                continue;
            }
            $result = $world->currentSeasonId()?->value() === $next->id()->value()
                ? $archiver->archive($database, $season->id(), $next->id(), $next->startDate()->toIsoString())
                : $compactor->compact($database, $season->id(), $next->startDate()->toIsoString());
            if (($result['compacted'] ?? $result['archived'] ?? false) === true) {
                ++$count;
                $physicalCompactionRecommended = $physicalCompactionRecommended || (bool) ($result['physical_compaction_recommended'] ?? false);
                $historical = is_array($result['historical_compaction'] ?? null) ? $result['historical_compaction'] : [];
                $output->write(sprintf('COMPACTED %s: stats=%d selections=%d evaluations=%d development=%d availability=%d registrations=%d memberships=%d role_history=%d contracts=%d.', $season->label(), (int) ($result['stats'] ?? $historical['stats'] ?? 0), (int) ($result['selections'] ?? $historical['selections'] ?? 0), (int) ($result['evaluations'] ?? $historical['evaluations'] ?? 0), (int) ($result['development'] ?? $historical['development'] ?? 0), (int) ($result['availability'] ?? $historical['availability'] ?? 0), (int) ($result['registrations'] ?? 0), (int) ($result['memberships'] ?? 0), (int) ($result['role_history'] ?? 0), (int) ($result['contracts'] ?? 0)));
            }
        }
        unset($database);
        if ($physicalCompactionRecommended) {
            $physical = $this->services->saveStore()->compact($saveId);
            $output->write(sprintf('PHYSICAL COMPACTION save=%s before=%d after=%d reclaimed=%d duration_ms=%.3f integrity=%s fk=%d.', $saveId, $physical['before']['file_size_bytes'], $physical['after']['file_size_bytes'], $physical['bytes_reclaimed'], $physical['duration_ms'], $physical['after']['integrity_check'], $physical['after']['foreign_key_violations']));
        }
        $output->write(sprintf('CAREER COMPACTION — %d completed Season(s) processed for %s.', $count, $world->id()->value()));

        return 0;
    }
}
