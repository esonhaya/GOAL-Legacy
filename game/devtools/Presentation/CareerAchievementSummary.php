<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Presentation;

/**
 * Read-only achievement projection for the controlled Player.
 *
 * CareerLegacyService, PlayerCareerStatisticsService, the international
 * services and CareerClubContextService remain the owners of their facts.
 * This class only composes the values already present in the presentation
 * summary; it never writes, awards, recalculates or persists anything.
 */
final class CareerAchievementSummary
{
    /** @param array<string, mixed> $summary @return array<string, mixed> */
    public function project(array $summary): array
    {
        $legacy = is_array($summary['legacy'] ?? null) ? $summary['legacy'] : [];
        $seasonLabels = $this->seasonLabels($summary);
        $clubNames = $this->clubNames($summary, $legacy);

        $clubHonours = [];
        $internationalHonours = [];
        foreach ((array) ($legacy['honours'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $honour = $this->honour($row, $seasonLabels, $clubNames);
            if (($honour['category'] ?? 'club') === 'international') {
                $internationalHonours[] = $honour;
            } else {
                $clubHonours[] = $honour;
            }
        }

        $awards = [];
        foreach ((array) ($legacy['awards'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $awards[] = $this->award($row, $seasonLabels, $clubNames);
        }

        $personalBests = [];
        foreach ((array) ($legacy['records'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $personalBests[] = $this->personalBest($row, $seasonLabels, $clubNames);
        }
        usort($personalBests, static fn (array $left, array $right): int => strcmp(
            (string) ($left['season_id'] ?? '') . ':' . (string) ($left['metric'] ?? ''),
            (string) ($right['season_id'] ?? '') . ':' . (string) ($right['metric'] ?? ''),
        ));

        $milestones = [];
        foreach ((array) ($legacy['milestones'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['season'] = $this->seasonLabel((string) ($row['season_id'] ?? ''), $seasonLabels);
            $row['category'] = 'milestone';
            $milestones[] = $row;
        }

        $careerRecords = $this->careerRecords($legacy, $summary);

        return [
            'counts' => [
                'club_honours' => count($clubHonours),
                'international_honours' => count($internationalHonours),
                'individual_awards' => count($awards),
                'personal_bests' => count($personalBests),
                'milestones' => count($milestones),
            ],
            'club_honours' => $clubHonours,
            'international_honours' => $internationalHonours,
            'individual_awards' => $awards,
            'career_records' => $careerRecords,
            'personal_bests' => $personalBests,
            'best_seasons' => $this->bestSeasons($summary),
            'milestones' => $milestones,
            'club_journey' => (array) (($summary['career_context'] ?? [])['club_journey'] ?? ($legacy['club_journey'] ?? [])),
            'international_stats' => (array) ($legacy['international_stats'] ?? (($summary['international'] ?? [])['stats'] ?? [])),
            'career_state' => (string) ($summary['career_state'] ?? 'active'),
            'retirement' => $legacy['retirement'] ?? ($summary['retirement'] ?? null),
            'captaincy' => (array) ($summary['captaincy'] ?? []),
            'source' => 'canonical career honours, awards, records, milestones and statistics',
        ];
    }

    /** @param array<string, mixed> $summary @return array<string, string> */
    private function seasonLabels(array $summary): array
    {
        $labels = [];
        foreach ((array) ($summary['season_history'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['season_id'] ?? '');
            if ($id !== '') {
                $labels[$id] = (string) ($row['season'] ?? $id);
            }
        }
        $span = is_array(($summary['legacy'] ?? [])['career_span'] ?? null) ? $summary['legacy']['career_span'] : [];
        if (count($labels) === 1 && isset($span['start'], $span['latest'])) {
            $only = array_key_first($labels);
            if ($only !== null) {
                $labels[$only] = (string) $span['latest'];
            }
        }

        return $labels;
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $legacy @return array<string, string> */
    private function clubNames(array $summary, array $legacy): array
    {
        $names = [];
        foreach (array_merge((array) ($legacy['clubs'] ?? []), (array) (($summary['career_context'] ?? [])['club_journey'] ?? []), (array) ($summary['season_history'] ?? [])) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $club = is_array($row['club'] ?? null) ? $row['club'] : $row;
            $id = (string) ($club['id'] ?? $club['club_id'] ?? '');
            $name = (string) ($club['name'] ?? $club['club_name'] ?? '');
            if ($id !== '' && $name !== '') {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $row @param array<string, string> $seasonLabels @param array<string, string> $clubNames @return array<string, mixed> */
    private function honour(array $row, array $seasonLabels, array $clubNames): array
    {
        $type = (string) ($row['honour_type'] ?? '');
        $international = $type === 'world_championship_winner';
        $holderId = (string) ($row['holder_id'] ?? '');
        $label = (string) ($row['label'] ?? CareerLabels::value($type, 'Honour'));

        return [
            'source_key' => (string) ($row['source_key'] ?? ''),
            'category' => $international ? 'international' : 'club',
            'honour_type' => $type,
            'label' => $label,
            'season_id' => (string) ($row['season_id'] ?? ''),
            'season' => $this->seasonLabel((string) ($row['season_id'] ?? ''), $seasonLabels),
            'competition_id' => (string) ($row['competition_id'] ?? ''),
            'competition' => $this->competitionLabel($label, $type),
            'club_id' => $international ? null : $holderId,
            'club' => $international ? null : ($clubNames[$holderId] ?? 'Recorded Club'),
            'team_id' => $international ? $holderId : null,
            'evidence' => is_array($row['evidence'] ?? null) ? $row['evidence'] : [],
        ];
    }

    /** @param array<string, mixed> $row @param array<string, string> $seasonLabels @param array<string, string> $clubNames @return array<string, mixed> */
    private function award(array $row, array $seasonLabels, array $clubNames): array
    {
        $evidence = is_array($row['evidence'] ?? null) ? $row['evidence'] : [];
        $type = (string) ($row['award_type'] ?? '');

        return [
            'source_key' => (string) ($row['source_key'] ?? ''),
            'award_type' => $type,
            'label' => CareerLabels::value($type, 'Individual award'),
            'season_id' => (string) ($row['season_id'] ?? ''),
            'season' => $this->seasonLabel((string) ($row['season_id'] ?? ''), $seasonLabels),
            'competition_id' => (string) ($row['competition_id'] ?? ''),
            'competition' => (string) ($evidence['competition_name'] ?? 'League'),
            'club_id' => (string) ($row['club_id'] ?? ''),
            'club' => $clubNames[(string) ($row['club_id'] ?? '')] ?? 'Recorded Club',
            'evidence' => $evidence,
        ];
    }

    /** @param array<string, mixed> $row @param array<string, string> $seasonLabels @param array<string, string> $clubNames @return array<string, mixed> */
    private function personalBest(array $row, array $seasonLabels, array $clubNames): array
    {
        $metric = (string) ($row['metric'] ?? '');
        $clubId = (string) ($row['club_id'] ?? '');

        return [
            'metric' => $metric,
            'label' => CareerLabels::value($metric, 'Personal best'),
            'value' => $row['value'] ?? null,
            'season_id' => (string) ($row['season_id'] ?? ''),
            'season' => $this->seasonLabel((string) ($row['season_id'] ?? ''), $seasonLabels),
            'club_id' => $clubId === '' ? null : $clubId,
            'club' => $clubId === '' ? null : ($clubNames[$clubId] ?? 'Recorded Club'),
            'evidence' => is_array($row['evidence'] ?? null) ? $row['evidence'] : [],
        ];
    }

    /** @param array<string, mixed> $legacy @param array<string, mixed> $summary @return list<array<string, mixed>> */
    private function careerRecords(array $legacy, array $summary): array
    {
        $club = (array) ($legacy['club_stats'] ?? ($summary['career_stats'] ?? []));
        $international = (array) ($legacy['international_stats'] ?? (($summary['international'] ?? [])['stats'] ?? []));
        $fields = [
            ['metric' => 'club_appearances', 'label' => 'Career Club appearances', 'value' => (int) ($club['appearances'] ?? 0)],
            ['metric' => 'club_goals', 'label' => 'Career Club goals', 'value' => (int) ($club['goals'] ?? 0)],
            ['metric' => 'club_assists', 'label' => 'Career Club assists', 'value' => (int) ($club['assists'] ?? 0)],
            ['metric' => 'international_caps', 'label' => 'International caps', 'value' => (int) ($international['caps'] ?? 0)],
            ['metric' => 'international_goals', 'label' => 'International goals', 'value' => (int) ($international['goals'] ?? 0)],
        ];

        return array_map(static fn (array $row): array => $row + ['kind' => 'career_total'], $fields);
    }

    /** @param array<string, mixed> $summary @return list<array<string, mixed>> */
    private function bestSeasons(array $summary): array
    {
        $rows = array_values(array_filter((array) ($summary['season_history'] ?? []), 'is_array'));
        $categories = [
            ['key' => 'goals', 'label' => 'Highest goals'],
            ['key' => 'assists', 'label' => 'Highest assists'],
            ['key' => 'average_match_rating', 'label' => 'Highest average rating'],
            ['key' => 'appearances', 'label' => 'Most appearances'],
        ];
        $result = [];
        foreach ($categories as $category) {
            $eligible = array_values(array_filter($rows, static function (array $row) use ($category): bool {
                $value = $row[$category['key']] ?? null;

                return ($category['key'] === 'average_match_rating' ? $value !== null && (int) ($row['rated_appearances'] ?? 0) > 0 : (int) $value > 0);
            }));
            if ($eligible === []) {
                continue;
            }
            usort($eligible, static fn (array $left, array $right): int => ((float) ($right[$category['key']] ?? 0) <=> (float) ($left[$category['key']] ?? 0)) ?: strcmp(
                (string) ($left['season_id'] ?? '') . ':' . (string) (($left['club']['id'] ?? '')),
                (string) ($right['season_id'] ?? '') . ':' . (string) (($right['club']['id'] ?? '')),
            ));
            $best = $eligible[0];
            $result[] = [
                'category' => $category['key'],
                'label' => $category['label'],
                'value' => $best[$category['key']],
                'season_id' => (string) ($best['season_id'] ?? ''),
                'season' => (string) ($best['season'] ?? $best['season_id'] ?? 'Recorded Season'),
                'club_id' => (string) (($best['club']['id'] ?? '')),
                'club' => (string) (($best['club']['name'] ?? 'Recorded Club')),
            ];
        }

        return $result;
    }

    /** @param array<string, string> $seasonLabels */
    private function seasonLabel(string $seasonId, array $seasonLabels): string
    {
        return $seasonId === '' ? 'Recorded Season' : ($seasonLabels[$seasonId] ?? $seasonId);
    }

    private function competitionLabel(string $label, string $type): string
    {
        if (str_contains($label, ' — ')) {
            return (string) substr($label, strrpos($label, ' — ') + strlen(' — '));
        }

        return match ($type) {
            'world_championship_winner' => 'World Championship',
            'league_champion' => 'League',
            'domestic_cup_winner' => 'Domestic Cup',
            'europe_tier_1_winner', 'europe_tier_2_winner' => 'European competition',
            default => $label,
        };
    }
}
