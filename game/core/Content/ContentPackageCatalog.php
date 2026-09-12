<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Content;

final class ContentPackageCatalog
{
    /** @var array<string, ContentPackage> */
    private array $packages = [];

    /** @var list<ContentPackage> */
    private array $resolved;

    /** @var list<string> */
    private array $selectedIds;

    /** @param list<ContentPackage> $packages @param list<string> $selectedIds */
    public function __construct(array $packages, array $selectedIds = [])
    {
        $this->resolved = (new ContentPackageResolver())->resolve($packages);
        foreach ($this->resolved as $package) {
            $this->packages[$package->manifest()->id()] = $package;
        }
        ksort($this->packages, SORT_STRING);
        $this->selectedIds = $this->validateSelection($selectedIds);
    }

    /** @return list<ContentPackage> */
    public function packages(): array { return array_values($this->packages); }

    /** @return list<ContentPackage> */
    public function resolvedPackages(): array { return $this->resolved; }

    /** @return list<string> */
    public function selectedIds(): array { return $this->selectedIds; }

    public function isSelected(string $id): bool
    {
        return in_array($id, $this->selectedIds, true);
    }

    /** @param list<string> $selectedIds */
    public function select(array $selectedIds): self
    {
        return new self($this->packages(), $selectedIds);
    }

    /** @return list<string> */
    private function validateSelection(array $selectedIds): array
    {
        $selected = [];
        foreach ($selectedIds as $id) {
            if (!is_string($id) || !isset($this->packages[$id])) {
                throw new ContentPackageException(sprintf('Selected content package does not exist: %s', (string) $id));
            }
            $selected[$id] = true;
        }

        foreach (array_keys($selected) as $id) {
            foreach ($this->packages[$id]->manifest()->dependencies() as $dependency) {
                if (!isset($selected[$dependency])) {
                    throw new ContentPackageException(sprintf('Selected content package "%s" requires unselected package "%s".', $id, $dependency));
                }
            }
        }

        $ordered = [];
        foreach ($this->resolved as $package) {
            if (isset($selected[$package->manifest()->id()])) {
                $ordered[] = $package->manifest()->id();
            }
        }

        return $ordered;
    }
}
