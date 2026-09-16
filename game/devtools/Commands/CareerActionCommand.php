<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use RuntimeException;

/** Player-facing career actions backed by the existing transfer boundary. */
final class CareerActionCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly ?SaveStore $saveStore = null)
    {
    }

    public function name(): string { return 'career:action'; }

    public function description(): string { return 'Use a career action: request-transfer, withdraw-transfer, or decide <option-number>.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $action = trim((string) ($arguments[0] ?? ''));
        $saveId = trim((string) ($arguments[1] ?? ''));
        if ($action === '' || $saveId === '') { $output->error('Usage: career:action <request-transfer|withdraw-transfer|decide> <save-id> [option-number]'); return 1; }
        $database = $this->services->saveStore()->openDatabase($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $date = $world->currentDate($worldService->calendar());
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $movement = $this->services->transferModule()->service()->careerMovement();
        $season = $worldService->seasonRepository($database)->get($world->currentSeasonId());
        if ($action === 'request-transfer') {
            $movement->requestTransfer($database, $career->playerId(), $season, $date);
            $output->write('CAREER ACTION — transfer request submitted.');
            $this->home($saveId, $output);
            return 0;
        }
        if ($action === 'withdraw-transfer') {
            $movement->withdrawTransferRequest($database, $career->playerId(), $date);
            $output->write('CAREER ACTION — transfer request withdrawn.');
            $this->home($saveId, $output);
            return 0;
        }
        if ($action !== 'decide') { throw new RuntimeException(sprintf('Unknown career action "%s".', $action)); }
        $optionNumber = filter_var($arguments[2] ?? null, FILTER_VALIDATE_INT);
        if ($optionNumber === false || $optionNumber < 1) { throw new RuntimeException('Decision option must be a positive number from the displayed options.'); }
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($career->playerId(), $date)[0] ?? null;
        if ($opportunity === null) { throw new RuntimeException('There is no open Career decision.'); }
        $options = $opportunity->context()['options'] ?? [];
        $selected = $options[$optionNumber - 1] ?? null;
        if (!is_array($selected) || !isset($selected['id'])) { throw new RuntimeException('That Career decision option is unavailable.'); }
        if (($opportunity->context()['decision_kind'] ?? null) === 'controlled_transfer') {
            $resolved = $movement->resolveTransferDecision($database, $opportunity->id(), (string) $selected['id'], $date);
        } elseif (($opportunity->context()['decision_kind'] ?? null) === 'contract_boundary' || $opportunity->type()->value === 'contract_renewal') {
            $resolved = $movement->resolveContractDecision($database, $opportunity->id(), (string) $selected['id'], $date);
        } else {
            throw new RuntimeException('This Career decision has no player-facing resolver.');
        }
        $output->write(sprintf('CAREER DECISION — resolved as %s.', $resolved->context()['offer_status'] ?? 'complete'));
        $this->home($saveId, $output);
        return 0;
    }

    private function home(string $saveId, ConsoleOutputInterface $output): void
    {
        (new CareerHomeCommand($this->services, $this->saveStore))->execute([$saveId], $output);
    }
}
