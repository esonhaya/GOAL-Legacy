<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\OwnedArtifactCleanup;
use Goal\Legacy\Core\Persistence\StorageInventory;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Throwable;

/** Explicit developer/maintenance boundary for storage inspection and cleanup. */
final class StorageMaintenanceCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'storage:inspect'; }

    public function description(): string { return 'Inspect owned save storage, attribution, and bounded maintenance results.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        try {
            $store = $this->services->saveStore();
            $inventory = new StorageInventory($store->storageDirectory(), sys_get_temp_dir(), $store);
            $report = $inventory->report();
            $counts = $report['counts'];
            $bytes = $report['bytes'];
            $output->write(sprintf(
                'STORAGE_INVENTORY normal=%d sandbox=%d owned_temp=%d snapshots=%d unknown=%d owned_bytes=%d normal_bytes=%d sandbox_bytes=%d temp_bytes=%d snapshot_bytes=%d unknown_bytes=%d duration_ms=%.3f',
                $counts['normal_save_count'],
                $counts['sandbox_save_count'],
                $counts['owned_temp_count'],
                $counts['owned_snapshot_count'],
                $counts['unknown_file_count'],
                $bytes['total_owned_bytes'],
                $bytes['normal_save_bytes'],
                $bytes['sandbox_bytes'],
                $bytes['temp_bytes'],
                $bytes['snapshot_bytes'],
                $bytes['unknown_bytes'],
                $report['inventory_duration_ms'],
            ));

            $saveId = $this->argumentValue($arguments, '--save=', '');
            if ($saveId === '' && isset($arguments[0]) && !str_starts_with((string) $arguments[0], '--')) {
                $saveId = (string) $arguments[0];
            }
            if ($saveId !== '') {
                $attribution = $inventory->attribution($saveId);
                $output->write('STORAGE_ATTRIBUTION save=' . $saveId . ' ' . json_encode($attribution, JSON_THROW_ON_ERROR));
            }

            if (in_array('--cleanup', $arguments, true)) {
                $cleaner = new OwnedArtifactCleanup();
                $temp = $cleaner->cleanupTemporaryDirectories(sys_get_temp_dir());
                $saveRoot = $cleaner->cleanupSaveRoot($store->storageDirectory());
                $output->write(sprintf('STORAGE_CLEANUP temp_removed=%d save_root_removed=%d bytes=%d failures=%d', count($temp['removed']), count($saveRoot['removed']), $temp['bytes'] + $saveRoot['bytes'], count($temp['failures']) + count($saveRoot['failures'])));
                if ($temp['failures'] !== [] || $saveRoot['failures'] !== []) {
                    return 1;
                }
            }

            $compactId = $this->argumentValue($arguments, '--compact=', '');
            if ($compactId !== '') {
                $result = $store->compact($compactId);
                $output->write(sprintf('STORAGE_COMPACTION save=%s before=%d after=%d reclaimed=%d duration_ms=%.3f integrity=%s fk=%d', $compactId, $result['before']['file_size_bytes'], $result['after']['file_size_bytes'], $result['bytes_reclaimed'], $result['duration_ms'], $result['after']['integrity_check'], $result['after']['foreign_key_violations']));
            }

            return 0;
        } catch (Throwable $exception) {
            $output->error('Storage maintenance failed: ' . $exception->getMessage());

            return 1;
        }
    }

    private function argumentValue(array $arguments, string $prefix, string $default): string
    {
        foreach ($arguments as $argument) {
            if (str_starts_with((string) $argument, $prefix)) {
                return substr((string) $argument, strlen($prefix));
            }
        }

        return $default;
    }
}
