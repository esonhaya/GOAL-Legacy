<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PDO;

/**
 * Compact World-fidelity evidence scoped to one competition.
 *
 * This is an aggregate boundary, not NPC Match detail. It gives bounded
 * season consumers such as awards reliable competition context after the
 * replay-only Match rows have been discarded.
 */
final class PlayerCompetitionStatisticsRepository
{
    private const TABLE = 'player_competition_statistics';

    public function __construct(private readonly DatabaseInterface $database, bool $initialize = true)
    {
        if (!$initialize) {
            return;
        }
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'player_id TEXT NOT NULL, season_id TEXT NOT NULL, competition_id TEXT NOT NULL, club_id TEXT NOT NULL, '
                . 'appearances INTEGER NOT NULL, starts INTEGER NOT NULL, minutes INTEGER NOT NULL, '
                . 'goals INTEGER NOT NULL, assists INTEGER NOT NULL, shots INTEGER NOT NULL, '
                . 'shots_on_target INTEGER NOT NULL, saves INTEGER NOT NULL, clean_sheets INTEGER NOT NULL, '
                . 'tackles INTEGER NOT NULL, interceptions INTEGER NOT NULL, blocks INTEGER NOT NULL, '
                . 'passes_attempted INTEGER NOT NULL, passes_completed INTEGER NOT NULL, '
                . 'fouls_committed INTEGER NOT NULL, yellow_cards INTEGER NOT NULL, red_cards INTEGER NOT NULL, '
                . 'rated_appearances INTEGER NOT NULL, rating_total REAL NOT NULL, '
                . 'PRIMARY KEY (player_id, season_id, competition_id, club_id))'
            );
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_competition_statistics_season ON ' . self::TABLE . ' (season_id, competition_id, player_id, club_id)');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_competition_statistics_player ON ' . self::TABLE . ' (player_id, season_id, competition_id, club_id)');
        });
    }

    /** @param list<PlayerMatchStat> $stats @param array<string, PlayerPosition> $positions */
    public function addMatchInTransaction(GameMatch $match, array $stats, array $positions): void
    {
        if ($stats === []) {
            return;
        }
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' '
            . '(player_id, season_id, competition_id, club_id, appearances, starts, minutes, goals, assists, shots, shots_on_target, saves, clean_sheets, tackles, interceptions, blocks, passes_attempted, passes_completed, fouls_committed, yellow_cards, red_cards, rated_appearances, rating_total) '
            . 'VALUES (:player_id, :season_id, :competition_id, :club_id, :appearances, :starts, :minutes, :goals, :assists, :shots, :shots_on_target, :saves, :clean_sheets, :tackles, :interceptions, :blocks, :passes_attempted, :passes_completed, :fouls_committed, :yellow_cards, :red_cards, :rated_appearances, :rating_total) '
            . 'ON CONFLICT(player_id, season_id, competition_id, club_id) DO UPDATE SET '
            . 'appearances = appearances + excluded.appearances, starts = starts + excluded.starts, minutes = minutes + excluded.minutes, '
            . 'goals = goals + excluded.goals, assists = assists + excluded.assists, shots = shots + excluded.shots, '
            . 'shots_on_target = shots_on_target + excluded.shots_on_target, saves = saves + excluded.saves, clean_sheets = clean_sheets + excluded.clean_sheets, '
            . 'tackles = tackles + excluded.tackles, interceptions = interceptions + excluded.interceptions, blocks = blocks + excluded.blocks, '
            . 'passes_attempted = passes_attempted + excluded.passes_attempted, passes_completed = passes_completed + excluded.passes_completed, '
            . 'fouls_committed = fouls_committed + excluded.fouls_committed, yellow_cards = yellow_cards + excluded.yellow_cards, red_cards = red_cards + excluded.red_cards, '
            . 'rated_appearances = rated_appearances + excluded.rated_appearances, rating_total = rating_total + excluded.rating_total'
        );
        $ratings = new PlayerMatchRatingService();
        foreach ($stats as $stat) {
            if (!$stat->appeared()) {
                continue;
            }
            $position = $positions[$stat->playerId()->value()] ?? null;
            $rating = $position === null ? null : $ratings->rate($stat, $position);
            $statement->execute([
                'player_id' => $stat->playerId()->value(),
                'season_id' => $match->seasonId()->value(),
                'competition_id' => $match->competitionId()->value(),
                'club_id' => $stat->clubId()->value(),
                'appearances' => 1,
                'starts' => $stat->started() ? 1 : 0,
                'minutes' => $stat->minutes(),
                'goals' => $stat->goals(),
                'assists' => $stat->assists(),
                'shots' => $stat->shots(),
                'shots_on_target' => $stat->shotsOnTarget(),
                'saves' => $stat->saves(),
                'clean_sheets' => $stat->cleanSheets(),
                'tackles' => $stat->tackles(),
                'interceptions' => $stat->interceptions(),
                'blocks' => $stat->blocks(),
                'passes_attempted' => $stat->passesAttempted(),
                'passes_completed' => $stat->passesCompleted(),
                'fouls_committed' => $stat->foulsCommitted(),
                'yellow_cards' => $stat->yellowCards(),
                'red_cards' => $stat->redCards(),
                'rated_appearances' => $rating === null ? 0 : 1,
                'rating_total' => $rating ?? 0.0,
            ]);
        }
    }

    /** @return list<array<string, int|float|string>> */
    public function byCompetitionSeason(string $competitionId, SeasonId|string $seasonId): array
    {
        if (!$this->available()) {
            return [];
        }
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY player_id ASC, club_id ASC');
        $statement->execute(['competition_id' => $competitionId, 'season_id' => $season]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, int|float|string>> */
    public function byPlayer(string $playerId): array
    {
        if (!$this->available()) {
            return [];
        }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id ORDER BY season_id ASC, competition_id ASC, club_id ASC');
        $statement->execute(['player_id' => $playerId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function available(): bool
    {
        $statement = $this->database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => self::TABLE]);

        return $statement->fetchColumn() !== false;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, int|float|string>> */
    private function rows(array $rows): array
    {
        return array_map(static function (array $row): array {
            foreach ($row as $key => $value) {
                if (in_array($key, ['player_id', 'season_id', 'competition_id', 'club_id'], true)) {
                    $row[$key] = (string) $value;
                } elseif ($key === 'rating_total') {
                    $row[$key] = (float) $value;
                } else {
                    $row[$key] = (int) $value;
                }
            }

            return $row;
        }, $rows);
    }
}
