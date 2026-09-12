<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Content;

final class ContentPackageDiscovery
{
    public function __construct(
        private readonly string $packagesRoot,
        private readonly ContentPackageManifestLoader $manifestLoader = new ContentPackageManifestLoader(),
        private readonly ContentPackageValidatorInterface $validator = new ContentPackageValidator(),
    ) {
    }

    /** @return list<ContentPackage> */
    public function discover(): array
    {
        $root = realpath($this->packagesRoot);
        if ($root === false || !is_dir($root)) {
            throw new ContentPackageException(sprintf('Content package directory does not exist: %s', $this->packagesRoot));
        }

        $directories = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if ($directories === false) {
            throw new ContentPackageException('Unable to scan the content package directory.');
        }
        sort($directories, SORT_STRING);

        $packages = [];
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($directories as $directory) {
            $resolvedDirectory = realpath($directory);
            if ($resolvedDirectory === false || !str_starts_with($resolvedDirectory, $prefix)) {
                throw new ContentPackageException('Content package directory resolves outside the controlled content root.');
            }
            $manifest = $this->manifestLoader->load($directory . DIRECTORY_SEPARATOR . 'manifest.json');
            $package = new ContentPackage($manifest, $directory);
            $this->validator->validate($package);
            if (isset($packages[$manifest->id()])) {
                throw new ContentPackageException(sprintf('Duplicate content package ID "%s".', $manifest->id()));
            }
            $packages[$manifest->id()] = $package;
        }

        ksort($packages, SORT_STRING);

        return array_values($packages);
    }
}
