<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use PDO;
use Throwable;

final class SqliteSaveStore implements SaveStore
{
    private const TABLE = 'core_save_metadata';

    public function __construct(
        private readonly string $saveDirectory,
        private readonly SerializerInterface $serializer,
    ) {
        if ($saveDirectory === '' || str_contains($saveDirectory, "\0")) {
            throw new PersistenceException('Save directory must be non-empty and free of null bytes.');
        }
        if (!is_dir($saveDirectory) && !mkdir($saveDirectory, 0775, true) && !is_dir($saveDirectory)) {
            throw new PersistenceException(sprintf('Unable to create save directory "%s".', $saveDirectory));
        }
    }

    public function create(SaveMetadata $metadata): void
    {
        if ($metadata->formatVersion() !== SaveMetadata::FORMAT_VERSION) {
            throw new UnsupportedSaveVersionException('Cannot create a save with an unsupported format version.');
        }

        $path = $this->pathFor($metadata->id());
        if (file_exists($path) || is_link($path)) {
            throw new PersistenceException(sprintf('Save "%s" already exists.', $metadata->id()));
        }

        try {
            $database = new SqliteDatabase($path);
            $database->transaction(function (PDO $connection) use ($metadata): void {
                $this->initializeSchema($connection);
                $statement = $connection->prepare('INSERT INTO ' . self::TABLE . ' (id, payload) VALUES (:id, :payload)');
                $statement->execute([
                    'id' => $metadata->id(),
                    'payload' => $this->serializer->encode([
                        'format_version' => SaveMetadata::FORMAT_VERSION,
                        'metadata' => $metadata->toArray(),
                    ]),
                ]);
            });
        } catch (Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }
    }

    public function exists(string $saveId): bool
    {
        $path = $this->pathFor($saveId);

        return is_file($path) && !is_link($path);
    }

    public function openDatabase(string $saveId, ?SqlProfiler $profiler = null): DatabaseInterface
    {
        $path = $this->pathFor($saveId);
        if (!is_file($path) || is_link($path)) {
            throw new PersistenceException(sprintf('Save "%s" does not exist.', $saveId));
        }

        return new SqliteDatabase($path, $profiler);
    }

    public function open(string $saveId): SaveMetadata
    {
        $database = $this->openDatabase($saveId);
        $statement = $database->connection()->prepare('SELECT payload FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $saveId]);
        $row = $statement->fetch();
        if (!is_array($row) || !is_string($row['payload'] ?? null)) {
            throw new PersistenceException(sprintf('Save "%s" has no valid Core metadata.', $saveId));
        }

        $envelope = $this->serializer->decode($row['payload']);
        if (($envelope['format_version'] ?? null) !== SaveMetadata::FORMAT_VERSION) {
            throw new UnsupportedSaveVersionException(sprintf('Save "%s" uses an unsupported format version.', $saveId));
        }
        if (!is_array($envelope['metadata'] ?? null)) {
            throw new PersistenceException(sprintf('Save "%s" has malformed metadata.', $saveId));
        }

        $metadata = SaveMetadata::fromArray($envelope['metadata']);
        if ($metadata->formatVersion() !== SaveMetadata::FORMAT_VERSION) {
            throw new UnsupportedSaveVersionException(sprintf('Save "%s" metadata uses an unsupported format version.', $saveId));
        }
        if ($metadata->id() !== $saveId) {
            throw new PersistenceException(sprintf('Save "%s" contains mismatched metadata identity.', $saveId));
        }

        return $metadata;
    }

    public function update(SaveMetadata $metadata): void
    {
        $database = $this->openDatabase($metadata->id());
        $this->initializeSchema($database->connection());
        $this->writeMetadata($database->connection(), $metadata->id(), $metadata);
    }

    public function cloneSave(string $sourceId, SaveMetadata $destination): void
    {
        if (!$this->exists($sourceId)) {
            throw new PersistenceException(sprintf('Source save "%s" does not exist.', $sourceId));
        }
        $destinationPath = $this->pathFor($destination->id());
        if (file_exists($destinationPath) || is_link($destinationPath)) {
            throw new PersistenceException(sprintf('Save "%s" already exists.', $destination->id()));
        }
        if (!copy($this->pathFor($sourceId), $destinationPath)) {
            throw new PersistenceException(sprintf('Unable to clone save "%s".', $sourceId));
        }
        try {
            $database = $this->openDatabase($destination->id());
            $this->writeMetadata($database->connection(), $sourceId, $destination);
        } catch (\Throwable $exception) {
            if (is_file($destinationPath)) { unlink($destinationPath); }
            throw $exception;
        }
    }

    public function delete(string $saveId): void
    {
        $path = $this->pathFor($saveId);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_file($path)) {
            throw new PersistenceException(sprintf('Save "%s" is not a canonical SQLite file.', $saveId));
        }

        // A deletion caller must prove that this is a GOAL save before the
        // canonical file is removed.  This also protects arbitrary nearby
        // files and corrupt SQLite files from a filename-only delete.
        $this->open($saveId);
        if (!unlink($path)) {
            throw new PersistenceException(sprintf('Unable to delete save "%s".', $saveId));
        }

        try {
            (new OwnedArtifactCleanup())->deleteSaveCompanions($this->saveDirectory, $saveId);
        } catch (Throwable $exception) {
            throw new SaveDeletionException(sprintf('Save "%s" was removed but an owned SQLite sidecar could not be cleaned.', $saveId), 0, $exception);
        }
    }

    public function storageDirectory(): string
    {
        return $this->saveDirectory;
    }

    public function size(string $saveId): int
    {
        $path = $this->pathFor($saveId);
        if (!is_file($path) || is_link($path)) {
            return 0;
        }

        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false) {
            throw new PersistenceException(sprintf('Unable to measure save "%s".', $saveId));
        }

        foreach (OwnedArtifactCleanup::knownSaveCompanionPaths($this->saveDirectory, $saveId) as $companion) {
            if (!is_file($companion) || is_link($companion)) {
                continue;
            }
            clearstatcache(true, $companion);
            $companionSize = filesize($companion);
            if ($companionSize !== false) {
                $size += $companionSize;
            }
        }

        return $size;
    }

    /** @return array<string, mixed> */
    /** @param (callable(DatabaseInterface):array<string,mixed>)|null $semanticCheckpoint */
    public function compact(string $saveId, ?callable $semanticCheckpoint = null): array
    {
        $path = $this->pathFor($saveId);
        if (!is_file($path) || is_link($path)) {
            throw new StorageMaintenanceException(sprintf('Save "%s" is not a canonical GOAL SQLite save.', $saveId));
        }

        $started = hrtime(true);
        $database = null;
        try {
            // Opening metadata rejects unknown/corrupt files before VACUUM
            // can touch them. VACUUM itself is deliberately explicit and
            // never called by a normal read path.
            $this->open($saveId);
            $database = new SqliteDatabase($path);
            $connection = $database->connection();
            if ($connection->inTransaction()) {
                throw new StorageMaintenanceException('SQLite compaction requires an idle connection.');
            }

            $before = self::sqliteMetrics($connection, $path);
            if ($before['integrity_check'] !== 'ok' || $before['foreign_key_violations'] !== 0) {
                throw new StorageMaintenanceException('SQLite compaction was refused because the save is not structurally healthy.');
            }
            $beforeCheckpoint = $semanticCheckpoint === null ? null : $this->readSemanticCheckpoint($saveId, $semanticCheckpoint);

            $connection->exec('VACUUM');

            unset($connection);
            unset($database);
            $database = new SqliteDatabase($path);
            $after = self::sqliteMetrics($database->connection(), $path);
            if ($after['integrity_check'] !== 'ok' || $after['foreign_key_violations'] !== 0) {
                throw new StorageMaintenanceException('SQLite compaction did not produce a healthy save.');
            }
            unset($database);
            $afterCheckpoint = $semanticCheckpoint === null ? null : $this->readSemanticCheckpoint($saveId, $semanticCheckpoint);
            if ($semanticCheckpoint !== null && $beforeCheckpoint !== $afterCheckpoint) {
                throw new StorageMaintenanceException('SQLite compaction changed the supplied semantic checkpoint.');
            }
            // Reopen through the canonical metadata path before reporting
            // success.  This proves the replacement file is still a GOAL save.
            $this->open($saveId);

            $beforeBytes = (int) $before['file_size_bytes'];
            $afterBytes = (int) $after['file_size_bytes'];

            return [
                'save_id' => $saveId,
                'before' => $before,
                'after' => $after,
                'bytes_reclaimed' => max(0, $beforeBytes - $afterBytes),
                'percent_reclaimed' => $beforeBytes === 0 ? 0.0 : round(max(0, $beforeBytes - $afterBytes) / $beforeBytes * 100, 3),
                'semantic_checkpoint' => $semanticCheckpoint === null ? 'NOT_REQUESTED' : 'PRESERVED',
                'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
            ];
        } catch (StorageMaintenanceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StorageMaintenanceException('SQLite compaction failed; the canonical save was not reported as optimized.', 0, $exception);
        } finally {
            unset($database);
        }
    }

    /** @param callable(DatabaseInterface):array<string,mixed> $semanticCheckpoint @return array<string,mixed> */
    private function readSemanticCheckpoint(string $saveId, callable $semanticCheckpoint): array
    {
        $database = $this->openDatabase($saveId);
        try {
            $checkpoint = $semanticCheckpoint($database);
            if (!is_array($checkpoint)) {
                throw new StorageMaintenanceException('The semantic checkpoint must return an array.');
            }

            return $checkpoint;
        } finally {
            unset($database);
        }
    }

    public function list(): array
    {
        $files = glob($this->saveDirectory . DIRECTORY_SEPARATOR . '*.sqlite');
        if ($files === false) {
            throw new PersistenceException('Unable to list save files.');
        }
        sort($files, SORT_STRING);

        $saves = [];
        foreach ($files as $file) {
            $saveId = pathinfo($file, PATHINFO_FILENAME);
            try {
                $saves[] = $this->open($saveId);
            } catch (Throwable) {
                // An unreadable save is omitted from the selectable list. It
                // is never deleted or repaired implicitly; direct open still
                // reports the canonical persistence failure to its caller.
            }
        }

        return $saves;
    }

    private function pathFor(string $saveId): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $saveId) !== 1) {
            throw new PersistenceException('Invalid save ID; path traversal and unsupported characters are rejected.');
        }

        return $this->saveDirectory . DIRECTORY_SEPARATOR . $saveId . '.sqlite';
    }

    /** @return array<string, int|string> */
    private static function sqliteMetrics(PDO $connection, string $path): array
    {
        clearstatcache(true, $path);
        $fileSize = filesize($path);
        if ($fileSize === false) {
            throw new StorageMaintenanceException('Unable to measure the SQLite save during maintenance.');
        }
        $pageSize = (int) $connection->query('PRAGMA page_size')->fetchColumn();
        $pageCount = (int) $connection->query('PRAGMA page_count')->fetchColumn();
        $freelist = (int) $connection->query('PRAGMA freelist_count')->fetchColumn();
        $integrity = (string) $connection->query('PRAGMA integrity_check')->fetchColumn();
        $foreignKeys = 0;
        foreach ($connection->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_NUM) as $_violation) {
            ++$foreignKeys;
        }

        return [
            'file_size_bytes' => (int) $fileSize,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'freelist_count' => $freelist,
            'used_approx_bytes' => max(0, $pageCount - $freelist) * $pageSize,
            'free_approx_bytes' => $freelist * $pageSize,
            'journal_mode' => (string) $connection->query('PRAGMA journal_mode')->fetchColumn(),
            'auto_vacuum' => (string) $connection->query('PRAGMA auto_vacuum')->fetchColumn(),
            'integrity_check' => $integrity,
            'foreign_key_violations' => $foreignKeys,
        ];
    }

    private function initializeSchema(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id TEXT PRIMARY KEY, '
            . 'payload TEXT NOT NULL'
            . ')'
        );
    }

    private function writeMetadata(PDO $connection, string $lookupId, SaveMetadata $metadata): void
    {
        $statement = $connection->prepare('UPDATE ' . self::TABLE . ' SET id = :new_id, payload = :payload WHERE id = :lookup_id');
        $statement->execute([
            'lookup_id' => $lookupId,
            'new_id' => $metadata->id(),
            'payload' => $this->serializer->encode([
                'format_version' => SaveMetadata::FORMAT_VERSION,
                'metadata' => $metadata->toArray(),
            ]),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PersistenceException(sprintf('Save "%s" metadata could not be updated.', $metadata->id()));
        }
    }
}
