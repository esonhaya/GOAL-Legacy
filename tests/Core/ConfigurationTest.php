<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Core;

use Goal\Legacy\Core\Configuration\Configuration;
use Goal\Legacy\Core\Configuration\ConfigurationLoader;
use Goal\Legacy\Core\Configuration\MissingConfigurationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testEnvironmentOverridesAreLoadedAndTyped(): void
    {
        $configuration = (new ConfigurationLoader())->load(
            dirname(__DIR__, 2) . '/game/config/core.php',
            ['APP_ENV' => 'test', 'APP_MODULE_SAMPLE_ENABLED' => 'false', 'APP_FEATURE_FAST_MODE' => 'true'],
        );

        self::assertSame('test', $configuration->string('app.environment'));
        self::assertFalse($configuration->boolean('modules.sample.enabled'));
        self::assertTrue($configuration->boolean('features.fast.mode'));
    }

    public function testRequiredConfigurationFailureIsClear(): void
    {
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('Required configuration value "missing.value" is missing.');
        (new Configuration([]))->require('missing.value');
    }

    public function testTypedGetterRejectsWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Configuration(['value' => 'not-an-integer']))->integer('value');
    }
}
