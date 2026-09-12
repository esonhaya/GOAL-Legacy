<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use JsonException;

final class JsonSerializer implements SerializerInterface
{
    public function encode(array $data): string
    {
        $normalized = $this->sortAssociativeKeys($data);

        try {
            return json_encode(
                $normalized,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new PersistenceException('Unable to encode save data as JSON.', 0, $exception);
        }
    }

    public function decode(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PersistenceException('Save data contains malformed JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new PersistenceException('Save data JSON must contain an object.');
        }

        return $decoded;
    }

    /** @return array<string|int, mixed> */
    private function sortAssociativeKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortAssociativeKeys($value);
            }
        }

        if (!$this->isList($data)) {
            ksort($data);
        }

        return $data;
    }

    private function isList(array $data): bool
    {
        return array_keys($data) === range(0, count($data) - 1);
    }
}
