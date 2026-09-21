<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Devtools\Presentation\CompetitionLeaderboardProjection;
use PHPUnit\Framework\TestCase;

final class CompetitionLeaderboardProjectionTest extends TestCase
{
    public function testGoalsAndAssistsAreCompetitionScopedAndCombineASeasonTransfer(): void
    {
        $projection = (new CompetitionLeaderboardProjection())->project(
            [
                $this->row('player-a', 'club-a', 6, 4, 4, 90),
                $this->row('player-a', 'club-b', 4, 2, 2, 90),
                $this->row('player-b', 'club-c', 10, 1, 3, 90),
                $this->row('player-c', 'club-d', 8, 4, 3, 90),
                $this->row('player-d', 'club-e', 7, 8, 2, 90),
            ],
            ['player-a' => 'A', 'player-b' => 'B', 'player-c' => 'C', 'player-d' => 'D'],
            ['club-a' => 'Club A', 'club-b' => 'Club B', 'club-c' => 'Club C', 'club-d' => 'Club D', 'club-e' => 'Club E'],
            'premier-league',
            'Premier League',
            'season-2024-25',
            '2024/25',
            'player-a',
        );

        self::assertSame('2024/25', $projection['season']);
        self::assertSame(['goals', 'assists'], array_keys($projection['categories']));
        self::assertSame('player-a', $projection['categories']['goals']['leader']['player_id']);
        self::assertSame(10, $projection['categories']['goals']['controlled']['value']);
        self::assertSame(1, $projection['categories']['goals']['controlled']['position']);
        self::assertSame(['Club A', 'Club B'], $projection['categories']['goals']['controlled']['clubs']);
        self::assertSame(2, $projection['categories']['assists']['controlled']['position']);
        self::assertSame(2, $projection['categories']['assists']['controlled']['gap_to_leader']);
        self::assertSame(['player-a', 'player-b', 'player-c', 'player-d'], array_column($projection['categories']['goals']['entries'], 'player_id'));
    }

    public function testTiesShareTheirFactualPositionAndOutsideTopTenStandingIsBounded(): void
    {
        $rows = [];
        for ($index = 1; $index <= 12; ++$index) {
            $rows[] = $this->row('player-' . $index, 'club-' . $index, $index <= 2 ? 10 : 12 - $index, 1, 1, 90);
        }
        $projection = (new CompetitionLeaderboardProjection())->project(
            $rows,
            array_combine(array_map(static fn (int $index): string => 'player-' . $index, range(1, 12)), array_map(static fn (int $index): string => 'Player ' . $index, range(1, 12))),
            [],
            'premier-league',
            'Premier League',
            'season-2024-25',
            '2024/25',
            'player-12',
        );

        self::assertCount(10, $projection['categories']['goals']['entries']);
        self::assertSame(1, $projection['categories']['goals']['entries'][0]['position']);
        self::assertSame(1, $projection['categories']['goals']['entries'][1]['position']);
        self::assertNull($projection['categories']['goals']['controlled']['position']);
        self::assertSame('outside_top_ten', $projection['categories']['goals']['controlled']['state']);
    }

    public function testEmptySeasonDoesNotInventRaceLanguage(): void
    {
        $projection = (new CompetitionLeaderboardProjection())->project([], [], [], 'premier-league', 'Premier League', 'season-2024-25', '2024/25', 'player-1');

        self::assertSame([], $projection['categories']['goals']['entries']);
        self::assertNull($projection['categories']['goals']['leader']);
        self::assertSame('not_started', $projection['categories']['goals']['state']);
        self::assertNull($projection['categories']['goals']['controlled']);
    }

    /** @return array<string, mixed> */
    private function row(string $player, string $club, int $goals, int $assists, int $appearances, int $minutes): array
    {
        return ['player_id' => $player, 'club_id' => $club, 'goals' => $goals, 'assists' => $assists, 'appearances' => $appearances, 'minutes' => $minutes, 'clean_sheets' => 0, 'rated_appearances' => 0, 'rating_total' => 0.0];
    }
}
