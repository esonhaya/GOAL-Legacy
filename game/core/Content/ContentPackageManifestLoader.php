<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Content;

use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SerializerInterface;
use Throwable;

final class ContentPackageManifestLoader
{
    public function __construct(private readonly SerializerInterface $serializer = new JsonSerializer())
    {
    }

    public function load(string $path): ContentPackageManifest
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ContentPackageException(sprintf('Content manifest is not readable: %s', $path));
        }

        $payload = file_get_contents($path);
        if ($payload === false) {
            throw new ContentPackageException(sprintf('Unable to read content manifest: %s', $path));
        }

        try {
            return ContentPackageManifest::fromArray($this->serializer->decode($payload));
        } catch (Throwable $exception) {
            if ($exception instanceof ContentPackageException) {
                throw $exception;
            }
            throw new ContentPackageException(sprintf('Invalid content manifest: %s (%s)', $path, $exception->getMessage()), 0, $exception);
        }
    }
}
