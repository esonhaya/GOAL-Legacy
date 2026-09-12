<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Nation\Content;

use Goal\Legacy\Core\Content\ContentFileLoader;
use Goal\Legacy\Core\Content\ContentPackage;
use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Domain\NationException;
use Goal\Legacy\Modules\Nation\Domain\NationId;

final class NationContentLoader
{
    public const CONTENT_DOMAIN = 'nation';
    public const DATA_FILE = 'nations.json';
    public const SUPPORTED_SCHEMA_VERSION = 1;

    public function __construct(private readonly ContentFileLoader $fileLoader = new ContentFileLoader())
    {
    }

    /** @return list<Nation> */
    public function loadSelected(ContentPackageCatalog $catalog): array
    {
        $nations = [];
        foreach ($catalog->resolvedPackages() as $package) {
            if (!$catalog->isSelected($package->manifest()->id())) {
                continue;
            }
            foreach ($this->loadPackage($package) as $nation) {
                $id = $nation->id()->value();
                if (isset($nations[$id])) {
                    throw new NationException(sprintf('Duplicate Nation ID "%s" in selected content packages.', $id));
                }
                $nations[$id] = $nation;
            }
        }

        ksort($nations, SORT_STRING);

        return array_values($nations);
    }

    /** @return list<Nation> */
    public function loadPackage(ContentPackage $package): array
    {
        $metadata = $package->manifest()->metadata();
        if (($metadata['content_domain'] ?? null) !== self::CONTENT_DOMAIN) {
            return [];
        }
        if (!in_array(self::DATA_FILE, $package->manifest()->files(), true)) {
            throw new NationException(sprintf('Nation package "%s" must declare %s.', $package->manifest()->id(), self::DATA_FILE));
        }

        $data = $this->fileLoader->readJson($package, self::DATA_FILE);
        $schemaVersion = $data['schema_version'] ?? null;
        if (!is_int($schemaVersion) || $schemaVersion < 1) {
            throw new NationException(sprintf('Nation content in package "%s" has an invalid schema_version.', $package->manifest()->id()));
        }
        if ($schemaVersion > self::SUPPORTED_SCHEMA_VERSION) {
            throw new NationException(sprintf('Nation content in package "%s" uses unsupported schema version %d.', $package->manifest()->id(), $schemaVersion));
        }
        if (!isset($data['nations']) || !is_array($data['nations']) || !array_is_list($data['nations'])) {
            throw new NationException(sprintf('Nation content in package "%s" must contain a nations list.', $package->manifest()->id()));
        }

        $nations = [];
        foreach ($data['nations'] as $index => $record) {
            if (!is_array($record)) {
                throw new NationException(sprintf('Nation record %d in package "%s" must be an object.', $index, $package->manifest()->id()));
            }
            $nations[] = $this->createNation($record, $package, $schemaVersion, $index);
        }

        return $nations;
    }

    /** @param array<string, mixed> $record */
    private function createNation(array $record, ContentPackage $package, int $schemaVersion, int $index): Nation
    {
        foreach (['id', 'canonical_name', 'display_name'] as $field) {
            if (!array_key_exists($field, $record)) {
                throw new NationException(sprintf('Nation record %d in package "%s" is missing "%s".', $index, $package->manifest()->id(), $field));
            }
        }
        $stringFields = ['id', 'canonical_name', 'display_name', 'code', 'region_id', 'geography_reference_id', 'association_id'];
        foreach ($stringFields as $field) {
            if (array_key_exists($field, $record) && $record[$field] !== null && !is_string($record[$field])) {
                throw new NationException(sprintf('Nation record %d field "%s" must be a string or null.', $index, $field));
            }
        }
        if (!is_string($record['id']) || !is_string($record['canonical_name']) || !is_string($record['display_name'])) {
            throw new NationException(sprintf('Nation record %d in package "%s" has invalid identity field types.', $index, $package->manifest()->id()));
        }

        try {
            return new Nation(
                new NationId($record['id']),
                $record['canonical_name'],
                $record['display_name'],
                $record['code'] ?? null,
                $record['region_id'] ?? null,
                $record['geography_reference_id'] ?? null,
                $record['association_id'] ?? null,
                $package->manifest()->id(),
                $package->manifest()->version(),
                $schemaVersion,
            );
        } catch (NationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new NationException(sprintf('Nation record %d in package "%s" is invalid: %s', $index, $package->manifest()->id(), $exception->getMessage()), 0, $exception);
        }
    }
}
