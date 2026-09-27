<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

interface SaveStore
{
    public function create(SaveMetadata $metadata): void;

    public function openDatabase(string $saveId, ?SqlProfiler $profiler = null): DatabaseInterface;

    public function exists(string $saveId): bool;

    public function open(string $saveId): SaveMetadata;

    public function update(SaveMetadata $metadata): void;

    public function cloneSave(string $sourceId, SaveMetadata $destination): void;

    public function delete(string $saveId): void;

    /** Return the configured storage root without exposing it to players. */
    public function storageDirectory(): string;

    /** Return the canonical save file size plus known SQLite sidecars. */
    public function size(string $saveId): int;

    /**
     * Reclaim SQLite free pages at an explicit maintenance boundary.
     *
     * @param (callable(DatabaseInterface):array<string,mixed>)|null $semanticCheckpoint
     * @return array<string, mixed>
     */
    public function compact(string $saveId, ?callable $semanticCheckpoint = null): array;

    /** @return list<SaveMetadata> */
    public function list(): array;
}
