<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Player\CareerLegacyService;
use Goal\Legacy\Modules\Player\CompetitionStatisticsQuery;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\PlayerCompetitionStatisticsRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerLegacyRepository;
use PHPUnit\Framework\TestCase;

final class P2033SeasonLeaderboardTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testCanonicalQueryMergesControlledMatchEvidenceAndWorldAggregateWithoutDoubleCounting(): void
    {
        [$services, $database, $season] = $this->scenario('p2033-query');
        $playerService = $services->playerModule()->service();
        $controlled = $this->player('p2033-controlled', '1999-01-01', 'ST');
        $npc = $this->player('p2033-npc', '1999-01-01', 'ST');
        $players = new \Goal\Legacy\Modules\Player\Persistence\PlayerRepository($database);
        $players->save($controlled);
        $players->save($npc);

        $match = (new GameMatch(
            new MatchId('p2033-query-match'),
            new CompetitionId('premier-league'),
            $season->id(),
            1,
            SimulationDate::fromIsoString('2024-08-02'),
            new ClubId('arsenal'),
            new ClubId('chelsea'),
        ))->complete(new MatchResult(2, 0));
        (new MatchRepository($database))->save($match);
        (new PlayerMatchStatRepository($database))->replaceForMatch([
            new PlayerMatchStat($match->id(), $controlled->id(), new ClubId('arsenal'), true, true, 90, 2, 1, 2, 2),
        ]);

        $compact = new PlayerCompetitionStatisticsRepository($database);
        $database->connection()->prepare(
            'INSERT INTO player_competition_statistics (player_id, season_id, competition_id, club_id, appearances, starts, minutes, goals, assists, shots, shots_on_target, saves, clean_sheets, tackles, interceptions, blocks, passes_attempted, passes_completed, fouls_committed, yellow_cards, red_cards, rated_appearances, rating_total) VALUES (:player, :season, :competition, :club, 5, 5, 450, :goals, 0, 5, 5, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 5, 35.0)'
        )->execute(['player' => $controlled->id()->value(), 'season' => $season->id()->value(), 'competition' => 'premier-league', 'club' => 'arsenal', 'goals' => 99]);
        $compact->addMatchInTransaction($match, [new PlayerMatchStat($match->id(), $npc->id(), new ClubId('chelsea'), true, true, 90, 1, 2, 2, 1)], ['p2033-npc' => $npc->primaryPosition()]);
        $database->connection()->exec('UPDATE player_competition_statistics SET goals = 5, assists = 4, appearances = 5, starts = 5, minutes = 450 WHERE player_id = \'p2033-npc\'');

        $rows = (new CompetitionStatisticsQuery())->forCompetitionSeason($database, 'premier-league', $season->id());
        $byPlayer = [];
        foreach ($rows as $row) {
            $byPlayer[(string) $row['player_id']] = $row;
        }
        self::assertSame(2, $byPlayer['p2033-controlled']['goals']);
        self::assertSame(1, $byPlayer['p2033-controlled']['assists']);
        self::assertSame(5, $byPlayer['p2033-npc']['goals']);
        self::assertSame(4, $byPlayer['p2033-npc']['assists']);
        self::assertCount(2, $byPlayer);

        $legacy = new CareerLegacyService($services->clubModule()->service(), $services->nationalTeams(), $services->internationalCompetitions());
        $legacy->resolveCompletedSeason($database, $season);
        $awards = (new CareerLegacyRepository($database))->awardsForSeason($season->id()->value());
        $topScorer = array_values(array_filter($awards, static fn (array $award): bool => ($award['award_type'] ?? null) === 'TOP_SCORER'))[0] ?? null;
        self::assertSame('p2033-npc', $topScorer['winner_player_id'] ?? null);
    }

    private function player(string $id, string $birthDate, string $position): \Goal\Legacy\Modules\Player\Domain\Player
    {
        return (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test'])
            ->playerModule()->service()->create(new PlayerCreationRequest(
                $id,
                'Leaderboard',
                'Candidate',
                $id,
                $birthDate,
                'england',
                [],
                'england',
                ['england'],
                180,
                75,
                $position,
                90,
                'regular',
                2033,
                new PlayerAttributeSet(60, 60, 60, 60, 60, 60),
            ));
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026033, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season];
    }
}
