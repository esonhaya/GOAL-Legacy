<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Content;

final class ContentPackageResolver
{
    /**
     * @param list<ContentPackage> $packages
     * @return list<ContentPackage>
     */
    public function resolve(array $packages): array
    {
        $byId = [];
        foreach ($packages as $package) {
            $id = $package->manifest()->id();
            if (isset($byId[$id])) {
                throw new ContentPackageException(sprintf('Duplicate content package ID "%s".', $id));
            }
            $byId[$id] = $package;
        }
        ksort($byId, SORT_STRING);

        $resolved = [];
        $visiting = [];
        $visited = [];
        foreach (array_keys($byId) as $id) {
            $this->visit($id, $byId, $resolved, $visiting, $visited);
        }

        return $resolved;
    }

    /** @param array<string, ContentPackage> $byId @param list<ContentPackage> $resolved @param array<string, bool> $visiting @param array<string, bool> $visited */
    private function visit(string $id, array $byId, array &$resolved, array &$visiting, array &$visited): void
    {
        if (isset($visited[$id])) {
            return;
        }
        if (isset($visiting[$id])) {
            throw new ContentPackageException(sprintf('Circular content package dependency detected at "%s".', $id));
        }
        if (!isset($byId[$id])) {
            throw new ContentPackageException(sprintf('Missing content package dependency "%s".', $id));
        }

        $visiting[$id] = true;
        $dependencies = $byId[$id]->manifest()->dependencies();
        sort($dependencies, SORT_STRING);
        foreach ($dependencies as $dependency) {
            if (!isset($byId[$dependency])) {
                throw new ContentPackageException(sprintf('Content package "%s" depends on missing package "%s".', $id, $dependency));
            }
            $this->visit($dependency, $byId, $resolved, $visiting, $visited);
        }
        unset($visiting[$id]);
        $visited[$id] = true;
        $resolved[] = $byId[$id];
    }
}
