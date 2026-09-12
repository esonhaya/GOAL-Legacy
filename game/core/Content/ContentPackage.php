<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Content;

final readonly class ContentPackage
{
    private string $root;

    public function __construct(
        private ContentPackageManifest $manifest,
        string $root,
    ) {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new ContentPackageException(sprintf('Content package root does not exist: %s', $root));
        }
        $this->root = $resolved;
    }

    public function manifest(): ContentPackageManifest { return $this->manifest; }

    public function root(): string { return $this->root; }

    public function resolvePath(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_starts_with($relativePath, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $relativePath) === 1 || str_contains($relativePath, '\\')) {
            throw new ContentPackageException('Content file paths must be relative and stay inside the package root.');
        }

        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '') {
                throw new ContentPackageException('Content file paths cannot traverse outside the package root.');
            }
        }

        $candidate = $this->root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $resolved = realpath($candidate);
        if ($resolved === false) {
            throw new ContentPackageException(sprintf('Content file does not exist: %s', $relativePath));
        }
        $prefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolved, $prefix) || !is_file($resolved)) {
            throw new ContentPackageException('Content file path resolves outside the package root.');
        }

        return $resolved;
    }
}
