<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use Goal\Legacy\Core\Persistence\SqlProfiler;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\Club\Persistence\ClubRepository;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use PHPUnit\Framework\TestCase;

final class P2034ReadPathPerformanceTest extends TestCase
{
    public function testClubAndCompetitionSchemaReadsAreInitializedOncePerConnection(): void
    {
        $profiler = new SqlProfiler();
        $database = new SqliteDatabase(':memory:', $profiler);

        for ($index = 0; $index < 32; ++$index) {
            new ClubRepository($database);
            new CompetitionRepository($database);
        }

        $profiles = $profiler->snapshot()['queries'];
        self::assertSame(1, $this->callsFor($profiles, 'CREATE TABLE IF NOT EXISTS club_records'));
        self::assertSame(1, $this->callsFor($profiles, 'CREATE INDEX IF NOT EXISTS idx_club_records_nation_id'));
        self::assertSame(1, $this->callsFor($profiles, 'CREATE TABLE IF NOT EXISTS competition_records'));
        self::assertSame(1, $this->callsFor($profiles, 'PRAGMA table_info(competition_records)'));

        $writes = array_filter(
            $profiles,
            static fn (array $profile): bool => preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', (string) $profile['fingerprint']) === 1,
        );
        self::assertSame([], $writes);
    }

    /** @param list<array<string, mixed>> $profiles */
    private function callsFor(array $profiles, string $prefix): int
    {
        foreach ($profiles as $profile) {
            if (str_starts_with((string) $profile['fingerprint'], $prefix)) {
                return (int) $profile['calls'];
            }
        }

        return 0;
    }
}
