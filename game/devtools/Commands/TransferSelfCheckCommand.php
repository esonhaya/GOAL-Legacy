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
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;
use Throwable;

final class TransferSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services) {}
    public function name(): string { return 'transfer:self-check'; }
    public function description(): string { return 'Execute and reload a real Contract, registration, squad, and Transfer transition in isolated storage.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-transfer-' . bin2hex(random_bytes(8));
        $database = null;
        try {
            $calendar = $this->services->worldModule()->service()->calendar();
            $nations = $this->services->nationModule()->service()->loadSelected();
            $competitions = $this->services->competitionModule()->service()->loadSelected();
            $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
            $world = new World(new WorldId('transfer-self-check'), 'Transfer self-check', 2026005, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $this->services->contentPackages()->selectedIds());
            $store = new SqliteSaveStore($directory, new JsonSerializer());
            $store->create(SaveMetadata::create('transfer-self-check', 'Transfer self-check', $world->currentTime(), new DateTimeImmutable('@0')));
            $database = $store->openDatabase('transfer-self-check');
            $worldService = $this->services->worldModule()->service();
            $worldService->initialize($database, $world, $season);
            $player = $this->services->playerModule()->service()->create(new PlayerCreationRequest('transfer-demo-player', 'Transfer', 'Demo', 'Transfer Demo', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 88, 'regular', 42));
            $career = new CareerPlayerReference(new CareerId('transfer-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31'));
            $this->services->playerModule()->service()->initializeCareer($database, $player, $career, new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));
            $contractService = $this->services->contractModule()->service();
            $contractService->save($database, $contractService->create(new ContractCreationRequest(new ContractId('transfer-source-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100000, SimulationDate::fromIsoString('2024-07-31'))));
            $registrationRepository = $this->services->competitionModule()->service()->registrationRepository($database);
            $registrationRepository->register(new PlayerRegistration($season->id(), new \Goal\Legacy\Modules\Competition\Domain\CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
            $transfer = new Transfer(new TransferId('transfer-demo'), $player->id(), new ClubId('arsenal'), new ClubId('chelsea'), $season->id(), 2500000, SimulationDate::fromIsoString('2024-08-02'));
            $completed = $this->services->transferModule()->service()->execute($database, $transfer, new TransferExecutionTerms(new ContractId('transfer-destination-contract'), SimulationDate::fromIsoString('2025-06-30'), 120000));
            $reloaded = $this->services->transferModule()->service()->repository($database)->get($completed->id());
            $sourceContract = $contractService->repository($database)->get('transfer-source-contract');
            $destinationContract = $contractService->repository($database)->get('transfer-destination-contract');
            $destinationSquad = $this->services->clubModule()->service()->squadRepository($database)->byClub('chelsea', $season->id());
            $destinationRegistrations = $registrationRepository->byPlayer($player->id());
            $careerReloaded = $this->services->playerModule()->service()->careerRepository($database)->get('transfer-career');
            $destinationRegistration = array_values(array_filter($destinationRegistrations, static fn ($registration): bool => $registration->clubId()->value() === 'chelsea' && $registration->seasonId()->value() === $season->id()->value()))[0] ?? null;
            $sourceRegistration = array_values(array_filter($destinationRegistrations, static fn ($registration): bool => $registration->clubId()->value() === 'arsenal' && $registration->seasonId()->value() === $season->id()->value()));
            if ($reloaded->status()->value !== 'completed' || $sourceContract->status()->value !== 'terminated' || $destinationContract->clubId()->value() !== 'chelsea' || count($destinationSquad) !== 1 || $destinationRegistration === null || $sourceRegistration !== [] || $careerReloaded->playerId()->value() !== $player->id()->value()) { throw new RuntimeException('Transfer self-check relationships are not coherent after reload.'); }
            $output->write(sprintf('Transfer self-check passed: player=%s source=arsenal destination=chelsea fee=%d old_contract=%s new_contract=%s registration=%s status=%s.', $player->id()->value(), $completed->fee(), $sourceContract->status()->value, $destinationContract->id()->value(), $destinationRegistration->competitionId()->value(), $reloaded->status()->value));
            return 0;
        } catch (Throwable $exception) {
            $output->error('Transfer self-check failed: ' . $exception->getMessage());
            return 1;
        } finally {
            unset($database);
            $this->removeIsolatedStorage($directory);
        }
    }

    private function removeIsolatedStorage(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file) && !unlink($file)) { throw new RuntimeException('Unable to clean isolated Transfer database.'); } }
        if (!rmdir($directory)) { throw new RuntimeException('Unable to clean isolated Transfer directory.'); }
    }
}
