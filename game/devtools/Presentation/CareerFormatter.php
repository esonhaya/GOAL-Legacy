<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Presentation;

/**
 * The CLI presentation boundary. It only turns canonical read-model values
 * into readable lines; it does not derive or mutate football facts.
 */
final class CareerFormatter
{
    /** @param array<string, mixed> $summary @param array<string, mixed>|null $nextMatch @param array<string, mixed>|null $clubContext */
    public function home(array $summary, string $date, ?array $nextMatch = null, ?array $clubContext = null): array
    {
        $player = is_array($summary['player'] ?? null) ? $summary['player'] : [];
        $season = is_array($summary['season_stats'] ?? null) ? $summary['season_stats'] : [];
        $form = is_array($summary['recent_form'] ?? null) ? $summary['recent_form'] : [];
        $performance = is_array($summary['season_performance'] ?? null) ? $summary['season_performance'] : [];
        $outlook = is_array($summary['career_outlook'] ?? null) ? $summary['career_outlook'] : [];
        $history = is_array($summary['season_history'] ?? null) ? $summary['season_history'] : [];
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $competition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : null;
        $contract = is_array($summary['current_contract'] ?? null) ? $summary['current_contract'] : null;
        $lines = $this->title('CAREER HOME');

        $lines[] = 'Date: ' . $date;
        $lines[] = '';
        $lines[] = 'PLAYER';
        $lines[] = 'Name: ' . $this->text($player['preferred_name'] ?? null, 'Unknown player');
        $lines[] = 'Age: ' . $this->number($summary['age'] ?? null);
        $lines[] = 'Nationality: ' . CareerLabels::nationality($player['primary_nation_id'] ?? null);
        $lines[] = 'Position: ' . CareerLabels::position($player['primary_position'] ?? null);
        $lines[] = 'Club: ' . ($club['name'] ?? 'Free agent');
        $lines[] = 'Competition: ' . ($competition === null ? 'No competition' : ($competition['name'] ?? 'No competition') . ' (Tier ' . $this->number($competition['tier'] ?? null) . ')');
        $lines[] = 'OVR: ' . $this->number($summary['current_ovr'] ?? null);
        $lines[] = 'Potential: ' . $this->number($summary['potential'] ?? null);
        $lines[] = 'Development: ' . CareerLabels::value($summary['development_profile'] ?? null);
        $lines[] = 'Squad Role: ' . CareerLabels::value($summary['current_role'] ?? null);

        $lines[] = '';
        $latestSeason = $history === [] ? null : $history[array_key_last($history)];
        $seasonLabel = is_array($latestSeason) ? ($latestSeason['season'] ?? 'Current') : 'Current';
        $lines[] = 'CURRENT SEASON' . ($seasonLabel === 'Current' ? '' : ' — ' . $seasonLabel);
        $lines[] = 'Appearances: ' . $this->number($season['appearances'] ?? 0) . ' | Starts: ' . $this->number($season['starts'] ?? 0) . ' | Minutes: ' . $this->number($season['minutes'] ?? 0);
        $lines[] = 'Goals: ' . $this->number($season['goals'] ?? 0) . ' | Assists: ' . $this->number($season['assists'] ?? 0);
        $lines[] = 'Average Rating: ' . $this->ratingOrEvidence($season['average_match_rating'] ?? null);
        $lines[] = $this->formLine($form);
        $lines[] = 'Season Performance: ' . CareerLabels::value($performance['classification'] ?? null);

        $lines[] = '';
        $lines[] = 'CAREER SITUATION';
        $lines[] = 'Contract: ' . $this->contract($contract);
        $transferRequest = is_array($summary['transfer_request'] ?? null) ? $summary['transfer_request'] : [];
        $lines[] = 'Transfer Request: ' . CareerLabels::value($transferRequest['status'] ?? 'none');
        $lines[] = 'Career Outlook: ' . CareerLabels::value($outlook['category'] ?? null);

        $lines[] = '';
        $lines[] = 'NEXT MATCH';
        if ($nextMatch === null) {
            $lines[] = 'No scheduled fixture';
        } else {
            $lines[] = 'Date: ' . $this->text($nextMatch['date'] ?? null);
            $lines[] = 'Fixture: ' . $this->text($nextMatch['home_club'] ?? null) . ' vs ' . $this->text($nextMatch['away_club'] ?? null);
            $lines[] = 'Competition: ' . $this->text($nextMatch['competition'] ?? null);
        }

        $lines[] = '';
        $lines[] = 'CLUB';
        if ($clubContext === null) {
            $lines[] = 'League position: Not available';
        } else {
            $lines[] = 'League position: ' . $this->number($clubContext['position'] ?? null);
            $lines[] = 'Played: ' . $this->number($clubContext['played'] ?? 0) . ' | Points: ' . $this->number($clubContext['points'] ?? 0);
        }

        $lines[] = '';
        $lines[] = 'ACTIONS';
        $lines[] = '1. Continue';
        $lines[] = '2. Career';
        $lines[] = '3. World';
        $actionNumber = 4;
        foreach (($summary['available_actions'] ?? []) as $action) {
            $type = is_array($action) ? ($action['type'] ?? null) : null;
            if ($type === 'request_transfer') {
                $lines[] = $actionNumber++ . '. Request transfer';
            } elseif ($type === 'withdraw_transfer_request') {
                $lines[] = $actionNumber++ . '. Withdraw transfer request';
            }
        }
        $lines[] = $actionNumber . '. Save & Exit';

        return $lines;
    }

    /** @param array<string, mixed> $summary */
    public function career(array $summary): array
    {
        $player = is_array($summary['player'] ?? null) ? $summary['player'] : [];
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $contract = is_array($summary['current_contract'] ?? null) ? $summary['current_contract'] : null;
        $season = is_array($summary['season_stats'] ?? null) ? $summary['season_stats'] : [];
        $form = is_array($summary['recent_form'] ?? null) ? $summary['recent_form'] : [];
        $outlook = is_array($summary['career_outlook'] ?? null) ? $summary['career_outlook'] : [];
        $history = is_array($summary['season_history'] ?? null) ? $summary['season_history'] : [];
        $lines = $this->title('CAREER');
        $lines[] = '';
        $lines[] = 'CURRENT PROFILE';
        $lines[] = 'Name: ' . $this->text($player['preferred_name'] ?? null, 'Unknown player') . ' | Age: ' . $this->number($summary['age'] ?? null);
        $lines[] = 'Position: ' . CareerLabels::position($player['primary_position'] ?? null) . ' | OVR: ' . $this->number($summary['current_ovr'] ?? null);
        $lines[] = 'Development: ' . CareerLabels::value($summary['development_profile'] ?? null) . ' | Potential: ' . $this->number($summary['potential'] ?? null);
        $lines[] = '';
        $lines[] = 'CURRENT CLUB / CONTRACT';
        $lines[] = 'Club: ' . ($club['name'] ?? 'Free agent') . ' | Role: ' . CareerLabels::value($summary['current_role'] ?? null);
        $lines[] = 'Contract: ' . $this->contract($contract);
        $lines[] = '';
        $latestSeason = $history === [] ? null : $history[array_key_last($history)];
        $seasonLabel = is_array($latestSeason) ? ($latestSeason['season'] ?? 'Current') : 'Current';
        $lines[] = 'CURRENT SEASON' . ($seasonLabel === 'Current' ? '' : ' — ' . $seasonLabel);
        $lines[] = $this->statsLine($season);
        $lines[] = 'Average Rating: ' . $this->ratingOrEvidence($season['average_match_rating'] ?? null);
        $lines[] = '';
        $lines[] = 'RECENT FORM';
        $lines[] = $this->formLine($form);
        $lines[] = '';
        $lines[] = 'CAREER OUTLOOK';
        $lines[] = CareerLabels::value($outlook['category'] ?? null);
        foreach (($outlook['guidance'] ?? []) as $guidance) {
            if (is_array($guidance) && trim((string) ($guidance['message'] ?? '')) !== '') {
                $lines[] = (string) $guidance['message'];
            }
        }

        $lines[] = '';
        $lines[] = 'SEASON HISTORY';
        $history = is_array($summary['season_history'] ?? null) ? $summary['season_history'] : [];
        if ($history === []) {
            $lines[] = 'No season history yet.';
        } else {
            foreach ($history as $row) {
                if (!is_array($row)) { continue; }
                $lines[] = sprintf('%s | %s | %s | Tier %s', $this->text($row['season'] ?? null), $this->text($row['club']['name'] ?? null), $this->text($row['competition']['name'] ?? null), $this->number($row['tier'] ?? null));
                $lines[] = '  Apps ' . $this->number($row['appearances'] ?? 0) . ' | Starts ' . $this->number($row['starts'] ?? 0) . ' | Min ' . $this->number($row['minutes'] ?? 0) . ' | Goals ' . $this->number($row['goals'] ?? 0) . ' | Assists ' . $this->number($row['assists'] ?? 0) . ' | Rating ' . $this->ratingOrEvidence($row['average_match_rating'] ?? null) . ' | ' . CareerLabels::value($row['performance']['classification'] ?? null);
            }
        }

        $lines[] = '';
        $lines[] = 'MOVEMENT HISTORY';
        $movement = is_array($summary['movement_history'] ?? null) ? $summary['movement_history'] : [];
        if ($movement === []) {
            $lines[] = 'No recorded movement yet.';
        } else {
            foreach ($movement as $event) {
                if (!is_array($event)) { continue; }
                $lines[] = $this->movement($event);
            }
        }

        $lines[] = '';
        $lines[] = 'DEVELOPMENT / OVR HISTORY';
        $development = is_array($summary['development_history'] ?? null) ? $summary['development_history'] : [];
        if ($development === []) {
            $lines[] = 'No recorded OVR changes yet.';
        } else {
            foreach ($development as $entry) {
                if (!is_array($entry)) { continue; }
                $lines[] = sprintf('%s: %s -> %s', $this->text($entry['occurred_date'] ?? null), $this->number($entry['before_ovr'] ?? null), $this->number($entry['after_ovr'] ?? null));
            }
        }

        $lines[] = '';
        $lines[] = 'ACTIONS';
        $lines[] = '1. Back to Career Home';
        return $lines;
    }

    /** @param array<string, mixed> $match */
    public function matchday(array $match): array
    {
        $result = $match['result'] ?? [];
        $performance = is_array($match['performance'] ?? null) ? $match['performance'] : [];
        $post = is_array($match['post_match'] ?? null) ? $match['post_match'] : [];
        $lines = $this->title('MATCHDAY');
        $lines[] = 'Competition: ' . $this->text($match['competition'] ?? null);
        $lines[] = 'Date: ' . $this->text($match['date'] ?? null);
        $lines[] = '';
        $lines[] = sprintf('%s      %s - %s      %s', $this->text($match['home_club'] ?? null), $this->number($result['home_goals'] ?? 0), $this->number($result['away_goals'] ?? 0), $this->text($match['away_club'] ?? null));
        $lines[] = 'MATCH RESULT';
        $lines[] = 'RESULT: ' . CareerLabels::value($match['perspective_result'] ?? null);
        $lines[] = '';
        $lines[] = 'YOUR MATCH';
        $lines[] = $this->participation($performance);
        if (($performance['appeared'] ?? false) === true) {
            $lines[] = 'Minutes: ' . $this->number($performance['minutes'] ?? null);
            if (($performance['rating'] ?? null) !== null) {
                $lines[] = 'Rating: ' . number_format((float) $performance['rating'], 1);
            }
            $lines[] = 'STATS: ' . $this->matchStats($performance);
        }

        $lines[] = '';
        $lines[] = 'HIGHLIGHTS';
        $highlights = is_array($match['highlights'] ?? null) ? $match['highlights'] : [];
        if ($highlights === []) {
            $lines[] = 'No highlights recorded.';
        } else {
            foreach ($highlights as $highlight) {
                $lines[] = (string) $highlight;
            }
        }

        $lines[] = '';
        $lines[] = 'POST-MATCH';
        $lines[] = $this->formLine(is_array($post['recent_form'] ?? null) ? $post['recent_form'] : []);
        $season = is_array($post['season_stats'] ?? null) ? $post['season_stats'] : [];
        $lines[] = 'Season: Apps ' . $this->number($season['appearances'] ?? 0) . ' | Starts ' . $this->number($season['starts'] ?? 0) . ' | Minutes ' . $this->number($season['minutes'] ?? 0) . ' | Goals ' . $this->number($season['goals'] ?? 0) . ' | Assists ' . $this->number($season['assists'] ?? 0) . ' | Average Rating ' . $this->ratingOrEvidence($season['average_match_rating'] ?? null);
        $postPerformance = is_array($post['season_performance'] ?? null) ? $post['season_performance'] : [];
        $lines[] = 'Season Performance: ' . CareerLabels::value($postPerformance['classification'] ?? null);
        if (isset($post['club_position'])) {
            $lines[] = 'Club league position: ' . $this->number($post['club_position']) . ' | Points: ' . $this->number($post['club_points'] ?? 0);
        }
        $lines[] = '';
        $lines[] = '1. Back to Career Home';
        return $lines;
    }

    /** @param array<string, mixed> $world */
    public function world(array $world): array
    {
        $lines = $this->title('WORLD');
        $lines[] = 'Competition: ' . $this->text($world['competition'] ?? null);
        $lines[] = '';
        $lines[] = 'STANDINGS';
        $lines[] = 'Pos  Club | P W D L GF GA GD Pts';
        $standings = is_array($world['standings'] ?? null) ? $world['standings'] : [];
        if ($standings === []) {
            $lines[] = 'No standings available.';
        } else {
            foreach ($standings as $index => $row) {
                if (!is_array($row)) { continue; }
                $mark = !empty($row['controlled']) ? '*' : ' ';
                $lines[] = sprintf('%s%2d  %s | %d %d %d %d %d %d %+d %d', $mark, $index + 1, $this->text($row['club'] ?? null), (int) ($row['played'] ?? 0), (int) ($row['wins'] ?? 0), (int) ($row['draws'] ?? 0), (int) ($row['losses'] ?? 0), (int) ($row['goals_for'] ?? 0), (int) ($row['goals_against'] ?? 0), (int) ($row['goal_difference'] ?? 0), (int) ($row['points'] ?? 0));
            }
        }
        $lines[] = '';
        $lines[] = 'RECENT CONTROLLED CLUB RESULT';
        $lines[] = $this->text($world['recent_result'] ?? null, 'No completed result yet.');
        $lines[] = '';
        $lines[] = 'NEXT CONTROLLED CLUB FIXTURE';
        $lines[] = $this->text($world['next_fixture'] ?? null, 'No scheduled fixture.');
        $lines[] = '';
        $lines[] = '1. Back to Career Home';
        return $lines;
    }

    /** @param array<string, mixed> $form */
    private function formLine(array $form): string
    {
        $classification = (string) ($form['classification'] ?? 'insufficient_evidence');
        if (($form['rated_appearances'] ?? 0) < 2 || $classification === 'insufficient_evidence') {
            return 'Recent Form: Not enough matches yet';
        }

        return 'Recent Form: ' . CareerLabels::value($classification) . ' | Recent Rating: ' . $this->ratingOrEvidence($form['average_match_rating'] ?? null) . ' | Rated Matches: ' . $this->number($form['rated_appearances'] ?? 0);
    }

    /** @param array<string, mixed> $stats */
    private function statsLine(array $stats): string
    {
        return 'Apps ' . $this->number($stats['appearances'] ?? 0) . ' | Starts ' . $this->number($stats['starts'] ?? 0) . ' | Minutes ' . $this->number($stats['minutes'] ?? 0) . ' | Goals ' . $this->number($stats['goals'] ?? 0) . ' | Assists ' . $this->number($stats['assists'] ?? 0);
    }

    /** @param array<string, mixed> $performance */
    private function participation(array $performance): string
    {
        $status = (string) ($performance['selection_status'] ?? 'not_selected');
        if (($performance['appeared'] ?? false) !== true) {
            return match ($status) {
                'bench' => 'Unused substitute',
                'unavailable' => 'Not selected (unavailable)',
                default => 'Not selected',
            };
        }
        if ($status === 'starter' || ($performance['started'] ?? false) === true) {
            return 'Started';
        }
        $minute = $performance['substitution_minute'] ?? null;
        return $minute === null ? 'Came on' : "Came on in {$minute}'";
    }

    /** @param array<string, mixed> $performance */
    private function matchStats(array $performance): string
    {
        $position = (string) ($performance['position'] ?? '');
        $fields = match ($position) {
            'GK' => ['goals' => 'Goals', 'assists' => 'Assists', 'saves' => 'Saves', 'clean_sheets' => 'Clean sheet', 'passes' => 'Passing', 'cards' => 'Cards'],
            'CB', 'LB', 'RB' => ['goals' => 'Goals', 'assists' => 'Assists', 'tackles' => 'Tackles', 'interceptions' => 'Interceptions', 'blocks' => 'Blocks', 'passes' => 'Passing', 'clean_sheets' => 'Clean sheet', 'cards' => 'Cards'],
            'DM', 'CM', 'AM' => ['goals' => 'Goals', 'assists' => 'Assists', 'passes' => 'Passing', 'tackles' => 'Tackles', 'interceptions' => 'Interceptions', 'cards' => 'Cards'],
            default => ['goals' => 'Goals', 'assists' => 'Assists', 'shots' => 'Shots', 'shots_on_target' => 'Shots on target', 'passes' => 'Passing', 'cards' => 'Cards'],
        };
        $parts = [];
        foreach ($fields as $key => $label) {
            if ($key === 'passes') {
                if ((int) ($performance['passes_attempted'] ?? 0) > 0) {
                    $parts[] = $label . ' ' . (int) ($performance['passes_completed'] ?? 0) . '/' . (int) ($performance['passes_attempted'] ?? 0);
                }
                continue;
            }
            if ($key === 'cards') {
                $yellow = (int) ($performance['yellow_cards'] ?? 0);
                $red = (int) ($performance['red_cards'] ?? 0);
                if ($yellow > 0 || $red > 0) { $parts[] = $label . ' ' . $yellow . 'Y/' . $red . 'R'; }
                continue;
            }
            $value = (int) ($performance[$key] ?? 0);
            if ($value > 0 || in_array($key, ['goals', 'assists'], true)) { $parts[] = $label . ' ' . $value; }
        }

        return $parts === [] ? 'No notable stats recorded' : implode(' | ', $parts);
    }

    /** @param array<string, mixed> $contract */
    private function contract(?array $contract): string
    {
        if ($contract === null) { return 'No active contract'; }
        $status = CareerLabels::value($contract['status'] ?? null);
        $end = trim((string) ($contract['end_date'] ?? ''));
        return $end === '' ? $status : $status . ' through ' . $end;
    }

    /** @param array<string, mixed> $event */
    private function movement(array $event): string
    {
        $type = (string) ($event['type'] ?? 'movement');
        if ($type === 'transfer') {
            return $this->text($event['date'] ?? null) . ': Transferred from ' . $this->text($event['from_club'] ?? null) . ' to ' . $this->text($event['to_club'] ?? null);
        }
        return $this->text($event['date'] ?? null) . ': ' . CareerLabels::value($type) . ' — ' . $this->text($event['club']['name'] ?? null);
    }

    private function ratingOrEvidence(mixed $value): string
    {
        return $value === null ? 'Not enough matches yet' : number_format((float) $value, 1);
    }

    private function number(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : 'Not available';
    }

    private function text(mixed $value, string $fallback = 'Not available'): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : $fallback;
    }

    /** @return list<string> */
    private function title(string $title): array
    {
        return ['==================================================', 'GOAL: LEGACY', $title, '=================================================='];
    }
}
