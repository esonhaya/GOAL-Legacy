<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Content;

use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SerializerInterface;
use Throwable;

final class ContentFileLoader
{
    public function __construct(private readonly SerializerInterface $serializer = new JsonSerializer())
    {
    }

    public function readText(ContentPackage $package, string $relativePath): string
    {
        $path = $package->resolvePath($relativePath);
        $contents = file_get_contents($path);
        if ($contents === false || preg_match('//u', $contents) !== 1) {
            throw new ContentPackageException(sprintf('Content file is not valid UTF-8 text: %s', $relativePath));
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    public function readJson(ContentPackage $package, string $relativePath): array
    {
        $contents = $this->readText($package, $relativePath);
        try {
            return $this->serializer->decode($contents);
        } catch (Throwable $exception) {
            throw new ContentPackageException(sprintf('Content JSON is malformed: %s', $relativePath), 0, $exception);
        }
    }
}
