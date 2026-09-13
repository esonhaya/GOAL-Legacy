<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Content;

use Goal\Legacy\Core\Content\ContentFileLoader;
use Goal\Legacy\Core\Content\ContentPackage;
use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubCompetitionMembership;
use Goal\Legacy\Modules\Club\Domain\ClubContentDefinition;
use Goal\Legacy\Modules\Club\Domain\ClubException;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionDefinition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final class ClubContentLoader
{
    public const CONTENT_DOMAIN = 'club';
    public const DATA_FILE = 'clubs.json';
    public const SUPPORTED_SCHEMA_VERSION = 1;

    public function __construct(private readonly ContentFileLoader $fileLoader = new ContentFileLoader())
    {
    }

    /** @param list<Nation> $nations @param list<CompetitionDefinition> $competitions @return list<ClubContentDefinition> */
    public function loadSelected(ContentPackageCatalog $catalog, array $nations, array $competitions): array
    {
        $nationIds = [];
        foreach ($nations as $nation) {
            $nationIds[$nation->id()->value()] = true;
        }
        $competitionById = [];
        foreach ($competitions as $competition) {
            $competitionById[$competition->id()->value()] = $competition;
        }

        $clubs = [];
        foreach ($catalog->resolvedPackages() as $package) {
            if (!$catalog->isSelected($package->manifest()->id())) {
                continue;
            }
            foreach ($this->loadPackage($package, $nationIds, $competitionById) as $definition) {
                $id = $definition->club()->id()->value();
                if (isset($clubs[$id])) {
                    throw new ClubException(sprintf('Duplicate Club ID "%s" in selected content packages.', $id));
                }
                $clubs[$id] = $definition;
            }
        }

        ksort($clubs, SORT_STRING);

        return array_values($clubs);
    }

    /** @param array<string, bool> $nationIds @param array<string, CompetitionDefinition> $competitionById @return list<ClubContentDefinition> */
    public function loadPackage(ContentPackage $package, array $nationIds, array $competitionById): array
    {
        $metadata = $package->manifest()->metadata();
        if (($metadata['content_domain'] ?? null) !== self::CONTENT_DOMAIN) {
            return [];
        }
        if (!in_array(self::DATA_FILE, $package->manifest()->files(), true)) {
            throw new ClubException(sprintf('Club package "%s" must declare %s.', $package->manifest()->id(), self::DATA_FILE));
        }

        $data = $this->fileLoader->readJson($package, self::DATA_FILE);
        $schemaVersion = $data['schema_version'] ?? null;
        if (!is_int($schemaVersion) || $schemaVersion < 1) {
            throw new ClubException(sprintf('Club content in package "%s" has an invalid schema_version.', $package->manifest()->id()));
        }
        if ($schemaVersion > self::SUPPORTED_SCHEMA_VERSION) {
            throw new ClubException(sprintf('Club content in package "%s" uses unsupported schema version %d.', $package->manifest()->id(), $schemaVersion));
        }
        if (!isset($data['clubs']) || !is_array($data['clubs']) || !array_is_list($data['clubs'])) {
            throw new ClubException(sprintf('Club content in package "%s" must contain a clubs list.', $package->manifest()->id()));
        }

        $definitions = [];
        foreach ($data['clubs'] as $index => $record) {
            if (!is_array($record)) {
                throw new ClubException(sprintf('Club record %d in package "%s" must be an object.', $index, $package->manifest()->id()));
            }
            $definitions[] = $this->createDefinition($record, $package, $schemaVersion, $nationIds, $competitionById, $index);
        }

        return $definitions;
    }

    /** @param array<string, mixed> $record @param array<string, bool> $nationIds @param array<string, CompetitionDefinition> $competitionById */
    private function createDefinition(array $record, ContentPackage $package, int $schemaVersion, array $nationIds, array $competitionById, int $index): ClubContentDefinition
    {
        foreach (['id', 'name', 'short_name', 'nation_id', 'city', 'founded_year', 'stadium_name', 'club_colors', 'core_philosophy', 'football_identity', 'current_style', 'memberships'] as $field) {
            if (!array_key_exists($field, $record)) {
                throw new ClubException(sprintf('Club record %d in package "%s" is missing "%s".', $index, $package->manifest()->id(), $field));
            }
        }
        foreach (['id', 'name', 'short_name', 'nation_id', 'city', 'stadium_name', 'club_colors', 'core_philosophy', 'football_identity', 'current_style'] as $field) {
            if (!is_string($record[$field])) {
                throw new ClubException(sprintf('Club record %d field "%s" must be a string.', $index, $field));
            }
        }
        if (!is_int($record['founded_year']) || !is_array($record['memberships']) || !array_is_list($record['memberships'])) {
            throw new ClubException(sprintf('Club record %d has malformed year or membership fields.', $index));
        }
        if (!isset($nationIds[$record['nation_id']])) {
            throw new ClubException(sprintf('Club "%s" references unknown Nation "%s".', $record['id'], $record['nation_id']));
        }

        try {
            $club = new Club(
                new ClubId($record['id']),
                $record['name'],
                $record['short_name'],
                $record['nickname'] ?? null,
                new NationId($record['nation_id']),
                $record['city'],
                $record['founded_year'],
                $record['stadium_name'],
                $record['club_colors'],
                $record['core_philosophy'],
                $record['football_identity'],
                $record['current_style'],
                $this->optionalInt($record, 'reputation', 50, $index),
                $this->optionalInt($record, 'facilities_level', 50, $index),
                $package->manifest()->id(),
                $package->manifest()->version(),
                $schemaVersion,
            );
            $memberships = [];
            foreach ($record['memberships'] as $membershipIndex => $membership) {
                if (!is_array($membership) || !is_string($membership['competition_id'] ?? null) || !is_string($membership['season_id'] ?? null)) {
                    throw new ClubException(sprintf('Club record %d membership %d is malformed.', $index, $membershipIndex));
                }
                $competition = $competitionById[$membership['competition_id']] ?? null;
                if ($competition === null) {
                    throw new ClubException(sprintf('Club "%s" references unknown Competition "%s".', $club->id()->value(), $membership['competition_id']));
                }
                if ($competition->type() === CompetitionType::DomesticLeague && $competition->nationId()->value() !== $club->nationId()->value()) {
                    throw new ClubException(sprintf('Club "%s" cannot join domestic Competition "%s" for another Nation.', $club->id()->value(), $competition->id()->value()));
                }
                $memberships[] = new ClubCompetitionMembership(
                    $club->id(),
                    new CompetitionId($membership['competition_id']),
                    new SeasonId($membership['season_id']),
                );
            }
            if ($memberships === []) {
                throw new ClubException(sprintf('Club "%s" must declare at least one Competition membership.', $club->id()->value()));
            }

            return new ClubContentDefinition($club, $memberships);
        } catch (ClubException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ClubException(sprintf('Club record %d in package "%s" is invalid: %s', $index, $package->manifest()->id(), $exception->getMessage()), 0, $exception);
        }
    }

    /** @param array<string, mixed> $record */
    private function optionalInt(array $record, string $field, int $default, int $index): int
    {
        if (!array_key_exists($field, $record)) {
            return $default;
        }
        if (!is_int($record[$field])) {
            throw new ClubException(sprintf('Club record %d field "%s" must be an integer.', $index, $field));
        }

        return $record[$field];
    }
}
