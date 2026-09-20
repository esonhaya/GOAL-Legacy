<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

/**
 * Derives the controlled Player's Club context from the existing Career
 * read-model.  This is deliberately a pure service: it owns no decisions,
 * writes no rows, and never turns context into a gameplay modifier.
 */
final class CareerClubContextService
{
    /** @param array<string, mixed> $summary @return array<string, mixed> */
    public function derive(array $summary): array
    {
        $history = array_values(array_filter((array) ($summary['season_history'] ?? []), 'is_array'));
        usort($history, static fn (array $left, array $right): int => strcmp(
            (string) ($left['season_id'] ?? '') . ':' . (string) (($left['club']['id'] ?? '')),
            (string) ($right['season_id'] ?? '') . ':' . (string) (($right['club']['id'] ?? '')),
        ));

        $journey = $this->journey($history, $summary);
        $movement = array_values(array_filter((array) ($summary['movement_history'] ?? []), 'is_array'));
        $currentClub = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $currentClubId = (string) ($currentClub['id'] ?? '');
        $current = $currentClubId === '' ? null : ($journey[$currentClubId] ?? null);
        $movementClubIds = [];
        foreach ($movement as $event) {
            if (($event['type'] ?? null) !== 'transfer') { continue; }
            foreach (['from_club_id', 'to_club_id'] as $field) {
                $clubId = (string) ($event[$field] ?? '');
                if ($clubId !== '') { $movementClubIds[$clubId] = true; }
            }
            $fromId = (string) ($event['from_club_id'] ?? '');
            if ($fromId !== '' && $fromId !== $currentClubId && !isset($journey[$fromId])) {
                $journey[$fromId] = $this->movementClubRow($fromId, (string) ($event['from_club'] ?? $fromId), (string) ($event['season_id'] ?? ''), 'Former Club');
            }
        }
        $former = [];
        foreach ($journey as $clubId => $row) {
            if ($clubId !== $currentClubId) {
                $former[] = $row;
            }
        }
        usort($former, static fn (array $left, array $right): int => strcmp(
            (string) ($left['first_season_id'] ?? '') . ':' . (string) ($left['club_id'] ?? ''),
            (string) ($right['first_season_id'] ?? '') . ':' . (string) ($right['club_id'] ?? ''),
        ));

        $breakthrough = null;
        foreach ($journey as $row) {
            if ((int) ($row['appearances'] ?? 0) >= 10 || (int) ($row['minutes'] ?? 0) >= 600) {
                $breakthrough = $row;
                break;
            }
        }

        $longest = null;
        foreach ($journey as $row) {
            if ($longest === null || $this->journeyLength($row) > $this->journeyLength($longest) || ($this->journeyLength($row) === $this->journeyLength($longest) && strcmp((string) ($row['club_id'] ?? ''), (string) ($longest['club_id'] ?? '')) < 0)) {
                $longest = $row;
            }
        }

        $returns = $this->returns($history, $movement);
        $attachment = $this->attachment($summary, $current, $currentClub);
        $direction = $this->direction($summary, $attachment, $breakthrough);

        return [
            'attachment' => $attachment,
            'direction' => $direction,
            'club_journey' => array_values($journey),
            'former_clubs' => $former,
            'former_club_ids' => array_values(array_map(static fn (array $row): string => (string) ($row['club_id'] ?? ''), $former)),
            'breakthrough_club' => $breakthrough,
            'longest_club_spell' => $longest,
            'returns' => $returns,
            'one_club_career' => count(array_unique(array_merge(array_keys($journey), array_keys($movementClubIds)))) <= 1,
            'evidence_source' => 'canonical Club memberships and Career evidence',
        ];
    }

    /** @param list<array<string, mixed>> $history @return array<string, array<string, mixed>> */
    private function journey(array $history, array $summary): array
    {
        $journey = [];
        foreach ($history as $row) {
            $club = is_array($row['club'] ?? null) ? $row['club'] : [];
            $clubId = (string) ($club['id'] ?? '');
            if ($clubId === '') {
                continue;
            }
            if (!isset($journey[$clubId])) {
                $journey[$clubId] = [
                    'club_id' => $clubId,
                    'club_name' => (string) ($club['name'] ?? $clubId),
                    'seasons' => [],
                    'season_ids' => [],
                    'season_count' => 0,
                    'appearances' => 0,
                    'minutes' => 0,
                    'goals' => 0,
                    'assists' => 0,
                    'first_season' => (string) ($row['season'] ?? $row['season_id'] ?? ''),
                    'first_season_id' => (string) ($row['season_id'] ?? ''),
                    'last_season' => (string) ($row['season'] ?? $row['season_id'] ?? ''),
                    'last_season_id' => (string) ($row['season_id'] ?? ''),
                    'roles' => [],
                ];
            }
            $journey[$clubId]['seasons'][] = (string) ($row['season'] ?? $row['season_id'] ?? '');
            $journey[$clubId]['season_ids'][] = (string) ($row['season_id'] ?? '');
            $journey[$clubId]['season_count']++;
            $journey[$clubId]['appearances'] += (int) ($row['appearances'] ?? 0);
            $journey[$clubId]['minutes'] += (int) ($row['minutes'] ?? 0);
            $journey[$clubId]['goals'] += (int) ($row['goals'] ?? 0);
            $journey[$clubId]['assists'] += (int) ($row['assists'] ?? 0);
            $journey[$clubId]['last_season'] = (string) ($row['season'] ?? $row['season_id'] ?? '');
            $journey[$clubId]['last_season_id'] = (string) ($row['season_id'] ?? '');
            $role = (string) ($row['role'] ?? '');
            if ($role !== '' && !in_array($role, $journey[$clubId]['roles'], true)) {
                $journey[$clubId]['roles'][] = $role;
            }
        }
        uasort($journey, static fn (array $left, array $right): int => strcmp(
            (string) ($left['first_season_id'] ?? '') . ':' . (string) ($left['club_id'] ?? ''),
            (string) ($right['first_season_id'] ?? '') . ':' . (string) ($right['club_id'] ?? ''),
        ));

        return $journey;
    }

    /** @return array<string, mixed> */
    private function movementClubRow(string $clubId, string $clubName, string $seasonId, string $season): array
    {
        return [
            'club_id' => $clubId,
            'club_name' => $clubName,
            'seasons' => $season === '' ? [] : [$season],
            'season_ids' => $seasonId === '' ? [] : [$seasonId],
            'season_count' => $season === '' ? 0 : 1,
            'appearances' => 0,
            'minutes' => 0,
            'goals' => 0,
            'assists' => 0,
            'first_season' => $season,
            'first_season_id' => $seasonId,
            'last_season' => $season,
            'last_season_id' => $seasonId,
            'roles' => [],
        ];
    }

    /** @param array<string, mixed> $row */
    private function journeyLength(array $row): int
    {
        return (int) ($row['season_count'] ?? 0) * 1000000 + (int) ($row['minutes'] ?? 0);
    }

    /** @param list<array<string, mixed>> $history @param list<array<string, mixed>> $movement @return list<array<string, mixed>> */
    private function returns(array $history, array $movement): array
    {
        $seen = [];
        $returns = [];
        $previous = null;
        foreach ($history as $row) {
            $club = is_array($row['club'] ?? null) ? $row['club'] : [];
            $clubId = (string) ($club['id'] ?? '');
            if ($clubId === '') {
                continue;
            }
            if ($previous !== null && $previous !== $clubId && isset($seen[$clubId])) {
                $returns[] = [
                    'club_id' => $clubId,
                    'club_name' => (string) ($club['name'] ?? $clubId),
                    'season_id' => (string) ($row['season_id'] ?? ''),
                    'season' => (string) ($row['season'] ?? $row['season_id'] ?? ''),
                ];
            }
            $seen[$clubId] = true;
            $previous = $clubId;
        }
        $transferSeen = [];
        foreach ($movement as $event) {
            if (($event['type'] ?? null) !== 'transfer') { continue; }
            $fromId = (string) ($event['from_club_id'] ?? '');
            $toId = (string) ($event['to_club_id'] ?? '');
            if ($toId !== '' && isset($transferSeen[$toId])) {
                $returns[] = [
                    'club_id' => $toId,
                    'club_name' => (string) ($event['to_club'] ?? $toId),
                    'season_id' => (string) ($event['season_id'] ?? ''),
                    'season' => (string) ($event['season_id'] ?? ''),
                ];
            }
            if ($fromId !== '') { $transferSeen[$fromId] = true; }
            if ($toId !== '') { $transferSeen[$toId] = true; }
        }
        $unique = [];
        foreach ($returns as $return) {
            $key = (string) ($return['club_id'] ?? '') . '|' . (string) ($return['season_id'] ?? '');
            if ($key !== '|') { $unique[$key] = $return; }
        }

        return array_values($unique);
    }

    /** @param array<string, mixed>|null $current @param array<string, mixed>|null $currentClub @return array<string, mixed> */
    private function attachment(array $summary, ?array $current, ?array $currentClub): array
    {
        if ($currentClub === null) {
            return [
                'state' => 'no_current_club',
                'label' => 'No current Club',
                'description' => 'There is no current Club relationship to describe.',
                'club_id' => null,
                'club_name' => null,
                'seasons' => 0,
                'appearances' => 0,
                'minutes' => 0,
            ];
        }
        $seasons = (int) ($current['season_count'] ?? 0);
        $appearances = (int) ($current['appearances'] ?? 0);
        $minutes = (int) ($current['minutes'] ?? 0);
        $state = match (true) {
            $seasons >= 3 && ($appearances >= 50 || $minutes >= 4500) => 'club_figure',
            $seasons >= 2 && ($appearances >= 20 || $minutes >= 1800) => 'strong_connection',
            $seasons >= 1 && ($appearances >= 10 || $minutes >= 600) => 'established',
            $seasons === 1 => 'new_arrival',
            $seasons >= 1 => 'settling_in',
            default => 'new_arrival',
        };
        $labels = [
            'new_arrival' => ['New arrival', 'A new chapter has started here; the senior record is still limited.'],
            'settling_in' => ['Settling in', 'A Club chapter is forming, but the senior record here is still developing.'],
            'established' => ['Established', 'A meaningful senior record has formed at this Club.'],
            'strong_connection' => ['Strong connection', 'Multiple Seasons and substantial football connect this Player to the Club.'],
            'club_figure' => ['Club figure', 'A long and substantial spell makes this Club a defining part of the Career.'],
        ];

        return [
            'state' => $state,
            'label' => $labels[$state][0],
            'description' => $labels[$state][1],
            'club_id' => $currentClub['id'] ?? null,
            'club_name' => $currentClub['name'] ?? null,
            'seasons' => $seasons,
            'appearances' => $appearances,
            'minutes' => $minutes,
            'role' => $summary['current_role'] ?? $summary['squad_role'] ?? null,
        ];
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $attachment @param array<string, mixed>|null $breakthrough @return array<string, mixed> */
    private function direction(array $summary, array $attachment, ?array $breakthrough): array
    {
        $age = (int) ($summary['age'] ?? 0);
        $phase = (string) ($summary['career_phase'] ?? '');
        $role = (string) ($summary['current_role'] ?? $summary['squad_role'] ?? '');
        $manager = is_array($summary['manager_context'] ?? null) ? $summary['manager_context'] : [];
        $playingTime = (string) ($manager['playing_time_status'] ?? '');
        $recent = is_array($summary['recent_playing_time'] ?? null) ? $summary['recent_playing_time'] : [];
        $minutes = (int) ($recent['minutes'] ?? 0);
        $transferRequest = (string) (($summary['transfer_request']['status'] ?? 'none'));
        $outlook = is_array($summary['career_outlook'] ?? null) ? $summary['career_outlook'] : [];
        $contractOutlook = (string) ($outlook['contract_outlook'] ?? '');
        $competition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : [];
        $history = (array) ($summary['season_history'] ?? []);
        $reasons = [];
        $key = 'establish_yourself';

        if (($summary['career_state'] ?? 'active') === 'retired' || $phase === 'retired') {
            $key = 'legacy_years';
            $reasons[] = 'playing Career complete';
        } elseif ($playingTime === 'severely_below_expectation' || $playingTime === 'below_expectation' || ($role !== '' && in_array($role, ['prospect', 'rotation'], true) && $minutes < 450)) {
            $key = 'seek_regular_football';
            $reasons[] = 'recent playing time is below the current role expectation';
            if ($transferRequest === 'requested') {
                $reasons[] = 'transfer request is active';
            }
        } elseif ($contractOutlook === 'approaching_decision' || $contractOutlook === 'no_active_contract' || $transferRequest === 'requested') {
            $key = 'secure_future';
            $reasons[] = $transferRequest === 'requested' ? 'a transfer request is active' : 'the next Contract decision is approaching';
        } elseif ($phase === 'decline' || $age >= 31) {
            $key = 'legacy_years';
            $reasons[] = 'Career phase is moving toward its later years';
        } elseif ($breakthrough === null && $age <= 23) {
            $key = 'break_through';
            $reasons[] = 'senior breakthrough evidence is still forming';
        } elseif (in_array($role, ['prospect', 'rotation'], true) || in_array($attachment['state'] ?? '', ['new_arrival', 'settling_in'], true)) {
            $key = 'establish_yourself';
            $reasons[] = 'the current role and Club chapter are still being established';
        } elseif (in_array($role, ['regular', 'key_player'], true) && (int) ($competition['tier'] ?? 99) >= 2 && count($history) >= 2) {
            $key = 'seek_bigger_challenge';
            $reasons[] = 'a sustained senior record has formed at the current level';
        } elseif (in_array($role, ['regular', 'key_player'], true)) {
            $key = 'win_here';
            $reasons[] = 'the current role offers a platform to contribute here';
        } else {
            $reasons[] = 'current Career evidence is still developing';
        }

        $labels = [
            'break_through' => ['Break through', 'The next meaningful senior opportunity can define the early Career.'],
            'establish_yourself' => ['Establish yourself', 'The current role and Club chapter are still taking shape.'],
            'win_here' => ['Win here', 'The current role offers a chance to build something at this Club.'],
            'seek_bigger_challenge' => ['Seek a bigger challenge', 'The record so far may support a different level of competition.'],
            'seek_regular_football' => ['Seek regular football', 'Consistent minutes are the clearest current Career need.'],
            'recover_career' => ['Recover the Career', 'The next stable football opportunity matters more than a label.'],
            'secure_future' => ['Secure the future', 'A Contract or movement decision is making the next chapter relevant.'],
            'legacy_years' => ['Legacy years', 'Later Career context makes the record and next chapter especially meaningful.'],
        ];

        return [
            'key' => $key,
            'label' => $labels[$key][0],
            'description' => $labels[$key][1],
            'reasons' => $reasons,
            'actionable' => in_array($key, ['break_through', 'seek_regular_football', 'seek_bigger_challenge', 'secure_future', 'recover_career', 'legacy_years'], true),
        ];
    }
}
