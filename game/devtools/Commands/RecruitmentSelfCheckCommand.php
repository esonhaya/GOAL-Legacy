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
use Goal\Legacy\Modules\Contract\Domain\ContractStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\MatchSelectionService;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;
use Throwable;

final class RecruitmentSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services) {}

    public function name(): string { return 'recruitment:self-check'; }

    public function description(): string { return 'Verify bounded free-agent recruitment and canonical squad integration.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-recruitment-' . bin2hex(random_bytes(8));
        $database = null;
        try {
            $nations = $this->services->nationModule()->service()->loadSelected();
            $competitions = $this->services->competitionModule()->service()->loadSelected();
            $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
            $calendar = $this->services->worldModule()->service()->calendar();
            $world = new World(new WorldId('recruitment-self-check'), 'Recruitment self-check', 15015, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $this->services->contentPackages()->selectedIds());
            $store = new SqliteSaveStore($directory, new JsonSerializer());
            $store->create(SaveMetadata::create('recruitment-self-check', $world->label(), $world->currentTime(), new DateTimeImmutable('@0')));
            $database = $store->openDatabase('recruitment-self-check');
            $this->services->worldModule()->service()->initialize($database, $world, $season);
            $population = $this->services->playerModule()->service()->populationService()->populate($database, $season, $world->universeSeed());
            $squads = $this->services->clubModule()->service()->squadRepository($database);
            $players = new PlayerRepository($database);
            $contracts = $this->services->contractModule()->service()->repository($database);
            $registrations = $this->services->competitionModule()->service()->registrationRepository($database);
            $released = null;
            foreach ($squads->byClub('arsenal', $season->id()) as $membership) {
                $player = $players->get($membership->playerId());
                if ($player->primaryPosition() === PlayerPosition::CentralMidfielder) {
                    $released = [$membership, $player];
                    break;
                }
            }
            if ($released === null) {
                throw new RuntimeException('Could not create the controlled free-agent vacancy.');
            }
            [$membership, $freePlayer] = $released;
            $contract = $contracts->activeForPlayer($freePlayer->id());
            if ($contract === null || $contract->status() !== ContractStatus::Active) {
                throw new RuntimeException('Controlled vacancy did not have an active Contract.');
            }
            $contracts->save($contract->terminate());
            foreach ($registrations->byPlayer($freePlayer->id()) as $registration) {
                if ($registration->clubId()->value() === 'arsenal' && $registration->seasonId()->value() === $season->id()->value()) {
                    $registrations->unregister($registration);
                }
            }
            $squads->remove(new ClubSquadMembership(new ClubId('arsenal'), $freePlayer->id(), $season->id(), $membership->role()));
            $report = $this->services->clubRecruitmentService()->recruit($database, $season, $season->startDate());
            $rerun = $this->services->clubRecruitmentService()->recruit($database, $season, $season->startDate());
            $newContract = $contracts->activeForPlayer($freePlayer->id());
            $newMemberships = $squads->byPlayer($freePlayer->id(), $season->id());
            $newRegistrations = array_values(array_filter($registrations->byPlayer($freePlayer->id()), static fn (PlayerRegistration $registration): bool => $registration->seasonId()->value() === $season->id()->value() && $registration->clubId()->value() === 'arsenal'));
            if (($report['free_agents_signed'] ?? 0) !== 1 || $newContract === null || $newMemberships === [] || $newMemberships[0]->clubId()->value() !== 'arsenal' || $newRegistrations === [] || ($rerun['free_agents_signed'] ?? 0) !== 0) {
                throw new RuntimeException('Free-agent recruitment or idempotency failed.');
            }
            $this->services->worldModule()->service()->advanceToDate($database, 'recruitment-self-check', SimulationDate::fromIsoString('2024-08-01'));
            $matches = $this->services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id());
            $arsenalMatch = (new MatchRepository($database))->byClub('arsenal', $season->id())[0] ?? null;
            if ($arsenalMatch === null) {
                throw new RuntimeException('No Arsenal fixture was generated for Match-path verification.');
            }
            $match = $arsenalMatch;
            $selected = (new MatchSelectionService($this->services->clubModule()->service()))->select($database, $match);
            $eligible = in_array($freePlayer->id()->value(), array_map(static fn ($selection): string => $selection->playerId()->value(), $selected), true);
            if (!$eligible) {
                throw new RuntimeException('Recruited Player did not reach the production Match selection path.');
            }
            $output->write(sprintf('Recruitment self-check passed: free_player=%s club=arsenal free_signings=%d rerun_signings=%d contract=%s registration=%d match_eligible=yes population=%d.', $freePlayer->id()->value(), $report['free_agents_signed'], $rerun['free_agents_signed'], $newContract->id()->value(), count($newRegistrations), $population['players_total']));
            return 0;
        } catch (Throwable $exception) {
            $output->error('Recruitment self-check failed: ' . $exception->getMessage());
            return 1;
        } finally {
            unset($database);
            if (is_dir($directory)) {
                foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
                rmdir($directory);
            }
        }
    }
}
