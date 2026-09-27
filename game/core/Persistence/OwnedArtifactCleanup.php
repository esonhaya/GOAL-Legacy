<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use RuntimeException;

/**
 * Bounded cleanup for artifacts with a repository-owned naming convention.
 *
 * This class intentionally does not accept arbitrary glob patterns.  A path
 * must be inside the supplied root and must match one of the known GOAL
 * conventions before it can be removed.
 */
final class OwnedArtifactCleanup
{
    public const BROWSER_SAVE_PREFIX = 'p3019-browser-';

    /** @var array<string, StorageArtifactType> */
    private const TEMP_DIRECTORY_PREFIXES = [
        'goal-legacy-scenario-' => StorageArtifactType::TempSimulationSave,
        'goal-legacy-p3020-' => StorageArtifactType::DiagnosticArtifact,
        'goal-legacy-multi-season-' => StorageArtifactType::TempSimulationSave,
        'goal-legacy-pacing-observatory-' => StorageArtifactType::TempSimulationSave,
        'goal-legacy-nation-' => StorageArtifactType::TempTestSave,
        'goal-legacy-persistence-' => StorageArtifactType::TempTestSave,
        'goal-legacy-world-' => StorageArtifactType::TempTestSave,
        'goal-legacy-player-' => StorageArtifactType::TempTestSave,
        'goal-legacy-match-' => StorageArtifactType::TempTestSave,
        'goal-legacy-career-' => StorageArtifactType::TempTestSave,
        'goal-legacy-transfer-' => StorageArtifactType::TempTestSave,
        'goal-legacy-recruitment-' => StorageArtifactType::TempTestSave,
        'goal-legacy-population-' => StorageArtifactType::TempTestSave,
        'goal-legacy-career-transfer-' => StorageArtifactType::TempTestSave,
        'goal-legacy-season-audit-' => StorageArtifactType::TempSimulationSave,
        'goal-legacy-p4-001-' => StorageArtifactType::TempTestSave,
        'goal-legacy-competition-test-' => StorageArtifactType::TempTestSave,
        'goal-legacy-club-test-' => StorageArtifactType::TempTestSave,
        'goal-legacy-content-' => StorageArtifactType::TempTestSave,
        'goal-legacy-domain-' => StorageArtifactType::TempTestSave,
        'goal-legacy-logs-' => StorageArtifactType::DiagnosticArtifact,
        'goal-legacy-log-' => StorageArtifactType::DiagnosticArtifact,
        'goal-legacy-recovery-snapshot-' => StorageArtifactType::RecoverySnapshot,
        'goal-legacy-sandbox-snapshot-' => StorageArtifactType::SandboxSnapshot,
    ];

    /** @return array<string, StorageArtifactType> */
    public static function temporaryDirectoryConventions(): array
    {
        return self::TEMP_DIRECTORY_PREFIXES;
    }

    /**
     * Remove the exact SQLite sidecars associated with an already validated
     * canonical save.  Unknown neighbours are deliberately ignored.
     */
    public function deleteSaveCompanions(string $saveDirectory, string $saveId): void
    {
        foreach (self::knownSaveCompanionPaths($saveDirectory, $saveId) as $path) {
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }
            if (is_dir($path) && !is_link($path)) {
                throw new PersistenceException(sprintf('Owned save sidecar "%s" is a directory.', basename($path)));
            }
            if (!unlink($path)) {
                throw new PersistenceException(sprintf('Unable to remove owned save sidecar "%s".', basename($path)));
            }
        }
    }

    /** @return list<string> */
    public static function knownSaveCompanionPaths(string $saveDirectory, string $saveId): array
    {
        $root = self::validatedRoot($saveDirectory);
        self::validateSaveId($saveId);
        $base = $root . DIRECTORY_SEPARATOR . $saveId . '.sqlite';
        $paths = [
            $base . '-wal',
            $base . '-shm',
            $base . '-journal',
        ];
        foreach (glob($base . '.p4-compaction-*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                $name = basename($path);
                if (preg_match('/^' . preg_quote($saveId, '/') . '\\.sqlite\\.p4-compaction-[A-Za-z0-9_-]+$/', $name) === 1) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Remove stale, repository-owned temporary directories under one exact
     * temporary root.  The default age makes this suitable for an explicit
     * maintenance boundary; normal page rendering never calls it.
     *
     * @return array{removed:list<string>,skipped:list<string>,bytes:int,failures:list<string>}
     */
    public function cleanupTemporaryDirectories(string $temporaryRoot, int $minimumAgeSeconds = 86400): array
    {
        $root = self::validatedRoot($temporaryRoot);
        $cutoff = time() - max(0, $minimumAgeSeconds);
        $removed = [];
        $skipped = [];
        $failures = [];
        $bytes = 0;

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path) || is_link($path)) {
                continue;
            }
            if (!self::temporaryType($entry)) {
                continue;
            }
            $modified = filemtime($path);
            if ($modified !== false && $modified > $cutoff) {
                $skipped[] = $path;
                continue;
            }

            try {
                $bytes += self::directoryBytes($path);
                self::removeOwnedDirectory($path, null, $root);
                $removed[] = $path;
            } catch (\Throwable $exception) {
                $failures[] = $path . ': ' . $exception->getMessage();
            }
        }

        return ['removed' => $removed, 'skipped' => $skipped, 'bytes' => $bytes, 'failures' => $failures];
    }

    /**
     * Clean only the preview directories and compaction scratch names that
     * GOAL itself owns inside the canonical save root.
     *
     * @return array{removed:list<string>,skipped:list<string>,bytes:int,failures:list<string>}
     */
    public function cleanupSaveRoot(string $saveDirectory, int $minimumAgeSeconds = 86400): array
    {
        $root = self::validatedRoot($saveDirectory);
        $cutoff = time() - max(0, $minimumAgeSeconds);
        $removed = [];
        $skipped = [];
        $failures = [];
        $bytes = 0;
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            $isPreview = is_dir($path) && !is_link($path) && str_starts_with($entry, '.career-preview-') && strlen($entry) > strlen('.career-preview-');
            $isCompaction = is_file($path) && !is_link($path) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.sqlite\.p4-compaction-[A-Za-z0-9_-]+$/', $entry) === 1;
            if (!$isPreview && !$isCompaction) {
                continue;
            }
            $modified = filemtime($path);
            if ($modified !== false && $modified > $cutoff) {
                $skipped[] = $path;
                continue;
            }
            try {
                $bytes += $isPreview ? self::directoryBytes($path) : $this->fileBytes($path);
                if ($isPreview) {
                    self::removeOwnedDirectory($path, '.career-preview-', $root);
                } elseif (!unlink($path)) {
                    throw new RuntimeException(sprintf('Unable to remove owned compaction artifact "%s".', $path));
                }
                $removed[] = $path;
            } catch (\Throwable $exception) {
                $failures[] = $path . ': ' . $exception->getMessage();
            }
        }

        return ['removed' => $removed, 'skipped' => $skipped, 'bytes' => $bytes, 'failures' => $failures];
    }

    /**
     * Remove a directory only when its basename is one of the owned
     * conventions.  This is shared by simulation fixtures' finally blocks.
     */
    public static function removeOwnedDirectory(string $directory, ?string $requiredPrefix = null, ?string $allowedRoot = null): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $name = basename(rtrim($directory, DIRECTORY_SEPARATOR));
        $type = self::temporaryType($name);
        if ($requiredPrefix !== null && !str_starts_with($name, $requiredPrefix)) {
            throw new RuntimeException('Refusing to clean a directory outside the requested owned prefix.');
        }
        if ($type === null && $requiredPrefix === null && !str_starts_with($name, '.career-preview-')) {
            throw new RuntimeException('Refusing to clean an unknown temporary directory.');
        }
        $root = self::validatedRoot($allowedRoot ?? dirname($directory));
        $realDirectory = realpath($directory);
        if ($realDirectory === false || !self::inside($realDirectory, $root)) {
            throw new RuntimeException('Refusing to clean a directory outside the owned root.');
        }

        foreach (scandir($realDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $realDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($child) || is_file($child)) {
                if (!unlink($child)) {
                    throw new RuntimeException(sprintf('Unable to remove owned temporary file "%s".', $child));
                }
                continue;
            }
            if (is_dir($child)) {
                self::removeTree($child);
            }
        }
        if (!rmdir($realDirectory)) {
            throw new RuntimeException(sprintf('Unable to remove owned temporary directory "%s".', $realDirectory));
        }
    }

    private static function removeTree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || is_file($path)) {
                if (!unlink($path)) {
                    throw new RuntimeException(sprintf('Unable to remove owned temporary file "%s".', $path));
                }
            } elseif (is_dir($path)) {
                self::removeTree($path);
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException(sprintf('Unable to remove owned temporary directory "%s".', $directory));
        }
    }

    private static function directoryBytes(string $directory): int
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
                $bytes += self::directoryBytes($path);
                continue;
            }
            $size = filesize($path);
            if ($size !== false) {
                $bytes += $size;
            }
        }

        return $bytes;
    }

    private function fileBytes(string $path): int
    {
        $size = filesize($path);

        return $size === false ? 0 : (int) $size;
    }

    private static function temporaryType(string $name): ?StorageArtifactType
    {
        foreach (self::TEMP_DIRECTORY_PREFIXES as $prefix => $type) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                return $type;
            }
        }

        return null;
    }

    private static function validatedRoot(string $root): string
    {
        if ($root === '' || str_contains($root, "\0") || !is_dir($root)) {
            throw new PersistenceException('Storage cleanup root must be an existing directory.');
        }
        $real = realpath($root);
        if ($real === false) {
            throw new PersistenceException('Storage cleanup root could not be resolved.');
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private static function inside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private static function validateSaveId(string $saveId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $saveId) !== 1) {
            throw new PersistenceException('Invalid save ID; path traversal and unsupported characters are rejected.');
        }
    }
}
