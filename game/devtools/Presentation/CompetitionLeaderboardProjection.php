<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Presentation;

/**
 * Deterministic presentation of current competition statistics.
 * It owns no statistics and persists no ranking rows.
 */
final class CompetitionLeaderboardProjection
{
    public const TOP_N = 10;

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $playerNames
     * @param array<string, string> $clubNames
     * @return array<string, mixed>
     */
    public function project(
        array $rows,
        array $playerNames,
        array $clubNames,
        string $competitionId,
        string $competitionName,
        string $seasonId,
        string $seasonLabel,
        ?string $controlledPlayerId = null,
    ): array {
        $players = $this->players($rows, $playerNames, $clubNames);
        $categories = [];
        foreach ([
            'goals' => ['label' => 'Goals', 'tie' => ['assists', 'appearances', 'minutes']],
            'assists' => ['label' => 'Assists', 'tie' => ['goals', 'appearances', 'minutes']],
        ] as $key => $definition) {
            $categories[$key] = $this->category($key, (string) $definition['label'], (array) $definition['tie'], $players, $controlledPlayerId);
        }

        return [
            'competition_id' => $competitionId,
            'competition' => $competitionName,
            'season_id' => $seasonId,
            'season' => $seasonLabel,
            'categories' => $categories,
            'supported_categories' => array_keys($categories),
            'source' => 'canonical completed Match statistics and World-fidelity competition aggregates',
            'persistence' => false,
        ];
    }

    /** @param list<array<string, mixed>> $rows @param array<string, string> $playerNames @param array<string, string> $clubNames @return array<string, array<string, mixed>> */
    private function players(array $rows, array $playerNames, array $clubNames): array
    {
        $players = [];
        foreach ($rows as $row) {
            $playerId = (string) ($row['player_id'] ?? '');
            if ($playerId === '') {
                continue;
            }
            $clubId = (string) ($row['club_id'] ?? '');
            $players[$playerId] ??= [
                'player_id' => $playerId,
                'player' => $playerNames[$playerId] ?? $playerId,
                'clubs' => [],
                'appearances' => 0,
                'minutes' => 0,
                'goals' => 0,
                'assists' => 0,
                'clean_sheets' => 0,
                'rated_appearances' => 0,
                'average_match_rating' => null,
                'position' => null,
            ];
            if ($clubId !== '') {
                $players[$playerId]['clubs'][$clubId] = $clubNames[$clubId] ?? $clubId;
            }
            foreach (['appearances', 'minutes', 'goals', 'assists', 'clean_sheets', 'rated_appearances'] as $field) {
                $players[$playerId][$field] += (int) ($row[$field] ?? 0);
            }
            if ($players[$playerId]['position'] === null && is_string($row['position'] ?? null)) {
                $players[$playerId]['position'] = $row['position'];
            }
            $players[$playerId]['rating_total'] = ($players[$playerId]['rating_total'] ?? 0.0) + (float) ($row['rating_total'] ?? 0);
        }
        foreach ($players as &$player) {
            ksort($player['clubs'], SORT_STRING);
            $rated = (int) $player['rated_appearances'];
            $player['average_match_rating'] = $rated === 0 ? null : round((float) $player['rating_total'] / $rated, 2);
            unset($player['rating_total'], $player['position']);
            $player['clubs'] = array_values($player['clubs']);
        }
        unset($player);

        return $players;
    }

    /** @param array<string, array<string, mixed>> $players @param list<string> $tie @return array<string, mixed> */
    private function category(string $key, string $label, array $tie, array $players, ?string $controlledPlayerId): array
    {
        $ranked = array_values(array_filter($players, static fn (array $player): bool => (int) $player['appearances'] > 0 && (int) $player[$key] > 0));
        usort($ranked, static function (array $left, array $right) use ($key, $tie): int {
            $comparison = (int) $right[$key] <=> (int) $left[$key];
            foreach ($tie as $field) {
                if ($comparison !== 0) {
                    break;
                }
                $comparison = (int) $right[$field] <=> (int) $left[$field];
            }

            return $comparison !== 0 ? $comparison : strcmp((string) $left['player_id'], (string) $right['player_id']);
        });
        $leaderValue = $ranked === [] ? null : (int) $ranked[0][$key];
        $entries = [];
        foreach ($ranked as $index => $player) {
            $position = 1;
            if ($index > 0) {
                $position = $index + 1;
                foreach (array_slice($ranked, 0, $index) as $previous) {
                    if ((int) $previous[$key] > (int) $player[$key]) {
                        $position = $index + 1;
                        break;
                    }
                    $position = 1;
                }
                if ((int) $player[$key] === (int) $ranked[$index - 1][$key]) {
                    $position = $this->positionForValue($ranked, $key, (int) $player[$key]);
                }
            }
            $entries[] = $this->entry($player, $position, $key, $controlledPlayerId);
        }
        $top = array_slice($entries, 0, self::TOP_N);
        $controlled = null;
        if ($controlledPlayerId !== null && isset($players[$controlledPlayerId])) {
            $player = $players[$controlledPlayerId];
            $position = null;
            foreach ($entries as $entry) {
                if ($entry['player_id'] === $controlledPlayerId) {
                    $position = $entry['position'];
                    break;
                }
            }
            $state = $this->state($position, $leaderValue, (int) $player[$key], (int) $player['appearances']);
            $controlled = [
                'player_id' => $controlledPlayerId,
                'player' => $player['player'],
                'position' => $position,
                'value' => (int) $player[$key],
                'leader_value' => $leaderValue,
                'gap_to_leader' => $leaderValue === null ? null : max(0, $leaderValue - (int) $player[$key]),
                'state' => $state,
                'clubs' => $player['clubs'],
                'appearances' => (int) $player['appearances'],
            ];
        }

        return [
            'key' => $key,
            'label' => $label,
            'supported' => true,
            'entries' => $top,
            'controlled' => $controlled,
            'leader' => $ranked === [] ? null : $this->entry($ranked[0], 1, $key, $controlledPlayerId),
            'state' => $leaderValue === null ? 'not_started' : 'active',
        ];
    }

    /** @param list<array<string, mixed>> $ranked */
    private function positionForValue(array $ranked, string $key, int $value): int
    {
        $position = 1;
        foreach ($ranked as $player) {
            if ((int) $player[$key] > $value) {
                ++$position;
            }
        }

        return $position;
    }

    /** @param array<string, mixed> $player */
    private function entry(array $player, int $position, string $key, ?string $controlledPlayerId): array
    {
        return [
            'position' => $position,
            'player_id' => $player['player_id'],
            'player' => $player['player'],
            'value' => (int) $player[$key],
            'clubs' => $player['clubs'],
            'appearances' => (int) $player['appearances'],
            'controlled' => $controlledPlayerId !== null && $player['player_id'] === $controlledPlayerId,
        ];
    }

    private function state(?int $position, ?int $leaderValue, int $value, int $appearances): string
    {
        if ($appearances === 0) {
            return 'no_appearances';
        }
        if ($leaderValue === null || $value === 0) {
            return $leaderValue === null ? 'not_started' : 'outside_top_ten';
        }
        if ($position === 1) {
            return 'leading';
        }
        if ($position <= 3) {
            return 'top_three';
        }
        if ($position <= self::TOP_N) {
            return 'top_ten';
        }

        return 'outside_top_ten';
    }
}
