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
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;
use Throwable;

final class CareerSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services) {}
    public function name(): string { return 'career:self-check'; }
    public function description(): string { return 'Train, play, develop, persist, reload, and inspect a deterministic career Player.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-career-' . bin2hex(random_bytes(8));
        $database = null;
        try {
            $nations = $this->services->nationModule()->service()->loadSelected();
            $competitions = $this->services->competitionModule()->service()->loadSelected();
            $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
            $calendar = $this->services->worldModule()->service()->calendar();
            $world = new World(new WorldId('career-self-check'), 'Career self-check', 2026007, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $this->services->contentPackages()->selectedIds());
            $store = new SqliteSaveStore($directory, new JsonSerializer()); $store->create(SaveMetadata::create('career-self-check', 'Career self-check', $world->currentTime(), new DateTimeImmutable('@0'))); $database = $store->openDatabase('career-self-check');
            $this->services->worldModule()->service()->initialize($database, $world, $season);
            $playerService = $this->services->playerModule()->service();
            $player = $playerService->create(new PlayerCreationRequest('career-self-check-player', 'Career', 'Demo', 'Career Demo', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 7007, new PlayerAttributeSet(50, 50, 50, 50, 50, 50)));
            $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('career-self-check'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Prospect));
            $contract = $this->services->contractModule()->service(); $contract->save($database, $contract->create(new ContractCreationRequest(new ContractId('career-self-check-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
            $this->services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
            $playerService->trainingService()->complete($database, new TrainingRequest($player->id(), 'career-training-1', 'passing', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2024-09-26')));
            $matches = $this->services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()); $careerMatches = array_values(array_filter($matches, static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal')); $matchService = $this->services->matchModule()->service(); $summary = null; $date = $careerMatches[1]->scheduledDate(); foreach (array_slice($careerMatches, 0, 2) as $careerMatch) { $this->services->worldModule()->service()->advanceToDate($database, 'career-self-check', $careerMatch->scheduledDate()); $completed = $matchService->simulateDue($database, $careerMatch->scheduledDate()); $summary = $matchService->playerSummary($database, $careerMatch->id(), $player->id()); }
            if ($summary === null) { throw new RuntimeException('Career Player did not receive a Match stat line.'); }
            $query = new PlayerCareerProgressionQuery($this->services->clubModule()->service()); $beforeReload = $query->summary($database, $player->id(), $date, $season->id()); unset($database); $database = $store->openDatabase('career-self-check'); $afterReload = $query->summary($database, $player->id(), $date, $season->id()); if ($beforeReload !== $afterReload) { throw new RuntimeException('Career progression changed after reload.'); }
            $output->write(sprintf('Career self-check passed: player=%s ovr=%d potential=%d role=%s appearances=%d minutes=%d opportunities=%d history=%d match=%s.', $player->id()->value(), $afterReload['current_ovr'], $afterReload['potential'], $afterReload['squad_role'], $afterReload['career_stats']['appearances'], $afterReload['career_stats']['minutes'], count($afterReload['open_opportunities']), count($afterReload['recent_development']), $summary['match_id'])); return 0;
        } catch (Throwable $exception) { $output->error('Career self-check failed: ' . $exception->getMessage()); return 1; }
        finally { unset($database); $this->removeIsolatedStorage($directory); }
    }

    private function removeIsolatedStorage(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($directory);
    }
}
