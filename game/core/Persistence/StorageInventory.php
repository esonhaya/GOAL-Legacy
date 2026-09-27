<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use Throwable;

/**
 * Inventory for the configured save root plus explicitly owned temp roots.
 * It never treats an unreadable SQLite file as deletable.
 */
final class StorageInventory
{
    public function __construct(
        private readonly string $saveDirectory,
        private readonly string $temporaryRoot,
        private readonly SaveStore $saveStore,
    ) {
        if ($saveDirectory === '' || str_contains($saveDirectory, "\0") || !is_dir($saveDirectory)) {
            throw new PersistenceException('Storage inventory requires an existing save directory.');
        }
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $started = hrtime(true);
        $artifacts = [];
        $canonical = [];
        $counts = [
            StorageArtifactType::NormalCareerSave->value => 0,
            StorageArtifactType::SandboxSave->value => 0,
            StorageArtifactType::TempTestSave->value => 0,
            StorageArtifactType::TempSimulationSave->value => 0,
            StorageArtifactType::BrowserTestSave->value => 0,
            StorageArtifactType::RecoverySnapshot->value => 0,
            StorageArtifactType::SandboxSnapshot->value => 0,
            StorageArtifactType::DiagnosticArtifact->value => 0,
            StorageArtifactType::UnknownExternal->value => 0,
        ];
        $bytes = array_fill_keys(array_keys($counts), 0);
        $unknownFiles = 0;
        $saveRootOwnedTempCount = 0;

        foreach (scandir($this->saveDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->saveDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $type = $this->ownedSaveDirectoryType($entry);
                if ($type !== null) {
                    $size = $this->directorySize($path);
                    $this->addArtifact($artifacts, $counts, $bytes, $type, $path, $size, $entry);
                    ++$saveRootOwnedTempCount;
                }
                continue;
            }
            if (is_link($path)) {
                ++$unknownFiles;
                $this->addArtifact($artifacts, $counts, $bytes, StorageArtifactType::UnknownExternal, $path, 0, $entry);
                continue;
            }
            if (!is_file($path)) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_-]{0,63})\.sqlite$/', $entry, $matches) === 1) {
                $saveId = (string) $matches[1];
                try {
                    $metadata = $this->saveStore->open($saveId);
                    $type = str_starts_with($saveId, OwnedArtifactCleanup::BROWSER_SAVE_PREFIX)
                        ? StorageArtifactType::BrowserTestSave
                        : ($metadata->isSandbox() ? StorageArtifactType::SandboxSave : StorageArtifactType::NormalCareerSave);
                    $canonical[$saveId] = $type;
                    $this->addArtifact($artifacts, $counts, $bytes, $type, $path, $this->fileSize($path), $saveId, [
                        'owner_id' => $metadata->ownerId(),
                        'sandbox_source_id' => $metadata->sandboxSourceId(),
                    ]);
                } catch (Throwable) {
                    $unknownFiles++;
                    $this->addArtifact($artifacts, $counts, $bytes, StorageArtifactType::UnknownExternal, $path, $this->fileSize($path), $entry);
                }
                continue;
            }

            $companion = $this->companionSaveId($entry);
            if ($companion !== null && isset($canonical[$companion])) {
                $this->addArtifact($artifacts, $counts, $bytes, $canonical[$companion], $path, $this->fileSize($path), $entry, ['associated_save_id' => $companion], false);
                continue;
            }

            $unknownFiles++;
            $this->addArtifact($artifacts, $counts, $bytes, StorageArtifactType::UnknownExternal, $path, $this->fileSize($path), $entry);
        }

        // Sidecars may sort before their canonical file, so classify a second
        // time after the canonical metadata pass.
        foreach (scandir($this->saveDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_file($this->saveDirectory . DIRECTORY_SEPARATOR . $entry)) {
                continue;
            }
            $saveId = $this->companionSaveId($entry);
            if ($saveId === null || !isset($canonical[$saveId]) || $this->hasArtifactPath($artifacts, $this->saveDirectory . DIRECTORY_SEPARATOR . $entry)) {
                continue;
            }
            $path = $this->saveDirectory . DIRECTORY_SEPARATOR . $entry;
            $this->addArtifact($artifacts, $counts, $bytes, $canonical[$saveId], $path, $this->fileSize($path), $entry, ['associated_save_id' => $saveId], false);
        }

        $ownedTemporaryCount = 0;
        if (is_dir($this->temporaryRoot) && !is_link($this->temporaryRoot)) {
            $root = realpath($this->temporaryRoot);
            if ($root !== false) {
                foreach (scandir($root) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $path = $root . DIRECTORY_SEPARATOR . $entry;
                    $type = OwnedArtifactCleanup::temporaryDirectoryConventions()[$this->matchingPrefix($entry)] ?? null;
                    if ($type === null || !is_dir($path) || is_link($path)) {
                        continue;
                    }
                    $ownedTemporaryCount++;
                    $this->addArtifact($artifacts, $counts, $bytes, $type, $path, $this->directorySize($path), $entry);
                }
            }
        }

        return [
            'save_directory' => $this->saveDirectory,
            'temporary_root' => $this->temporaryRoot,
            'artifacts' => $artifacts,
            'counts' => [
                'normal_save_count' => $counts[StorageArtifactType::NormalCareerSave->value],
                'sandbox_save_count' => $counts[StorageArtifactType::SandboxSave->value],
                'owned_temp_count' => $ownedTemporaryCount + $saveRootOwnedTempCount + $counts[StorageArtifactType::BrowserTestSave->value],
                'browser_test_save_count' => $counts[StorageArtifactType::BrowserTestSave->value],
                'owned_snapshot_count' => $counts[StorageArtifactType::RecoverySnapshot->value] + $counts[StorageArtifactType::SandboxSnapshot->value],
                'unknown_file_count' => $unknownFiles,
            ],
            'bytes' => [
                'total_owned_bytes' => $bytes[StorageArtifactType::NormalCareerSave->value] + $bytes[StorageArtifactType::SandboxSave->value] + $bytes[StorageArtifactType::TempTestSave->value] + $bytes[StorageArtifactType::TempSimulationSave->value] + $bytes[StorageArtifactType::BrowserTestSave->value] + $bytes[StorageArtifactType::RecoverySnapshot->value] + $bytes[StorageArtifactType::SandboxSnapshot->value] + $bytes[StorageArtifactType::DiagnosticArtifact->value],
                'normal_save_bytes' => $bytes[StorageArtifactType::NormalCareerSave->value],
                'sandbox_bytes' => $bytes[StorageArtifactType::SandboxSave->value],
                'temp_bytes' => $bytes[StorageArtifactType::TempTestSave->value] + $bytes[StorageArtifactType::TempSimulationSave->value] + $bytes[StorageArtifactType::BrowserTestSave->value] + $bytes[StorageArtifactType::DiagnosticArtifact->value],
                'snapshot_bytes' => $bytes[StorageArtifactType::RecoverySnapshot->value] + $bytes[StorageArtifactType::SandboxSnapshot->value],
                'unknown_bytes' => $bytes[StorageArtifactType::UnknownExternal->value],
            ],
            'inventory_duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
        ];
    }

    /** @return array<string, mixed> */
    public function attribution(string $saveId): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $saveId) !== 1) {
            throw new PersistenceException('Invalid save ID; path traversal and unsupported characters are rejected.');
        }
        $metadata = $this->saveStore->open($saveId);
        unset($metadata);
        $path = $this->saveDirectory . DIRECTORY_SEPARATOR . $saveId . '.sqlite';

        return (new SqliteStorageAttribution())->inspect($path);
    }

    /** @param list<array<string,mixed>> $artifacts @param array<string,int> $counts @param array<string,int> $bytes @param array<string,mixed> $extra */
    private function addArtifact(array &$artifacts, array &$counts, array &$bytes, StorageArtifactType $type, string $path, int $size, string $id, array $extra = [], bool $count = true): void
    {
        if ($count) {
            $counts[$type->value]++;
        }
        $bytes[$type->value] += $size;
        $artifacts[] = array_merge([
            'id' => $id,
            'type' => $type->value,
            'path' => $path,
            'bytes' => $size,
        ], $extra);
    }

    private function hasArtifactPath(array $artifacts, string $path): bool
    {
        foreach ($artifacts as $artifact) {
            if (($artifact['path'] ?? null) === $path) {
                return true;
            }
        }

        return false;
    }

    private function companionSaveId(string $entry): ?string
    {
        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_-]{0,63})\.sqlite-(?:wal|shm|journal)$/', $entry, $matches) === 1) {
            return (string) $matches[1];
        }
        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_-]{0,63})\.sqlite\.p4-compaction-[A-Za-z0-9_-]+$/', $entry, $matches) === 1) {
            return (string) $matches[1];
        }

        return null;
    }

    private function ownedSaveDirectoryType(string $entry): ?StorageArtifactType
    {
        if (str_starts_with($entry, '.career-preview-') && strlen($entry) > strlen('.career-preview-')) {
            return StorageArtifactType::TempTestSave;
        }

        return null;
    }

    private function matchingPrefix(string $entry): string
    {
        foreach (array_keys(OwnedArtifactCleanup::temporaryDirectoryConventions()) as $prefix) {
            if (str_starts_with($entry, $prefix) && strlen($entry) > strlen($prefix)) {
                return $prefix;
            }
        }

        return '';
    }

    private function fileSize(string $path): int
    {
        clearstatcache(true, $path);
        $size = filesize($path);

        return $size === false ? 0 : (int) $size;
    }

    private function directorySize(string $directory): int
    {
        $bytes = 0;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $bytes += $this->directorySize($path);
                continue;
            }
            $bytes += $this->fileSize($path);
        }

        return $bytes;
    }
}
