<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

interface SerializerInterface
{
    /** @param array<string, mixed> $data */
    public function encode(array $data): string;

    /** @return array<string, mixed> */
    public function decode(string $payload): array;
}
