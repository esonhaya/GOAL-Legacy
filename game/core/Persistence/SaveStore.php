<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

interface SaveStore
{
    public function create(SaveMetadata $metadata): void;

    public function openDatabase(string $saveId): DatabaseInterface;

    public function exists(string $saveId): bool;

    public function open(string $saveId): SaveMetadata;

    /** @return list<SaveMetadata> */
    public function list(): array;
}
