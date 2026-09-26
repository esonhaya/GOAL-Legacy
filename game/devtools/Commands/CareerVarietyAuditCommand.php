<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Devtools\Simulation\GoalMatchRunner;
use Goal\Legacy\Devtools\Simulation\GoalScenarioBuilder;
use Goal\Legacy\Modules\Player\CareerEventCatalog;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;

/** Development-only deterministic inspection of Career event variety. */
final class CareerVarietyAuditCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services) {}

    public function name(): string { return 'career:variety'; }

    public function description(): string { return 'Inspect eligible Career event families for a canonical scenario.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $scenario = strtoupper(trim((string) ($arguments[0] ?? '')));
        $seed = filter_var($arguments[1] ?? 13013, FILTER_VALIDATE_INT);
        if ($scenario === '' || $seed === false) {
            $output->error('Usage: career:variety <scenario-id> [seed]');
            return 1;
        }
        $errors = CareerEventCatalog::validate();
        if ($errors !== []) {
            $output->error('Career event catalog is invalid: ' . implode('; ', $errors));
            return 1;
        }

        $fixture = (new GoalScenarioBuilder($this->services))->build($scenario, (int) $seed);
        try {
            $worldService = $this->services->worldModule()->service();
            $summaryQuery = new PlayerCareerProgressionQuery($this->services->clubModule()->service());
            $world = $worldService->load($fixture->database(), $fixture->saveId());
            $date = $world->currentDate($worldService->calendar());
            $summary = $summaryQuery->summary($fixture->database(), $fixture->playerId(), $date, $fixture->seasonId());
            if (($summary['current_club'] ?? null) !== null) {
                (new GoalMatchRunner($this->services))->one($fixture->database(), $fixture->saveId(), $fixture->playerId()->value());
                $world = $worldService->load($fixture->database(), $fixture->saveId());
                $date = $world->currentDate($worldService->calendar());
                $summary = $summaryQuery->summary($fixture->database(), $fixture->playerId(), $date, $fixture->seasonId());
            }
            $experience = $this->services->playerModule()->service()->careerExperienceService();
            $first = $experience->auditEligibility($fixture->database(), $fixture->playerId(), $fixture->seasonId(), $date, $summary);
            $second = $experience->auditEligibility($fixture->database(), $fixture->playerId(), $fixture->seasonId(), $date, $summary);
            $first['deterministic_repeat'] = $this->stableProjection($first) === $this->stableProjection($second);
            $first['scenario'] = $scenario;
            $first['seed'] = (int) $seed;
            if (isset($first['signals']) && is_array($first['signals'])) {
                $first['signals'] = $this->compact($first['signals']);
            }
            $output->write((string) json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        } finally {
            $fixture->close();
        }
    }

    /** @param array<string,mixed> $value */
    private function stableProjection(array $value): string
    {
        unset($value['signals']['owned_item'], $value['signals']['owned_effects'], $value['signals']['owned_categories']);
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function compact(array $value): array
    {
        return array_intersect_key($value, array_flip(['career_phase', 'active_injury', 'role', 'form', 'appearances', 'starts', 'goals', 'playing_time_mismatch', 'transfer_request', 'recent_transfer', 'contract_expiring', 'season_phase', 'next_competition_type', 'recent_competition_type', 'public_profile', 'international_profile', 'club_standing', 'supporter_sentiment', 'manager_relationship', 'financial_context', 'context_keys']));
    }
}
