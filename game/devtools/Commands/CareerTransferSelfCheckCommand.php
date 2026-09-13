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
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;
use Throwable;

final class CareerTransferSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services) {}
    public function name(): string { return 'career:transfer-self-check'; }
    public function description(): string { return 'Generate, decide, execute, and reload a deterministic career transfer offer.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-career-transfer-' . bin2hex(random_bytes(8));
        $database = null;
        try {
            $nations = $this->services->nationModule()->service()->loadSelected();
            $competitions = $this->services->competitionModule()->service()->loadSelected();
            $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
            $calendar = $this->services->worldModule()->service()->calendar();
            $world = new World(new WorldId('career-transfer-self-check'), 'Career transfer self-check', 12012, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $this->services->contentPackages()->selectedIds());
            $store = new SqliteSaveStore($directory, new JsonSerializer());
            $store->create(SaveMetadata::create('career-transfer-self-check', 'Career transfer self-check', $world->currentTime(), new DateTimeImmutable('@0')));
            $database = $store->openDatabase('career-transfer-self-check');
            $this->services->worldModule()->service()->initialize($database, $world, $season);
            $this->services->playerModule()->service()->populationService()->populate($database, $season, 12012);
            $player = $this->services->playerModule()->service()->create(new PlayerCreationRequest('career-transfer-self-check-player', 'Career', 'Transfer', 'Career Transfer', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 92, 'regular', 12012, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
            $this->services->playerModule()->service()->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('career-transfer-self-check'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Prospect));
            $contracts = $this->services->contractModule()->service();
            $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('career-transfer-self-check-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
            $this->services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
            $date = SimulationDate::fromIsoString('2024-10-01');
            $movement = $this->services->transferModule()->service()->careerMovement();
            $offers = $movement->evaluate($database, $player->id(), $season->id(), $date);
            if ($offers === []) { throw new RuntimeException('No justified transfer offer was generated.'); }
            $offer = $offers[0];
            $resolved = $movement->accept($database, $offer->id(), $date);
            if ($resolved->status()->value !== 'resolved' || $offer->targetClubId() === null) { throw new RuntimeException('Transfer offer did not resolve.'); }
            $target = $offer->targetClubId()->value();
            $match = array_values(array_filter($this->services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()), static fn ($candidate): bool => $candidate->scheduledDate()->isAfter($date) && ($candidate->homeClubId()->value() === $target || $candidate->awayClubId()->value() === $target)))[0] ?? null;
            if ($match === null) { throw new RuntimeException('No destination Match was found after transfer.'); }
            $this->services->matchModule()->service()->simulate($database, $match->id());
            $summary = $this->services->matchModule()->service()->playerSummary($database, $match->id(), $player->id());
            unset($database);
            $database = $store->openDatabase('career-transfer-self-check');
            $reloaded = $movement->inspect($database, $offer->id());
            if ($reloaded->status()->value !== 'resolved') { throw new RuntimeException('Resolved transfer offer changed after reload.'); }
            $output->write(sprintf('Career transfer self-check passed: offers=%d source=arsenal destination=%s role=%s post_transfer_match=%s participation=%s reasons=%s.', count($offers), $target, (string) ($resolved->context()['proposed_role'] ?? 'unknown'), $match->id()->value(), $summary === null ? 'unused' : ($summary['started'] ? 'started' : 'substitute'), implode(',', (array) ($offer->context()['reasons'] ?? []))));
            return 0;
        } catch (Throwable $exception) {
            $output->error('Career transfer self-check failed: ' . $exception->getMessage());
            return 1;
        } finally {
            unset($database);
            $this->removeIsolatedStorage($directory);
        }
    }

    private function removeIsolatedStorage(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($directory);
    }
}
