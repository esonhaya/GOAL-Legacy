<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Configuration;

use InvalidArgumentException;

final class ConfigurationLoader
{
    /** @param array<string, string> $environmentMap */
    public function load(string $defaultsPath, array $environment = [], array $environmentMap = []): Configuration
    {
        if (!is_file($defaultsPath)) {
            throw new InvalidArgumentException(sprintf('Configuration defaults file "%s" does not exist.', $defaultsPath));
        }

        $defaults = require $defaultsPath;
        if (!is_array($defaults)) {
            throw new InvalidArgumentException('Configuration defaults must return an array.');
        }

        $overrides = [];
        $knownMap = array_merge([
            'APP_ENV' => 'app.environment',
            'APP_LOG_LEVEL' => 'logging.level',
            'APP_LOG_PATH' => 'logging.path',
            'APP_LOG_COMPONENT' => 'logging.component',
        ], $environmentMap);

        foreach ($knownMap as $environmentKey => $configurationKey) {
            if (array_key_exists($environmentKey, $environment)) {
                self::setPath($overrides, $configurationKey, self::cast($environment[$environmentKey]));
            }
        }

        foreach ($environment as $environmentKey => $value) {
            if (str_starts_with($environmentKey, 'APP_FEATURE_')) {
                $feature = strtolower(str_replace('_', '.', substr($environmentKey, 12)));
                self::setPath($overrides, 'features.' . $feature, self::cast($value));
            }

            if (preg_match('/^APP_MODULE_(.+)_ENABLED$/', $environmentKey, $matches) === 1) {
                $moduleId = strtolower(str_replace('_', '-', $matches[1]));
                self::setPath($overrides, 'modules.' . $moduleId . '.enabled', self::cast($value));
            }
        }

        return new Configuration(self::merge($defaults, $overrides));
    }

    private static function cast(string|int|float|bool|null $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $lower = strtolower(trim($value));
        return match (true) {
            $lower === 'true' => true,
            $lower === 'false' => false,
            $lower === 'null' => null,
            is_numeric($value) && str_contains($value, '.') => (float) $value,
            is_numeric($value) => (int) $value,
            default => $value,
        };
    }

    /** @param array<string, mixed> $values */
    private static function setPath(array &$values, string $path, mixed $value): void
    {
        $cursor =& $values;
        $segments = explode('.', $path);
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor =& $cursor[$segment];
        }
        $cursor[$last] = $value;
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }
}
