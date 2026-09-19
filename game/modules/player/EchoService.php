<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

/**
 * Selects the small set of canonical football facts that deserve a public
 * reaction. Echo owns narrative importance; Pulse owns presentation.
 */
final class EchoService
{
    /** @param array<string, mixed> $facts @return array<string, mixed>|null */
    public function match(array $facts): ?array
    {
        if (($facts['appeared'] ?? false) !== true) {
            return null;
        }
        if ((int) ($facts['red_cards'] ?? 0) > 0) {
            return ['kind' => 'match_red_card', 'importance' => 'major', 'response' => 'red_card'];
        }
        if ((int) ($facts['goals'] ?? 0) > 0 && ($facts['decisive'] ?? false)) {
            return ['kind' => 'match_decisive_goal', 'importance' => 'major', 'response' => 'victory'];
        }
        if ((int) ($facts['goals'] ?? 0) > 0 || (int) ($facts['assists'] ?? 0) > 0) {
            return ['kind' => (int) ($facts['goals'] ?? 0) > 0 ? 'match_goal' : 'match_assist', 'importance' => 'notable', 'response' => 'victory'];
        }
        if (($facts['player_of_match'] ?? false) === true || ($facts['decisive'] ?? false)) {
            return ['kind' => 'match_major_contribution', 'importance' => 'major', 'response' => (($facts['result'] ?? '') === 'loss' ? 'defeat' : 'victory')];
        }
        if ((float) ($facts['rating'] ?? 0) >= 8.0 || (int) ($facts['saves'] ?? 0) >= 5 || (int) ($facts['tackles'] ?? 0) + (int) ($facts['interceptions'] ?? 0) + (int) ($facts['blocks'] ?? 0) >= 6) {
            return ['kind' => 'match_strong_performance', 'importance' => 'notable', 'response' => (($facts['result'] ?? '') === 'loss' ? 'defeat' : 'victory')];
        }
        if (($facts['important_match'] ?? false) === true && ($facts['result'] ?? '') !== 'draw') {
            return ['kind' => 'match_result', 'importance' => 'notable', 'response' => (($facts['result'] ?? '') === 'loss' ? 'defeat' : 'victory')];
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function transfer(string $kind, bool $freeAgent = false): array
    {
        return match ($kind) {
            'request' => ['kind' => 'transfer_request', 'importance' => 'notable', 'response' => 'transfer'],
            'withdrawal' => ['kind' => 'transfer_withdrawal', 'importance' => 'routine', 'response' => null],
            'contract' => ['kind' => 'contract_event', 'importance' => 'notable', 'response' => 'transfer'],
            default => ['kind' => $freeAgent ? 'free_agent_signing' : 'transfer', 'importance' => 'major', 'response' => 'transfer'],
        };
    }

    /** @param array<string, mixed> $facts @return array<string, mixed> */
    public function achievement(array $facts): array
    {
        $source = strtolower((string) ($facts['source'] ?? ''));
        $headline = strtolower((string) ($facts['headline'] ?? ''));
        $kind = str_contains($source . '|' . $headline, 'award') ? 'award'
            : (str_contains($source . '|' . $headline, 'honour') || str_contains($source . '|' . $headline, 'champion') ? 'honour'
            : (str_contains($source . '|' . $headline, 'record') ? 'record' : 'milestone'));

        return ['kind' => $kind, 'importance' => (string) ($facts['importance'] ?? 'major'), 'response' => 'achievement'];
    }

    /** @return array<string, mixed> */
    public function availability(string $event): array
    {
        return match ($event) {
            'player.injured' => ['kind' => 'injury', 'importance' => 'notable', 'response' => null],
            'player.recovered' => ['kind' => 'return', 'importance' => 'notable', 'response' => null],
            default => ['kind' => 'availability', 'importance' => 'routine', 'response' => null],
        };
    }

    /** @return array<string, mixed> */
    public function careerChoice(string $category, bool $newsworthy): array
    {
        return ['kind' => 'career_choice', 'importance' => $newsworthy ? 'notable' : 'routine', 'response' => null, 'category' => $category];
    }
}
