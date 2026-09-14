<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition\Content;

use Goal\Legacy\Core\Content\ContentFileLoader;
use Goal\Legacy\Core\Content\ContentPackage;
use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Modules\Competition\Domain\CompetitionDefinition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionException;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Domain\NationId;

final class CompetitionContentLoader
{
    public const CONTENT_DOMAIN = 'competition';
    public const DATA_FILE = 'competitions.json';
    public const SUPPORTED_SCHEMA_VERSION = 1;

    public function __construct(private readonly ContentFileLoader $fileLoader = new ContentFileLoader())
    {
    }

    /** @param list<Nation> $nations @return list<CompetitionDefinition> */
    public function loadSelected(ContentPackageCatalog $catalog, array $nations): array
    {
        $nationIds = [];
        foreach ($nations as $nation) {
            $nationIds[$nation->id()->value()] = true;
        }

        $competitions = [];
        foreach ($catalog->resolvedPackages() as $package) {
            if (!$catalog->isSelected($package->manifest()->id())) {
                continue;
            }
            foreach ($this->loadPackage($package, $nationIds) as $competition) {
                $id = $competition->id()->value();
                if (isset($competitions[$id])) {
                    throw new CompetitionException(sprintf('Duplicate Competition ID "%s" in selected content packages.', $id));
                }
                $competitions[$id] = $competition;
            }
        }

        ksort($competitions, SORT_STRING);

        return array_values($competitions);
    }

    /** @param array<string, bool> $nationIds @return list<CompetitionDefinition> */
    public function loadPackage(ContentPackage $package, array $nationIds): array
    {
        $metadata = $package->manifest()->metadata();
        if (($metadata['content_domain'] ?? null) !== self::CONTENT_DOMAIN) {
            return [];
        }
        if (!in_array(self::DATA_FILE, $package->manifest()->files(), true)) {
            throw new CompetitionException(sprintf('Competition package "%s" must declare %s.', $package->manifest()->id(), self::DATA_FILE));
        }

        $data = $this->fileLoader->readJson($package, self::DATA_FILE);
        $schemaVersion = $data['schema_version'] ?? null;
        if (!is_int($schemaVersion) || $schemaVersion < 1) {
            throw new CompetitionException(sprintf('Competition content in package "%s" has an invalid schema_version.', $package->manifest()->id()));
        }
        if ($schemaVersion > self::SUPPORTED_SCHEMA_VERSION) {
            throw new CompetitionException(sprintf('Competition content in package "%s" uses unsupported schema version %d.', $package->manifest()->id(), $schemaVersion));
        }
        if (!isset($data['competitions']) || !is_array($data['competitions']) || !array_is_list($data['competitions'])) {
            throw new CompetitionException(sprintf('Competition content in package "%s" must contain a competitions list.', $package->manifest()->id()));
        }

        $definitions = [];
        foreach ($data['competitions'] as $index => $record) {
            if (!is_array($record)) {
                throw new CompetitionException(sprintf('Competition record %d in package "%s" must be an object.', $index, $package->manifest()->id()));
            }
            $definitions[] = $this->createDefinition($record, $package, $schemaVersion, $nationIds, $index);
        }

        return $definitions;
    }

    /** @param array<string, mixed> $record @param array<string, bool> $nationIds */
    private function createDefinition(array $record, ContentPackage $package, int $schemaVersion, array $nationIds, int $index): CompetitionDefinition
    {
        foreach (['id', 'name', 'short_name', 'type', 'nation_id'] as $field) {
            if (!array_key_exists($field, $record)) {
                throw new CompetitionException(sprintf('Competition record %d in package "%s" is missing "%s".', $index, $package->manifest()->id(), $field));
            }
            if (!is_string($record[$field])) {
                throw new CompetitionException(sprintf('Competition record %d field "%s" must be a string.', $index, $field));
            }
        }
        if (!isset($nationIds[$record['nation_id']])) {
            throw new CompetitionException(sprintf('Competition "%s" references unknown Nation "%s".', $record['id'], $record['nation_id']));
        }

        try {
            return new CompetitionDefinition(
                new CompetitionId($record['id']),
                $record['name'],
                $record['short_name'],
                CompetitionType::from($record['type']),
                new NationId($record['nation_id']),
                $package->manifest()->id(),
                $package->manifest()->version(),
                $schemaVersion,
                isset($record['maximum_substitutions']) ? (int) $record['maximum_substitutions'] : 5,
                isset($record['tier']) ? (int) $record['tier'] : 1,
            );
        } catch (\Throwable $exception) {
            throw new CompetitionException(sprintf('Competition record %d in package "%s" is invalid: %s', $index, $package->manifest()->id(), $exception->getMessage()), 0, $exception);
        }
    }
}
