<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Modules;

use InvalidArgumentException;

final class ModuleDescriptor
{
    /** @param list<string> $dependencies */
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $version,
        private readonly array $dependencies = [],
        private readonly bool $enabledByDefault = true,
        private readonly bool $critical = false,
        private readonly ?string $featureFlag = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]*$/', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid module ID "%s".', $id));
        }
        if (trim($name) === '' || trim($version) === '') {
            throw new InvalidArgumentException('Module name and version are required.');
        }
        if ($featureFlag !== null && trim($featureFlag) === '') {
            throw new InvalidArgumentException('A module feature flag cannot be empty.');
        }
        if (count(array_unique($dependencies)) !== count($dependencies)) {
            throw new InvalidArgumentException(sprintf('Module "%s" declares duplicate dependencies.', $id));
        }
    }

    public function id(): string { return $this->id; }

    public function name(): string { return $this->name; }

    public function version(): string { return $this->version; }

    /** @return list<string> */
    public function dependencies(): array { return $this->dependencies; }

    public function enabledByDefault(): bool { return $this->enabledByDefault; }

    public function critical(): bool { return $this->critical; }

    public function featureFlag(): ?string { return $this->featureFlag; }
}
