<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\PlayerTrait;
use Goal\Legacy\Modules\Player\Persistence\PositionDevelopmentRepository;
use Goal\Legacy\Modules\World\Persistence\PlayerSeasonStatisticsRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;

/**
 * Read-only football identity derived from canonical Player and Match/Season
 * evidence. Traits never feed back into simulation, ratings, or development.
 */
final class PlayerTraitService
{
    private const ESTABLISHED_MINUTES = 1200;
    private const EMERGING_MINUTES = 450;
    private const ESTABLISHED_ACTIVE_LIMIT = 5;
    private const EMERGING_ACTIVE_LIMIT = 3;

    /** @return array<string, mixed> */
    public function forPlayer(DatabaseInterface $database, PlayerId|string $playerId, SeasonId|string|null $seasonId = null): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $player = (new \Goal\Legacy\Modules\Player\Persistence\PlayerRepository($database))->get($id);
        $season = $seasonId === null ? null : ($seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId));

        return $this->derive($database, $player, $season);
    }

    /** @return array<string, mixed> */
    public function derive(DatabaseInterface $database, Player $player, ?SeasonId $seasonId = null): array
    {
        // Controlled Players retain detailed Match evidence. NPC Profile
        // reads stay on the compact Season aggregate and never introduce a
        // second detailed simulation or a World-wide trait scan.
        [$career, $current] = $this->footballEvidence($database, $player, $seasonId);
        $secondary = (new PositionDevelopmentRepository($database, false))->state($player->id())->secondaryPositions();
        $position = $player->primaryPosition();

        $candidates = [];
        foreach (PlayerTrait::cases() as $trait) {
            $evaluation = $this->evaluate($trait, $player, $position, $career, $current, $secondary);
            if ($evaluation === null) {
                continue;
            }
            $candidates[] = [
                'key' => $trait->value,
                'label' => $trait->label(),
                'state' => $evaluation['state'],
                'description' => $trait->description(),
                'evidence' => $evaluation['evidence'],
                'priority' => $trait->priority(),
            ];
        }

        usort($candidates, static fn (array $left, array $right): int =>
            (($right['state'] === 'established' ? 1 : 0) <=> ($left['state'] === 'established' ? 1 : 0))
            ?: (($right['priority'] <=> $left['priority']) ?: strcmp((string) $left['key'], (string) $right['key']))
        );
        $established = array_values(array_filter($candidates, static fn (array $trait): bool => $trait['state'] === 'established'));
        $emerging = array_values(array_filter($candidates, static fn (array $trait): bool => $trait['state'] === 'emerging'));
        $establishedTotal = count($established);
        $emergingTotal = count($emerging);
        $established = array_slice($established, 0, self::ESTABLISHED_ACTIVE_LIMIT);
        $emerging = array_slice($emerging, 0, self::EMERGING_ACTIVE_LIMIT);
        $strip = static function (array $trait): array {
            unset($trait['priority']);

            return $trait;
        };

        return [
            'active' => array_map($strip, $established),
            'established' => array_map($strip, $established),
            'emerging' => array_map($strip, $emerging),
            'established_total' => $establishedTotal,
            'emerging_total' => $emergingTotal,
            'active_limit' => self::ESTABLISHED_ACTIVE_LIMIT,
            'emerging_limit' => self::EMERGING_ACTIVE_LIMIT,
            'evidence_window' => $seasonId === null ? 'career' : 'career_plus_current_season',
            'derived' => true,
        ];
    }

    /** @return array{0:array<string,int|float|null>,1:array<string,int|float|null>} */
    private function footballEvidence(DatabaseInterface $database, Player $player, ?SeasonId $seasonId): array
    {
        if ($this->isControlledPlayer($database, $player->id())) {
            $statistics = new PlayerCareerStatisticsService();
            $career = $this->evidence($statistics->careerDetailed($database, $player->id()->value()));
            $current = $seasonId === null
                ? $career
                : $this->evidence($statistics->seasonDetailed($database, $player->id()->value(), $seasonId));

            return [$career, $current];
        }

        $statistics = new PlayerSeasonStatisticsRepository($database, false);
        $career = $this->compactEvidence($statistics->byPlayer($player->id()));
        $current = $seasonId === null
            ? $career
            : $this->compactEvidence($statistics->byPlayerSeason($player->id()->value(), $seasonId));

        return [$career, $current];
    }

    private function isControlledPlayer(DatabaseInterface $database, PlayerId $playerId): bool
    {
        $table = $database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'career_player_references'");
        $table->execute();
        if ($table->fetchColumn() === false) {
            return false;
        }
        $statement = $database->connection()->prepare('SELECT 1 FROM career_player_references WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId->value()]);

        return $statement->fetchColumn() !== false;
    }

    /** @param list<array<string,int|float|string>> $rows @return array<string,int|float|null> */
    private function compactEvidence(array $rows): array
    {
        $result = [
            'appearances' => 0,
            'minutes' => 0,
            'goals' => 0,
            'assists' => 0,
            'shots' => 0,
            'shots_on_target' => 0,
            'clean_sheets' => 0,
            'tackles' => 0,
            'interceptions' => 0,
            'blocks' => 0,
            'passes_attempted' => 0,
            'passes_completed' => 0,
            'rated_appearances' => 0,
            'rating_total' => 0.0,
        ];
        foreach ($rows as $row) {
            foreach (array_keys($result) as $key) {
                $result[$key] += $key === 'rating_total' ? (float) ($row[$key] ?? 0.0) : (int) ($row[$key] ?? 0);
            }
        }
        $result['average_match_rating'] = $result['rated_appearances'] > 0
            ? round($result['rating_total'] / $result['rated_appearances'], 2)
            : null;

        return $this->evidence($result);
    }

    /** @param array<string, int|float|null> $row @return array<string, int|float|null> */
    private function evidence(array $row): array
    {
        return [
            'appearances' => (int) ($row['appearances'] ?? 0),
            'minutes' => (int) ($row['minutes'] ?? 0),
            'goals' => (int) ($row['goals'] ?? 0),
            'assists' => (int) ($row['assists'] ?? 0),
            'shots' => (int) ($row['shots'] ?? 0),
            'shots_on_target' => (int) ($row['shots_on_target'] ?? 0),
            'clean_sheets' => (int) ($row['clean_sheets'] ?? 0),
            'tackles' => (int) ($row['tackles'] ?? 0),
            'interceptions' => (int) ($row['interceptions'] ?? 0),
            'blocks' => (int) ($row['blocks'] ?? 0),
            'passes_attempted' => (int) ($row['passes_attempted'] ?? 0),
            'passes_completed' => (int) ($row['passes_completed'] ?? 0),
            'average_match_rating' => ($row['average_match_rating'] ?? null) === null ? null : (float) $row['average_match_rating'],
        ];
    }

    /** @param array<string, int|float|null> $career @param array<string, int|float|null> $current @param list<\Goal\Legacy\Modules\Player\Domain\PlayerPosition> $secondary @return array{state:string,evidence:array<string,mixed>}|null */
    private function evaluate(PlayerTrait $trait, Player $player, PlayerPosition $position, array $career, array $current, array $secondary): ?array
    {
        $established = false;
        $emerging = false;
        $evidence = [];
        $defensiveActions = $career['tackles'] + $career['interceptions'] + $career['blocks'];
        $currentDefensiveActions = $current['tackles'] + $current['interceptions'] + $current['blocks'];
        $passRate = $career['passes_attempted'] > 0 ? $career['passes_completed'] / $career['passes_attempted'] : 0.0;
        $currentPassRate = $current['passes_attempted'] > 0 ? $current['passes_completed'] / $current['passes_attempted'] : 0.0;
        $conversion = $career['shots'] > 0 ? $career['goals'] / $career['shots'] : 0.0;
        $currentConversion = $current['shots'] > 0 ? $current['goals'] / $current['shots'] : 0.0;
        $attacking = in_array($position, [PlayerPosition::Striker, PlayerPosition::LeftWinger, PlayerPosition::RightWinger, PlayerPosition::AttackingMidfielder], true);
        $creative = in_array($position, [PlayerPosition::CentralMidfielder, PlayerPosition::AttackingMidfielder, PlayerPosition::DefensiveMidfielder, PlayerPosition::LeftWinger, PlayerPosition::RightWinger], true);
        $defensive = in_array($position, [PlayerPosition::CentreBack, PlayerPosition::LeftBack, PlayerPosition::RightBack, PlayerPosition::DefensiveMidfielder, PlayerPosition::CentralMidfielder], true);

        switch ($trait) {
            case PlayerTrait::Finisher:
                $established = $attacking && $player->attributes()->shooting() >= 68 && $career['minutes'] >= self::ESTABLISHED_MINUTES && $career['goals'] >= 8 && $career['shots'] >= 30 && $conversion >= 0.16;
                $emerging = $attacking && $player->attributes()->shooting() >= 64 && $current['minutes'] >= self::EMERGING_MINUTES && $current['goals'] >= 3 && $current['shots'] >= 12 && $currentConversion >= 0.15;
                $evidence = ['goals' => $career['goals'], 'shots' => $career['shots'], 'minutes' => $career['minutes']];
                break;
            case PlayerTrait::GoalThreat:
                $established = $attacking && $player->attributes()->shooting() >= 62 && $career['minutes'] >= self::ESTABLISHED_MINUTES && $career['goals'] >= 6 && $career['shots'] >= 50 && $career['shots_on_target'] >= 20;
                $emerging = $attacking && $player->attributes()->shooting() >= 60 && $current['minutes'] >= self::EMERGING_MINUTES && $current['goals'] >= 2 && $current['shots'] >= 18 && $current['shots_on_target'] >= 6;
                $evidence = ['goals' => $career['goals'], 'shots' => $career['shots'], 'shots_on_target' => $career['shots_on_target']];
                break;
            case PlayerTrait::Creator:
                $established = $creative && $player->attributes()->passing() >= 68 && $career['minutes'] >= self::ESTABLISHED_MINUTES && $career['assists'] >= 6 && $career['passes_attempted'] >= 500 && $passRate >= 0.72;
                $emerging = $creative && $player->attributes()->passing() >= 62 && $current['minutes'] >= self::EMERGING_MINUTES && $current['assists'] >= 2 && $current['passes_attempted'] >= 180 && $currentPassRate >= 0.68;
                $evidence = ['assists' => $career['assists'], 'passes_completed' => $career['passes_completed'], 'minutes' => $career['minutes']];
                break;
            case PlayerTrait::Playmaker:
                $established = $creative && $player->attributes()->passing() >= 78 && $career['minutes'] >= 1500 && $career['assists'] >= 5 && $career['passes_attempted'] >= 1000 && $passRate >= 0.78;
                $emerging = $creative && $player->attributes()->passing() >= 72 && $current['minutes'] >= 600 && $current['passes_attempted'] >= 400 && $currentPassRate >= 0.74;
                $evidence = ['passes_attempted' => $career['passes_attempted'], 'completion_rate' => round($passRate * 100, 1), 'minutes' => $career['minutes']];
                break;
            case PlayerTrait::BallWinner:
                $established = $defensive && $player->attributes()->defending() >= 68 && $career['minutes'] >= self::ESTABLISHED_MINUTES && $defensiveActions >= 40;
                $emerging = $defensive && $player->attributes()->defending() >= 64 && $current['minutes'] >= self::EMERGING_MINUTES && $currentDefensiveActions >= 12;
                $evidence = ['tackles' => $career['tackles'], 'interceptions' => $career['interceptions'], 'blocks' => $career['blocks']];
                break;
            case PlayerTrait::DefensiveAnchor:
                $established = $defensive && $player->attributes()->defending() >= 75 && $career['minutes'] >= 1500 && $career['clean_sheets'] >= 8 && $defensiveActions >= 35;
                $emerging = $defensive && $player->attributes()->defending() >= 70 && $current['minutes'] >= 600 && $current['clean_sheets'] >= 3 && $currentDefensiveActions >= 12;
                $evidence = ['clean_sheets' => $career['clean_sheets'], 'defensive_actions' => $defensiveActions, 'minutes' => $career['minutes']];
                break;
            case PlayerTrait::Workhorse:
                $established = $player->primaryPosition() !== PlayerPosition::Goalkeeper && $player->attributes()->physicality() >= 72 && $career['minutes'] >= 1800 && $career['appearances'] >= 22;
                $emerging = $player->primaryPosition() !== PlayerPosition::Goalkeeper && $player->attributes()->physicality() >= 68 && $current['minutes'] >= 700 && $current['appearances'] >= 8;
                $evidence = ['minutes' => $career['minutes'], 'appearances' => $career['appearances']];
                break;
            case PlayerTrait::ConsistentPerformer:
                $average = (float) ($career['average_match_rating'] ?? 0);
                $currentAverage = (float) ($current['average_match_rating'] ?? 0);
                $established = $player->overallRating() >= 60 && $career['minutes'] >= self::ESTABLISHED_MINUTES && $career['appearances'] >= 15 && $average >= 7.0;
                $emerging = $player->overallRating() >= 55 && $current['minutes'] >= self::EMERGING_MINUTES && $current['appearances'] >= 6 && $currentAverage >= 6.8;
                $evidence = ['appearances' => $career['appearances'], 'average_match_rating' => round($average, 2), 'minutes' => $career['minutes']];
                break;
            case PlayerTrait::Versatile:
                $established = count($secondary) >= 1 && $career['minutes'] >= 900;
                $emerging = count($secondary) >= 1 && $current['minutes'] >= 300;
                $evidence = ['established_secondary_positions' => count($secondary), 'minutes' => $career['minutes']];
                break;
            case PlayerTrait::TwoFooted:
                $established = $player->weakFoot()->value === 'strong' && $career['minutes'] >= 900;
                $emerging = in_array($player->weakFoot()->value, ['comfortable', 'strong'], true) && $current['minutes'] >= 450;
                $evidence = ['weak_foot' => $player->weakFoot()->label(), 'minutes' => $career['minutes']];
                break;
        }

        if ($established) {
            return ['state' => 'established', 'evidence' => $evidence];
        }
        if ($emerging) {
            return ['state' => 'emerging', 'evidence' => $evidence];
        }

        return null;
    }
}
