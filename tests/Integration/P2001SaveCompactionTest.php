<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\World\SeasonCompactionService;
use PHPUnit\Framework\TestCase;

final class P2001SaveCompactionTest extends TestCase
{
    public function testCompletedSeasonCompactionKeepsControlledEvidenceAndArchivesNpcFacts(): void
    {
        $database = new SqliteDatabase(':memory:');
        $this->schema($database);
        $this->fixtures($database);

        $result = (new SeasonCompactionService())->compact($database, 'season-2024-25', '2025-08-01');

        self::assertTrue($result['compacted']);
        self::assertSame(1, $result['stats']);
        self::assertSame(1, $result['selections']);
        self::assertSame(1, $result['evaluations']);
        self::assertSame(1, $result['development']);
        self::assertSame(2, $result['availability']);
        self::assertSame(1, (int) $database->connection()->query('SELECT COUNT(*) FROM player_season_statistics')->fetchColumn());
        self::assertSame(1, (int) $database->connection()->query("SELECT goals FROM player_season_statistics WHERE player_id = 'npc-1'")->fetchColumn());
        self::assertSame(1, (int) $database->connection()->query("SELECT COUNT(*) FROM match_player_stats WHERE player_id = 'controlled-1'")->fetchColumn());
        self::assertSame(0, (int) $database->connection()->query("SELECT COUNT(*) FROM match_player_stats WHERE player_id = 'npc-1'")->fetchColumn());
        self::assertSame(3, (int) $database->connection()->query("SELECT COUNT(*) FROM career_match_evaluations WHERE player_id = 'controlled-1'")->fetchColumn());
        self::assertSame(2, (int) $database->connection()->query("SELECT COUNT(*) FROM career_match_evaluations WHERE player_id = 'npc-1'")->fetchColumn());
        self::assertSame(1, (int) $database->connection()->query("SELECT evaluation_count FROM player_form_summaries WHERE player_id = 'npc-1' AND club_id = 'club-1'")->fetchColumn());
        self::assertSame(1, (int) $database->connection()->query("SELECT COUNT(*) FROM player_development_history WHERE player_id = 'controlled-1'")->fetchColumn());
        self::assertSame(0, (int) $database->connection()->query("SELECT COUNT(*) FROM player_development_history WHERE player_id = 'npc-1'")->fetchColumn());
        self::assertSame(0, (int) $database->connection()->query('SELECT COUNT(*) FROM player_availability_sources')->fetchColumn());
        self::assertSame('ok', $database->connection()->query('PRAGMA integrity_check')->fetchColumn());

        self::assertFalse((new SeasonCompactionService())->compact($database, 'season-2024-25', '2025-08-01')['compacted']);
    }

    private function schema(SqliteDatabase $database): void
    {
        $connection = $database->connection();
        $connection->exec('CREATE TABLE career_player_references (career_id TEXT PRIMARY KEY, player_id TEXT NOT NULL UNIQUE, start_date TEXT NOT NULL)');
        $connection->exec('CREATE TABLE player_records (id TEXT PRIMARY KEY, primary_position TEXT NOT NULL)');
        $connection->exec('CREATE TABLE match_records (id TEXT PRIMARY KEY, season_id TEXT NOT NULL, status TEXT NOT NULL)');
        $connection->exec('CREATE TABLE match_player_stats (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, appeared INTEGER NOT NULL, started INTEGER NOT NULL, minutes INTEGER NOT NULL, goals INTEGER NOT NULL, assists INTEGER NOT NULL, shots INTEGER NOT NULL, shots_on_target INTEGER NOT NULL, saves INTEGER NOT NULL, clean_sheets INTEGER NOT NULL, tackles INTEGER NOT NULL, interceptions INTEGER NOT NULL, blocks INTEGER NOT NULL, passes_attempted INTEGER NOT NULL, passes_completed INTEGER NOT NULL, fouls_committed INTEGER NOT NULL, yellow_cards INTEGER NOT NULL, red_cards INTEGER NOT NULL, PRIMARY KEY (match_id, player_id))');
        $connection->exec('CREATE TABLE match_player_selections (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, status TEXT NOT NULL, PRIMARY KEY (match_id, player_id))');
        $connection->exec('CREATE TABLE career_match_evaluations (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, occurred_date TEXT NOT NULL, evaluation_score INTEGER NOT NULL, expectation_status TEXT NOT NULL, PRIMARY KEY (match_id, player_id))');
        $connection->exec('CREATE TABLE player_development_history (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, occurred_date TEXT NOT NULL, source TEXT NOT NULL, source_id TEXT NOT NULL, attribute_deltas_json TEXT NOT NULL, before_ovr INTEGER NOT NULL, after_ovr INTEGER NOT NULL)');
        $connection->exec('CREATE TABLE player_availability_sources (player_id TEXT NOT NULL, source_type TEXT NOT NULL, source_id TEXT NOT NULL, fatigue_delta INTEGER NOT NULL, occurred_date TEXT NOT NULL, PRIMARY KEY (player_id, source_type, source_id))');
        $connection->exec('CREATE INDEX idx_match_selection_club_status ON match_player_selections (club_id, status, match_id)');
        $connection->exec('CREATE INDEX idx_match_stats_club ON match_player_stats (club_id, match_id)');
        $connection->exec('CREATE INDEX idx_career_evaluations_club ON career_match_evaluations (club_id, occurred_date, player_id)');
        $connection->exec('CREATE INDEX idx_match_highlights_player ON match_player_stats (player_id, match_id)');
        $connection->exec('CREATE INDEX idx_match_substitutions_player ON match_player_stats (player_id, match_id)');
        $connection->exec('CREATE INDEX idx_match_substitutions_club ON match_player_stats (club_id, match_id)');
    }

    private function fixtures(SqliteDatabase $database): void
    {
        $connection = $database->connection();
        $connection->exec("INSERT INTO career_player_references VALUES ('career-1', 'controlled-1', '2024-08-01')");
        $connection->exec("INSERT INTO player_records VALUES ('controlled-1', 'CM'), ('npc-1', 'CM')");
        $connection->exec("INSERT INTO match_records VALUES ('match-1', 'season-2024-25', 'completed'), ('match-2', 'season-2024-25', 'completed'), ('match-3', 'season-2024-25', 'completed')");
        $stat = "(match_id, player_id, club_id, appeared, started, minutes, goals, assists, shots, shots_on_target, saves, clean_sheets, tackles, interceptions, blocks, passes_attempted, passes_completed, fouls_committed, yellow_cards, red_cards)";
        $connection->exec("INSERT INTO match_player_stats $stat VALUES ('match-1', 'controlled-1', 'club-1', 1, 1, 90, 0, 0, 1, 1, 0, 0, 2, 1, 0, 40, 32, 1, 0, 0), ('match-1', 'npc-1', 'club-1', 1, 1, 90, 1, 0, 2, 2, 0, 0, 1, 1, 0, 35, 30, 1, 0, 0)");
        $connection->exec("INSERT INTO match_player_selections VALUES ('match-1', 'controlled-1', 'club-1', 'starter'), ('match-1', 'npc-1', 'club-1', 'starter')");
        $connection->exec("INSERT INTO career_match_evaluations VALUES ('match-1', 'controlled-1', 'club-1', '2024-08-10', 70, 'met'), ('match-2', 'controlled-1', 'club-1', '2024-08-20', 72, 'met'), ('match-3', 'controlled-1', 'club-1', '2024-08-30', 74, 'met'), ('match-1', 'npc-1', 'club-1', '2024-08-10', 60, 'met'), ('match-2', 'npc-1', 'club-1', '2024-08-20', 62, 'met'), ('match-3', 'npc-1', 'club-1', '2024-08-30', 64, 'met')");
        $connection->exec("INSERT INTO player_development_history VALUES ('dev-controlled', 'controlled-1', '2024-08-10', 'training', 'training-1', '{}', 50, 51), ('dev-npc', 'npc-1', '2024-08-10', 'training', 'training-2', '{}', 50, 51)");
        $connection->exec("INSERT INTO player_availability_sources VALUES ('controlled-1', 'training', 'training-1', 1, '2024-08-10'), ('npc-1', 'training', 'training-2', 1, '2024-08-10')");
    }
}
