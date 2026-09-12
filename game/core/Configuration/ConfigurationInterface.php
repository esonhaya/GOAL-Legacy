<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Configuration;

interface ConfigurationInterface
{
    public function has(string $key): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function require(string $key): mixed;

    public function string(string $key, ?string $default = null): string;

    public function integer(string $key, ?int $default = null): int;

    public function boolean(string $key, ?bool $default = null): bool;

    /** @return array<string, mixed> */
    public function all(): array;
}
