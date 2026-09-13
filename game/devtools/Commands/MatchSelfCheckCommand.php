<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;
use Throwable;

final class MatchSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services) {}
    public function name(): string { return 'match:self-check'; }
    public function description(): string { return 'Generate, simulate, persist, reload, and inspect a deterministic league Match loop.'; }
    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-match-' . bin2hex(random_bytes(8)); $database = null;
        try {
            $calendar = $this->services->worldModule()->service()->calendar(); $nations = $this->services->nationModule()->service()->loadSelected(); $competitions = $this->services->competitionModule()->service()->loadSelected();
            $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
            $world = new World(new WorldId('match-self-check'), 'Match self-check', 2026006, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $this->services->contentPackages()->selectedIds());
            $store = new SqliteSaveStore($directory, new JsonSerializer()); $store->create(SaveMetadata::create('match-self-check', 'Match self-check', $world->currentTime(), new DateTimeImmutable('@0'))); $database = $store->openDatabase('match-self-check');
            $this->services->worldModule()->service()->initialize($database, $world, $season);
            $matchService = $this->services->matchModule()->service(); $matches = $matchService->generateFixtures($database, 'premier-league', $season->id()); if (count($matches) !== 380) { throw new RuntimeException('Premier League fixture generation count is not 380.'); }
            $playerService = $this->services->playerModule()->service(); $player = $playerService->create(new PlayerCreationRequest('match-demo-player', 'Match', 'Demo', 'Match Demo', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 88, 'regular', 42)); $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('match-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));
            $contractService = $this->services->contractModule()->service(); $contractService->save($database, $contractService->create(new ContractCreationRequest(new ContractId('match-demo-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
            $this->services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
            $firstDate = $matches[0]->scheduledDate(); $this->services->worldModule()->service()->advanceToDate($database, 'match-self-check', $firstDate); $completed = $matchService->simulateDue($database, $firstDate); if (count($completed) !== 10) { throw new RuntimeException('Expected one Premier League round of ten Matches.'); }
            $careerMatch = null; $summary = null; foreach ($completed as $candidate) { $summary = $matchService->playerSummary($database, $candidate->id(), $player->id()); if ($summary !== null) { $careerMatch = $candidate; break; } } if ($careerMatch === null || $summary === null) { throw new RuntimeException('Career Player did not participate in the simulated Match round.'); }
            $table = $matchService->standings($database, 'premier-league', $season->id()); if (array_sum(array_map(static fn (array $row): int => $row['played'], $table)) !== 20 || $matchService->highlightRepository($database)->byMatch($careerMatch->id()) === []) { throw new RuntimeException('Match result, standings, or highlights were not persisted.'); }
            unset($database); $database = $store->openDatabase('match-self-check'); $reloaded = $matchService->repository($database)->get($careerMatch->id()); $reloadedTable = $matchService->standings($database, 'premier-league', $season->id()); if ($reloaded->status() !== MatchStatus::Completed || $reloaded->toArray() !== $careerMatch->toArray() || $reloadedTable !== $table || $matchService->playerSummary($database, $careerMatch->id(), $player->id()) === null) { throw new RuntimeException('Match save/reload state was not deterministic.'); }
            $output->write(sprintf('Match self-check passed: fixtures=%d round_matches=%d match=%s score=%d-%d career_player=%s appeared=%s highlights=%d.', count($matches), count($completed), $reloaded->id()->value(), $reloaded->result()?->homeGoals(), $reloaded->result()?->awayGoals(), $player->id()->value(), $summary['appeared'] ? 'yes' : 'no', count($matchService->highlightRepository($database)->byMatch($reloaded->id())))); return 0;
        } catch (Throwable $exception) { $output->error('Match self-check failed: ' . $exception->getMessage()); return 1; }
        finally { unset($database); $this->removeIsolatedStorage($directory); }
    }
    private function removeIsolatedStorage(string $directory): void { if (!is_dir($directory)) { return; } foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file) && !unlink($file)) { throw new RuntimeException('Unable to clean isolated Match database.'); } } if (!rmdir($directory)) { throw new RuntimeException('Unable to clean isolated Match directory.'); } }
}
