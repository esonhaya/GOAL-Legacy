<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Configuration;

use InvalidArgumentException;

final class Configuration implements ConfigurationInterface
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();

        return $this->get($key, $missing) !== $missing;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            throw new InvalidArgumentException('Configuration keys cannot be empty.');
        }

        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function require(string $key): mixed
    {
        if (!$this->has($key)) {
            throw new MissingConfigurationException($key);
        }

        return $this->get($key);
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->get($key, $default);
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Configuration "%s" must be a string.', $key));
        }

        return $value;
    }

    public function integer(string $key, ?int $default = null): int
    {
        $value = $this->get($key, $default);
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Configuration "%s" must be an integer.', $key));
        }

        return $value;
    }

    public function boolean(string $key, ?bool $default = null): bool
    {
        $value = $this->get($key, $default);
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('Configuration "%s" must be a boolean.', $key));
        }

        return $value;
    }

    public function all(): array
    {
        return $this->values;
    }
}
