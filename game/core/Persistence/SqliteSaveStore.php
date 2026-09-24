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
        return is_file($this->pathFor($saveId));
    }

    public function openDatabase(string $saveId, ?SqlProfiler $profiler = null): DatabaseInterface
    {
        $path = $this->pathFor($saveId);
        if (!is_file($path)) {
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
        if (is_file($path) && !unlink($path)) {
            throw new PersistenceException(sprintf('Unable to delete save "%s".', $saveId));
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
            $saves[] = $this->open($saveId);
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
