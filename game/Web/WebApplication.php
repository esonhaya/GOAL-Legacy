<?php

declare(strict_types=1);

namespace Goal\Legacy\Web;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Devtools\BufferedConsoleOutput;
use Goal\Legacy\Devtools\Commands\CareerContinueCommand;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Devtools\Presentation\CareerLabels;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Player\Avatar\AvatarCatalog;
use Goal\Legacy\Modules\Player\Avatar\PlayerAppearanceService;
use Goal\Legacy\Modules\Player\Avatar\PortraitContext;
use Goal\Legacy\Modules\Player\Avatar\PortraitRenderer;
use Goal\Legacy\Modules\Player\Domain\CareerPriority;
use Goal\Legacy\Modules\Player\Domain\CareerStartRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerAppearance;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Finance\LifestyleCatalog;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Persistence\PlayerSeasonStatisticsRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use RuntimeException;

/** Server-rendered graphical adapter around the existing career services. */
final class WebApplication
{
    private const DRAFT = 'goal_legacy_new_career';

    public function __construct(
        private readonly CoreServices $services,
        private readonly string $projectRoot,
    ) {
    }

    /** @param array<string,mixed> $query @param array<string,mixed> $post @param array<string,mixed> $session @return array{status:int,headers:array<string,string>,body:string} */
    public function handle(string $method, string $path, array $query, array $post, array &$session): array
    {
        try {
            if ($path === '/portrait') {
                return $this->portrait($query, $session);
            }
            if ($method === 'POST') {
                return $this->post($post, $query, $session);
            }

            return $this->get($query, $session);
        } catch (\Throwable $exception) {
            return $this->html('GOAL: Legacy — Error', $this->errorPage($exception->getMessage()), null, '', 500, $session);
        }
    }

    /** @param array<string,mixed> $query @param array<string,mixed> $session */
    private function get(array $query, array &$session): array
    {
        $page = (string) ($query['page'] ?? 'menu');
        if ($page === 'action') { return $this->redirect(WebView::url('menu')); }
        if ($page === 'menu') { return $this->html('Main Menu', $this->mainMenu(), null, '', 200, $session); }
        if ($page === 'new') { return $this->newCareer((string) ($query['step'] ?? 'identity'), $session); }
        if ($page === 'portrait') { return $this->portrait($query, $session); }
        $saveId = $this->saveId($query['save'] ?? null);
        if ($saveId === null) { return $this->redirect(WebView::url('menu')); }
        if (!$this->services->saveStore()->exists($saveId)) {
            $session['web_flash'] = 'That saved career could not be found.';
            return $this->redirect(WebView::url('menu'));
        }

        return match ($page) {
            'home' => $this->home($saveId, $session),
            'career' => $this->career($saveId, $session),
            'legacy' => $this->legacy($saveId, $session),
            'market' => $this->market($saveId, $session),
            'appearance' => $this->existingAppearance($saveId, $session),
            'squad' => $this->squad($saveId, $session, isset($query['club']) ? (string) $query['club'] : null),
            'profile' => $this->profile($saveId, (string) ($query['player'] ?? ''), $session),
            'relationships' => $this->relationships($saveId, $session),
            'pulse' => $this->pulse($saveId, $session),
            'world' => $this->world($saveId, $session),
            'international' => $this->international($saveId, $session),
            'national-team' => $this->nationalTeam($saveId, (string) ($query['team'] ?? ''), $session),
            'competition' => $this->competition($saveId, (string) ($query['competition'] ?? ''), $session),
            'club' => $this->club($saveId, (string) ($query['club'] ?? ''), $session),
            'news' => $this->news($saveId, $session),
            'training' => $this->training($saveId, $session),
            'finances' => $this->finances($saveId, $session),
            'lifestyle' => $this->lifestyle($saveId, $session),
            'event' => $this->event($saveId, $session),
            'decision' => $this->decision($saveId, $session),
            'matchday' => $this->matchday($saveId, (string) ($query['match'] ?? ''), $session),
            default => $this->html('Not found', $this->errorPage('That page is not available.'), $saveId, '', 404, $session),
        };
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $query @param array<string,mixed> $session */
    private function post(array $post, array $query, array &$session): array
    {
        $action = (string) ($post['action'] ?? '');
        try {
            return match ($action) {
                'new_identity' => $this->postIdentity($post, $session),
                'new_body' => $this->postBody($post, $session),
                'new_appearance' => $this->postAppearance($post, $session),
                'new_profile' => $this->postProfile($post, $session),
                'new_youth_view' => $this->redirect(WebView::url('new', ['step' => 'youth'])),
                'select_club' => $this->selectClub($post, $session),
                'set_training' => $this->setTraining($post, $session),
                'set_position_focus' => $this->setPositionFocus($post, $session),
                'cancel_position_focus' => $this->cancelPositionFocus($post, $session),
                'change_primary_position' => $this->changePrimaryPosition($post, $session),
                'set_priority' => $this->setPriority($post, $session),
                'purchase_lifestyle' => $this->purchaseLifestyle($post, $session),
                'activate_lifestyle' => $this->activateLifestyle($post, $session),
                'continue' => $this->continueCareer($post, $session),
                'save_exit' => $this->saveExit($session),
                'resolve_event' => $this->resolveEvent($post, $session),
                'resolve_decision' => $this->resolveDecision($post, $session),
                'request_transfer' => $this->transferRequest($post, $session, false),
                'withdraw_transfer' => $this->transferRequest($post, $session, true),
                'accept_transfer_offer' => $this->acceptTransferOffer($post, $session),
                'resolve_pulse' => $this->resolvePulse($post, $session),
                default => throw new RuntimeException('That action is not available.'),
            };
        } catch (\Throwable $exception) {
            $saveId = $this->saveId($post['save'] ?? null);
            if ($action === 'new_identity' || $action === 'new_body' || $action === 'new_appearance' || $action === 'new_profile') {
                $session['web_flash'] = $exception->getMessage();
                $step = $action === 'new_identity' ? 'identity' : ($action === 'new_body' ? 'body' : ($action === 'new_appearance' ? 'appearance' : 'profile'));
                return $this->redirect(WebView::url('new', ['step' => $step]));
            }
            $session['web_flash'] = $exception->getMessage();
            return $this->redirect($saveId === null || !$this->services->saveStore()->exists($saveId) ? WebView::url('menu') : WebView::url('home', ['save' => $saveId]));
        }
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function postIdentity(array $post, array &$session): array
    {
        $save = trim((string) ($post['save'] ?? ''));
        $name = trim((string) ($post['name'] ?? ''));
        $nation = trim((string) ($post['nation'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $save) !== 1) { throw new RuntimeException('Choose a save name using letters, numbers, hyphens, or underscores.'); }
        if ($this->services->saveStore()->exists($save)) { throw new RuntimeException('That save name is already in use.'); }
        if ($name === '' || strlen($name) > 80) { throw new RuntimeException('Enter a player name between 1 and 80 characters.'); }
        if ($this->services->nationModule()->service()->loadSelected() === []) { throw new RuntimeException('No Nations are available for a new career.'); }
        $valid = array_map(static fn ($item): string => $item->id()->value(), $this->services->nationModule()->service()->loadSelected());
        if (!in_array($nation, $valid, true)) { throw new RuntimeException('Choose a listed nationality.'); }
        $session[self::DRAFT] = ['save' => $save, 'name' => $name, 'nation' => $nation];
        $this->ensureDraftAppearance($session);

        return $this->redirect(WebView::url('new', ['step' => 'body']));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function postBody(array $post, array &$session): array
    {
        $draft = $this->draft($session);
        $height = filter_var($post['height'] ?? null, FILTER_VALIDATE_INT);
        $weight = filter_var($post['weight'] ?? null, FILTER_VALIDATE_INT);
        if ($height === false || $height < 120 || $height > 250) { throw new RuntimeException('Height must be between 120 and 250 cm.'); }
        if ($weight === false || $weight < 30 || $weight > 200) { throw new RuntimeException('Weight must be between 30 and 200 kg.'); }
        $draft['height'] = (int) $height;
        $draft['weight'] = (int) $weight;
        $session[self::DRAFT] = $draft;

        return $this->redirect(WebView::url('new', ['step' => 'appearance']));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function postAppearance(array $post, array &$session): array
    {
        $draft = $this->draft($session);
        $this->ensureDraftAppearance($session);
        $mode = (string) ($post['mode'] ?? 'save');
        $catalog = new AvatarCatalog();
        $existingSave = $this->saveId($post['edit_save'] ?? ($draft['existing_save'] ?? null));
        if ($mode === 'preset') {
            $preset = $catalog->preset((string) ($post['preset'] ?? ''));
            if ($preset === null || !is_array($preset['appearance'] ?? null)) { throw new RuntimeException('That Avatar preset is unavailable.'); }
            $draft['appearance'] = $preset['appearance'];
        } elseif ($mode === 'randomize') {
            $draft['appearance'] = $this->randomAppearance($catalog);
        } elseif ($mode === 'category') {
            $field = (string) ($post['category'] ?? '');
            $draft['appearance'] = array_replace((array) $draft['appearance'], [$field => $this->randomAppearanceField($catalog, $field)]);
        } elseif ($mode === 'reset') {
            $draft['appearance'] = (array) (($catalog->presets()[0] ?? [])['appearance'] ?? []);
        } else {
            $draft['appearance'] = $this->validatedAppearance($post, $catalog)->toArray();
        }
        if ($mode === 'save' && $existingSave !== null) {
            $database = $this->database($existingSave);
            $career = (new CareerPlayerRepository($database))->get($existingSave);
            (new PlayerAppearanceService())->save($database, (new PlayerRepository($database))->get($career->playerId()), PlayerAppearance::fromArray($draft['appearance']));
            unset($session[self::DRAFT]);
            $session['web_flash'] = 'Appearance saved.';
            return $this->redirect(WebView::url('home', ['save' => $existingSave]));
        }
        $session[self::DRAFT] = $draft;

        return $this->redirect($existingSave === null
            ? WebView::url('new', ['step' => $mode === 'save' ? 'profile' : 'appearance'])
            : WebView::url('appearance', ['save' => $existingSave]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function postProfile(array $post, array &$session): array
    {
        $draft = $this->draft($session);
        $position = trim((string) ($post['position'] ?? ''));
        $archetype = trim((string) ($post['archetype'] ?? ''));
        $seed = filter_var($post['seed'] ?? null, FILTER_VALIDATE_INT);
        if ($position === '' || $archetype === '' || $seed === false || $seed < 0) { throw new RuntimeException('Complete the football profile with a position, archetype, and non-negative seed.'); }
        $request = $this->careerRequest($draft, $position, $archetype, (int) $seed);
        $preview = (new WebCareerStartWorkflow($this->services, $this->projectRoot))->preview($request);
        $draft['position'] = $position;
        $draft['archetype'] = $archetype;
        $draft['seed'] = (int) $seed;
        $draft['player'] = $preview['player']->toArray();
        $draft['opportunities'] = $preview['opportunities'];
        $session[self::DRAFT] = $draft;

        return $this->redirect(WebView::url('new', ['step' => 'review']));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function selectClub(array $post, array &$session): array
    {
        $draft = $this->draft($session);
        $clubId = trim((string) ($post['club'] ?? ''));
        $token = (string) ($post['token'] ?? '');
        if (!$this->consumeToken($session, 'new_career', $token)) { throw new RuntimeException('That Club selection has already been submitted.'); }
        if (!isset($draft['player'], $draft['opportunities'])) { throw new RuntimeException('Return to Youth Camp before selecting a Club.'); }
        $request = $this->careerRequest($draft, (string) $draft['position'], (string) $draft['archetype'], (int) $draft['seed']);
        $appearance = PlayerAppearance::fromArray((array) ($draft['appearance'] ?? []));
        (new WebCareerStartWorkflow($this->services, $this->projectRoot))->create($request, $clubId, $appearance, is_array($draft['opportunities'] ?? null) ? $draft['opportunities'] : null);
        unset($session[self::DRAFT]);
        $session['web_flash'] = 'Career started. Your first Season is ready.';

        return $this->redirect(WebView::url('home', ['save' => $request->careerId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function setTraining(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        $database = $this->database($saveId);
        $summary = $this->snapshot($saveId, $database);
        $focus = TrainingFocus::fromInput((string) ($post['focus'] ?? 'balanced'));
        $this->services->playerModule()->service()->careerExperienceService()->setTrainingFocus($database, (string) $summary['summary']['player']['id'], $focus, $summary['date']);
        $session['web_flash'] = 'Training focus updated.';

        return $this->redirect(WebView::url('training', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function setPositionFocus(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $position = (string) ($post['position'] ?? '');
        $this->services->playerModule()->service()->positionDevelopmentService()->setFocus($database, (string) $snapshot['summary']['player']['id'], $position, $snapshot['date']);
        $session['web_flash'] = 'Position development focus updated.';

        return $this->redirect(WebView::url('training', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function cancelPositionFocus(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $this->services->playerModule()->service()->positionDevelopmentService()->cancelFocus($database, (string) $snapshot['summary']['player']['id'], $snapshot['date']);
        $session['web_flash'] = 'Position development focus cancelled.';

        return $this->redirect(WebView::url('training', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function changePrimaryPosition(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $position = (string) ($post['position'] ?? '');
        $this->services->playerModule()->service()->positionDevelopmentService()->changePrimary($database, (string) $snapshot['summary']['player']['id'], $position, $snapshot['date']);
        $session['web_flash'] = 'Primary position changed. Future selection and career context now use the new position.';

        return $this->redirect(WebView::url('profile', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function setPriority(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $priority = CareerPriority::fromInput((string) ($post['priority'] ?? 'balanced'));
        $this->services->playerModule()->service()->careerExperienceService()->setPriority($database, (string) $snapshot['summary']['player']['id'], $priority, $snapshot['date']);
        $session['web_flash'] = 'Career priority updated.';

        return $this->redirect(WebView::url('training', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function purchaseLifestyle(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        if (!$this->consumeToken($session, 'purchase_' . $saveId, (string) ($post['token'] ?? ''))) {
            throw new RuntimeException('That purchase action has already been handled.');
        }
        if ((string) ($post['confirm'] ?? '') !== '1') {
            throw new RuntimeException('Confirm the purchase before continuing.');
        }
        $database = $this->database($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $itemId = trim((string) ($post['item'] ?? ''));
        $result = $this->services->playerFinanceService()->purchase($database, $career->playerId(), $itemId, $world->currentDate($worldService->calendar()));
        $item = LifestyleCatalog::find($itemId);
        $session['web_flash'] = sprintf('%s purchased for %s. Balance: %s.', $item['label'] ?? 'Item', $this->money((int) ($result['price'] ?? 0)), $this->money((int) ($result['balance'] ?? 0)));

        return $this->redirect(WebView::url('lifestyle', ['save' => $saveId]));
    }

    private function activateLifestyle(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        $itemId = trim((string) ($post['item'] ?? ''));
        if (!$this->consumeToken($session, 'activate_' . $saveId . '_' . $itemId, (string) ($post['token'] ?? ''))) {
            throw new RuntimeException('That lifestyle change has already been handled.');
        }
        $database = $this->database($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $this->services->playerFinanceService()->activate($database, $career->playerId(), $itemId);
        $item = LifestyleCatalog::find($itemId);
        $session['web_flash'] = sprintf('%s is now your active %s.', $item['label'] ?? 'Lifestyle item', strtolower((string) ($item['category'] ?? 'lifestyle')));

        return $this->redirect(WebView::url('lifestyle', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function continueCareer(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        if (!$this->consumeToken($session, 'continue_' . $saveId, (string) ($post['token'] ?? ''))) {
            $session['web_flash'] = 'That Continue action has already been handled.';
            return $this->redirect(WebView::url('home', ['save' => $saveId]));
        }
        $output = new BufferedConsoleOutput();
        (new CareerContinueCommand($this->services))->execute([$saveId], $output);
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        if (($snapshot['summary']['pending_decisions'] ?? []) !== []) { return $this->redirect(WebView::url('decision', ['save' => $saveId])); }
        $careerId = (new CareerPlayerRepository($database))->get($saveId)->playerId()->value();
        if ($this->services->playerModule()->service()->careerExperienceService()->pendingEvent($database, $careerId) !== null) {
            return $this->redirect(WebView::url('event', ['save' => $saveId]));
        }
        $match = $this->latestControlledMatch($database, $snapshot['summary'], $snapshot['date']);
        if ($match !== null) {
            return $this->redirect(WebView::url('matchday', ['save' => $saveId, 'match' => $match->id()->value()]));
        }
        $session['web_flash'] = $output->messages()[0] ?? 'Career updated.';

        return $this->redirect(WebView::url('home', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function resolvePulse(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        $sourceKey = trim((string) ($post['source_key'] ?? ''));
        if ($sourceKey === '' || !$this->consumeToken($session, 'pulse_' . $saveId . '_' . $sourceKey, (string) ($post['token'] ?? ''))) {
            throw new RuntimeException('That Pulse response has already been handled.');
        }
        $database = $this->database($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $choice = trim((string) ($post['choice'] ?? ''));
        if ($choice === '' || !$this->services->playerModule()->service()->pulseService()->respond($database, $career->playerId(), $sourceKey, $choice, $world->currentDate($worldService->calendar()))) {
            throw new RuntimeException('That Pulse response is no longer available.');
        }
        $session['web_flash'] = 'Your response is now on Pulse.';

        return $this->redirect(WebView::url('pulse', ['save' => $saveId]));
    }

    /** Saving is already transactional; this action closes the player session cleanly. */
    private function saveExit(array &$session): array
    {
        unset($session[self::DRAFT], $session['web_tokens']);
        $session['web_flash'] = 'Career saved. Choose Load Career to continue.';

        return $this->redirect(WebView::url('menu'));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function resolveEvent(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        if (!$this->consumeToken($session, 'event_' . $saveId, (string) ($post['token'] ?? ''))) { throw new RuntimeException('That event choice has already been submitted.'); }
        $database = $this->database($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $choice = filter_var($post['choice'] ?? null, FILTER_VALIDATE_INT);
        if ($choice === false) { throw new RuntimeException('Choose one of the listed event options.'); }
        $resolved = $this->services->playerModule()->service()->careerExperienceService()->resolve($database, (string) ($post['event_id'] ?? ''), (int) $choice, $world->currentDate($worldService->calendar()));
        $session['web_flash'] = (string) (($resolved->consequence()['history'] ?? 'Career event resolved.'));

        return $this->redirect(WebView::url('home', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function resolveDecision(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        if (!$this->consumeToken($session, 'decision_' . $saveId, (string) ($post['token'] ?? ''))) { throw new RuntimeException('That decision has already been submitted.'); }
        $database = $this->database($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $date = $world->currentDate($worldService->calendar());
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($career->playerId(), $date)[0] ?? null;
        if ($opportunity === null) { throw new RuntimeException('There is no open Career decision.'); }
        $choice = filter_var($post['choice'] ?? null, FILTER_VALIDATE_INT);
        $selected = $choice === false ? null : ($opportunity->context()['options'][(int) $choice - 1] ?? null);
        if (!is_array($selected) || !isset($selected['id'])) { throw new RuntimeException('Choose one of the listed decision options.'); }
        $movement = $this->services->transferModule()->service()->careerMovement();
        $kind = $opportunity->context()['decision_kind'] ?? $opportunity->type()->value;
        if ($kind === 'controlled_transfer') {
            $movement->resolveTransferDecision($database, $opportunity->id(), (string) $selected['id'], $date);
        } elseif ($kind === 'contract_boundary' || $opportunity->type()->value === 'contract_renewal') {
            $movement->resolveContractDecision($database, $opportunity->id(), (string) $selected['id'], $date);
        } elseif ($kind === 'retirement' || $opportunity->type()->value === 'retirement') {
            $this->services->playerModule()->service()->lifecycleService()->resolveRetirementDecision($database, $opportunity->id(), (string) $selected['id'], $date);
        } else { throw new RuntimeException('This Career decision has no player-facing resolver.'); }
        $session['web_flash'] = 'Career decision resolved.';

        return $this->redirect(WebView::url('home', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function transferRequest(array $post, array &$session, bool $withdraw): array
    {
        $saveId = $this->requiredSave($post);
        if (!$withdraw && (string) ($post['confirm'] ?? '') !== '1') { throw new RuntimeException('Confirm the transfer request before submitting it.'); }
        $database = $this->database($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $movement = $this->services->transferModule()->service()->careerMovement();
        if ($withdraw) {
            $movement->withdrawTransferRequest($database, $career->playerId(), $world->currentDate($worldService->calendar()));
            $session['web_flash'] = 'Transfer request withdrawn.';
        } else {
            $movement->requestTransfer($database, $career->playerId(), $worldService->seasonRepository($database)->get($world->currentSeasonId()), $world->currentDate($worldService->calendar()));
            $session['web_flash'] = 'Transfer request submitted.';
        }

        return $this->redirect(WebView::url('home', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $session */
    private function acceptTransferOffer(array $post, array &$session): array
    {
        $saveId = $this->requiredSave($post);
        if (!$this->consumeToken($session, 'offer_' . $saveId . '_' . (string) ($post['offer_id'] ?? ''), (string) ($post['token'] ?? ''))) {
            throw new RuntimeException('That transfer offer has already been handled.');
        }
        $database = $this->database($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $this->services->transferModule()->service()->careerMovement()->accept($database, (string) ($post['offer_id'] ?? ''), $world->currentDate($worldService->calendar()));
        $session['web_flash'] = 'Transfer accepted. Your new Club Contract is active.';

        return $this->redirect(WebView::url('home', ['save' => $saveId]));
    }

    /** @param array<string,mixed> $session */
    private function mainMenu(): string
    {
        $cards = '';
        foreach ($this->services->saveStore()->list() as $metadata) {
            $saveId = $metadata->id();
            $player = $club = $season = $playerId = null;
            try {
                $menu = $this->menuCareer($saveId);
                $player = $menu['player']; $playerId = $menu['player_id']; $club = $menu['club']; $season = $menu['season'];
            } catch (\Throwable) {
                $player = 'Career unavailable';
            }
            $portrait = $playerId !== null
                ? WebView::portrait($this->portraitUrl($saveId, $playerId, 'career', 64), (string) ($player ?? 'Player'), 'portrait portrait-small')
                : '';
            $cards .= '<article class="save-card">' . $portrait . '<div class="save-card-copy"><span class="eyebrow">SAVED CAREER</span><h2>' . WebView::e($player ?? $metadata->name()) . '</h2><p>' . WebView::e($club ?? 'Free Agent') . ($season === null ? '' : ' · ' . WebView::e($season)) . '</p></div>' . WebView::link('home', ['save' => $saveId], 'Load Career', 'button button-primary') . '</article>';
        }
        $body = '<div class="hero hero-menu"><div class="eyebrow">FOOTBALL CAREER SIMULATION</div><h1>GOAL: LEGACY</h1><p>Build your football life, shape your development, and make every season your own.</p><div class="hero-actions">' . WebView::link('new', ['step' => 'identity'], 'New Career', 'button button-primary button-large') . '</div></div>';
        $body .= WebView::section('CONTINUE YOUR STORY', 'Load Career', $cards === '' ? WebView::emptyState('No saved careers yet. Start a new career to enter Youth Camp.') : '<div class="save-list">' . $cards . '</div>');

        return WebView::layout('Main Menu', $body, null, '', null);
    }

    /** Lightweight saved-career metadata; avoids advancing the shared world clock while listing saves. */
    private function menuCareer(string $saveId): array
    {
        $database = $this->database($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $player = (new PlayerRepository($database))->get($career->playerId());
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->repository($database)->get($saveId);
        $season = $world->currentSeasonId() === null ? null : $worldService->seasonRepository($database)->get($world->currentSeasonId());
        $club = null;
        if ($world->currentSeasonId() !== null) {
            $membership = $this->services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $world->currentSeasonId())[0] ?? null;
            if ($membership !== null) { $club = $this->services->clubModule()->service()->repository($database)->get($membership->clubId()); }
        }

        return [
            'player' => $player->preferredName(),
            'player_id' => $player->id()->value(),
            'club' => $club?->canonicalName() ?? 'Free Agent',
            'season' => $season?->label(),
        ];
    }

    /** @param array<string,mixed> $session */
    private function newCareer(string $step, array &$session): array
    {
        if ($step !== 'identity' && !isset($session[self::DRAFT])) { return $this->redirect(WebView::url('new', ['step' => 'identity'])); }
        if ($step === 'appearance') { $this->ensureDraftAppearance($session); }
        $draft = (array) ($session[self::DRAFT] ?? []);
        $content = match ($step) {
            'body' => $this->newBodyView($draft),
            'appearance' => $this->newAppearanceView($draft),
            'profile' => $this->newProfileView($draft),
            'review' => $this->newReviewView($draft),
            'youth' => $this->newYouthView($draft, $session),
            default => $this->newIdentityView($draft),
        };

        return $this->html('New Career', $content, null, '', 200, $session);
    }

    private function newIdentityView(array $draft): string
    {
        $options = '';
        foreach ($this->services->nationModule()->service()->loadSelected() as $nation) {
            $selected = ($draft['nation'] ?? '') === $nation->id()->value() ? ' selected' : '';
            $options .= '<option value="' . WebView::e($nation->id()->value()) . '"' . $selected . '>' . WebView::e($nation->displayName()) . '</option>';
        }
        $body = '<div class="flow-heading"><div class="eyebrow">NEW CAREER · 1 OF 6</div><h1>Identity</h1><p>Start with the player you want to become.</p></div>';
        $body .= '<form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel form-panel" data-busy><input type="hidden" name="action" value="new_identity"><label>Save name<input required name="save" value="' . WebView::e($draft['save'] ?? '') . '" placeholder="my-career"></label><label>Player name<input required name="name" value="' . WebView::e($draft['name'] ?? '') . '" placeholder="Alex Rivera"></label><label>Nationality<select name="nation" required>' . $options . '</select></label><div class="form-actions"><a class="button button-secondary" href="' . WebView::e(WebView::url('menu')) . '">Back</a><button class="button button-primary" type="submit">Continue</button></div></form>';

        return WebView::layout('New Career — Identity', $body, null, '', null);
    }

    private function newBodyView(array $draft): string
    {
        $body = '<div class="flow-heading"><div class="eyebrow">NEW CAREER · 2 OF 6</div><h1>Body</h1><p>Set the physical profile used by the canonical Player model.</p></div>';
        $body .= '<form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel form-panel" data-busy><input type="hidden" name="action" value="new_body"><label>Height <span class="input-unit">cm</span><input type="number" min="120" max="250" name="height" value="' . WebView::e($draft['height'] ?? 180) . '" required></label><label>Weight <span class="input-unit">kg</span><input type="number" min="30" max="200" name="weight" value="' . WebView::e($draft['weight'] ?? 75) . '" required></label><div class="form-actions">' . WebView::link('new', ['step' => 'identity'], 'Back') . '<button class="button button-primary" type="submit">Continue</button></div></form>';

        return WebView::layout('New Career — Body', $body, null, '', null);
    }

    private function newAppearanceView(array $draft): string
    {
        $catalog = new AvatarCatalog();
        $appearance = (array) ($draft['appearance'] ?? []);
        $fields = [
            'skin_tone' => ['label' => 'Skin', 'type' => 'palette:skin'], 'face' => ['label' => 'Face', 'type' => 'asset:face'], 'jaw' => ['label' => 'Jaw / Chin', 'type' => 'asset:jaw'], 'ears' => ['label' => 'Ears', 'type' => 'asset:ears'],
            'eyes' => ['label' => 'Eyes', 'type' => 'asset:eyes'], 'eye_color' => ['label' => 'Eye Color', 'type' => 'palette:eye'], 'brows' => ['label' => 'Eyebrows', 'type' => 'asset:brows'], 'nose' => ['label' => 'Nose', 'type' => 'asset:nose'],
            'mouth' => ['label' => 'Mouth', 'type' => 'asset:mouth'], 'hair' => ['label' => 'Hair', 'type' => 'asset:hair'], 'hair_color' => ['label' => 'Hair Color', 'type' => 'palette:hair'], 'facial_hair' => ['label' => 'Facial Hair', 'type' => 'asset:facial_hair'],
            'facial_hair_color' => ['label' => 'Facial Hair Color', 'type' => 'palette:hair'], 'skin_detail' => ['label' => 'Skin Detail', 'type' => 'asset:skin_detail'], 'scar' => ['label' => 'Scar', 'type' => 'asset:scar'], 'accessory' => ['label' => 'Accessory', 'type' => 'asset:accessory'],
        ];
        $controls = '';
        foreach ($fields as $field => $config) {
            [$kind, $category] = explode(':', $config['type'], 2);
            $items = $kind === 'palette' ? $catalog->palettes($category) : array_values(array_filter($catalog->assets($category), static fn (array $item): bool => (bool) ($item['creator_enabled'] ?? false)));
            $options = '';
            foreach ($items as $item) {
                $id = (string) ($item['id'] ?? '');
                $selected = ($appearance[$field] ?? '') === $id ? ' selected' : '';
                $options .= '<option value="' . WebView::e($id) . '"' . $selected . '>' . WebView::e($item['label'] ?? $id) . '</option>';
            }
            $controls .= '<label>' . WebView::e($config['label']) . '<select data-appearance-field="' . WebView::e($field) . '" name="' . WebView::e($field) . '" onchange="updateDraftPortrait()">' . $options . '</select></label>';
        }
        $presets = '<select name="preset"><option value="">Choose a preset</option>';
        foreach ($catalog->presets() as $preset) { $presets .= '<option value="' . WebView::e($preset['id'] ?? '') . '">' . WebView::e($preset['label'] ?? 'Preset') . '</option>'; }
        $presets .= '</select><button class="button button-secondary" name="mode" value="preset">Apply preset</button>';
        $existing = $this->saveId($draft['existing_save'] ?? null);
        $eyebrow = $existing === null ? 'NEW CAREER · 3 OF 6' : 'PLAYER PROFILE · APPEARANCE';
        $heading = $existing === null ? 'Avatar Creator' : 'Update appearance';
        $back = $existing === null ? WebView::link('new', ['step' => 'body'], 'Back') : WebView::link('home', ['save' => $existing], 'Back to Career Home');
        $editHidden = $existing === null ? '' : '<input type="hidden" name="edit_save" value="' . WebView::e($existing) . '">';
        $body = '<div class="flow-heading"><div class="eyebrow">' . $eyebrow . '</div><h1>' . $heading . '</h1><p>Your portrait is cosmetic identity. It never changes football ability or simulation results.</p></div>';
        $body .= '<div class="creator-layout"><div class="panel creator-preview"><div class="eyebrow">LIVE PREVIEW</div><img data-draft-portrait class="creator-portrait" src="' . WebView::e(WebView::url('portrait', ['draft' => 1, 'size' => 256])) . '" alt="Player portrait"><p class="muted">Change an option to preview it immediately.</p></div><form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel creator-controls" data-busy><input type="hidden" name="action" value="new_appearance">' . $editHidden . $controls . '<div class="creator-tools">' . $presets . '<button class="button button-secondary" name="mode" value="randomize">Randomize all</button><select name="category"><option value="hair">Hair</option><option value="face">Face</option><option value="eyes">Eyes</option><option value="nose">Nose</option><option value="mouth">Mouth</option><option value="facial_hair">Facial hair</option></select><button class="button button-secondary" name="mode" value="category">Randomize category</button><button class="button button-secondary" name="mode" value="reset">Reset</button></div><div class="form-actions">' . $back . '<button class="button button-primary" name="mode" value="save" type="submit">' . ($existing === null ? 'Confirm appearance' : 'Save appearance') . '</button></div></form></div>';

        return WebView::layout('New Career — Avatar Creator', $body, null, '', null);
    }

    private function newProfileView(array $draft): string
    {
        $positions = ['GK' => 'Goalkeeper', 'CB' => 'Centre Back', 'LB' => 'Left Back', 'RB' => 'Right Back', 'DM' => 'Defensive Midfielder', 'CM' => 'Central Midfielder', 'AM' => 'Attacking Midfielder', 'LW' => 'Left Winger', 'RW' => 'Right Winger', 'ST' => 'Striker'];
        $positionOptions = '';
        foreach ($positions as $id => $label) { $positionOptions .= '<option value="' . $id . '"' . (($draft['position'] ?? '') === $id ? ' selected' : '') . '>' . $label . '</option>'; }
        $archetypes = ['late_bloomer' => 'Late Bloomer', 'regular' => 'Regular', 'prodigy' => 'Prodigy'];
        $archetypeOptions = '';
        foreach ($archetypes as $id => $label) { $archetypeOptions .= '<option value="' . $id . '"' . (($draft['archetype'] ?? '') === $id ? ' selected' : '') . '>' . $label . '</option>'; }
        $body = '<div class="flow-heading"><div class="eyebrow">NEW CAREER · 4 OF 6</div><h1>Football Profile</h1><p>Choose the football identity that Youth Camp will evaluate.</p></div><form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel form-panel" data-busy><input type="hidden" name="action" value="new_profile"><input type="hidden" name="seed" value="' . WebView::e($draft['seed'] ?? 24001) . '"><label>Position<select name="position" required>' . $positionOptions . '</select></label><label>Development path<select name="archetype" required>' . $archetypeOptions . '</select></label><p class="muted">Youth Camp will evaluate this profile against the available Clubs.</p><div class="form-actions">' . WebView::link('new', ['step' => 'appearance'], 'Back') . '<button class="button button-primary" type="submit">Review profile</button></div></form>';

        return WebView::layout('New Career — Football Profile', $body, null, '', null);
    }

    private function newReviewView(array $draft): string
    {
        $player = (array) ($draft['player'] ?? []);
        $body = '<div class="flow-heading"><div class="eyebrow">NEW CAREER · 5 OF 6</div><h1>Review</h1><p>Everything is ready for Youth Camp.</p></div><div class="panel review-card"><div class="review-avatar"><img class="portrait portrait-large" src="' . WebView::e(WebView::url('portrait', ['draft' => 1, 'size' => 256])) . '" alt="Player portrait"></div><div class="review-facts"><h2>' . WebView::e($draft['name'] ?? '') . '</h2><p>' . WebView::e(CareerLabels::position($draft['position'] ?? null)) . ' · ' . WebView::e(CareerLabels::value($draft['archetype'] ?? null)) . '</p><div class="stat-grid compact">' . WebView::stat('Starting OVR', $player['overall_rating'] ?? '—') . WebView::stat('Potential', $player['potential'] ?? '—') . WebView::stat('Height', ($draft['height'] ?? '—') . ' cm') . WebView::stat('Weight', ($draft['weight'] ?? '—') . ' kg') . '</div></div></div><div class="form-actions">' . WebView::link('new', ['step' => 'profile'], 'Back') . WebView::form('new_youth_view', 'Enter Youth Camp', [], 'button button-primary', 'data-busy') . '</div>';

        return WebView::layout('New Career — Review', $body, null, '', null);
    }

    private function newYouthView(array $draft, array &$session): string
    {
        $opportunities = is_array($draft['opportunities'] ?? null) ? $draft['opportunities'] : [];
        $cards = '';
        foreach ($opportunities as $opportunity) {
            $cards .= '<article class="opportunity-card"><div><span class="eyebrow">TIER ' . WebView::e($opportunity['tier'] ?? '') . '</span><h2>' . WebView::e($opportunity['club'] ?? 'Club') . '</h2><p>' . WebView::e($opportunity['competition'] ?? '') . ' · ' . WebView::e(CareerLabels::value($opportunity['role'] ?? null)) . '</p><p class="muted">' . WebView::e($opportunity['context'] ?? '') . '</p></div>' . WebView::form('select_club', 'Join this Club', ['club' => $opportunity['club_id'] ?? '', 'token' => $this->issueToken($session, 'new_career')], 'button button-primary', 'data-busy') . '</article>';
        }
        $body = '<div class="flow-heading"><div class="eyebrow">NEW CAREER · 6 OF 6</div><h1>Youth Camp</h1><p>These canonical Club opportunities reflect your Player profile and the current football world.</p></div>' . ($cards === '' ? WebView::emptyState('No starting opportunities are available.') : '<div class="opportunity-list">' . $cards . '</div>') . '<div class="form-actions">' . WebView::link('new', ['step' => 'profile'], 'Back to profile') . '</div>';

        return WebView::layout('New Career — Youth Camp', $body, null, '', null);
    }

    /** @return array{world:object,summary:array<string,mixed>,date:SimulationDate} */
    private function snapshot(string $saveId, DatabaseInterface $database): array
    {
        return (new CareerPresentationService($this->services))->snapshot($database, $saveId, false);
    }

    /** @param array<string,mixed> $session */
    private function home(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $summary = $snapshot['summary'];
        $player = (array) ($summary['player'] ?? []);
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $portrait = $this->portraitUrl($saveId, (string) ($player['id'] ?? ''), 'career', 256);
        $season = (array) ($summary['season_stats'] ?? []);
        $form = (array) ($summary['recent_form'] ?? []);
        $performance = (array) ($summary['season_performance'] ?? []);
        $finance = $this->services->playerFinanceService()->summary($database, (string) ($player['id'] ?? ''), $snapshot['date']);
        $next = (new CareerPresentationService($this->services))->nextMatch($database, $summary);
        $clubContext = (new CareerPresentationService($this->services))->clubContext($database, $summary);
        $clubSeason = is_array($summary['club_season'] ?? null) ? $summary['club_season'] : null;
        $profile = '<div class="profile-hero">' . WebView::portrait($portrait, (string) ($player['preferred_name'] ?? 'Player'), 'portrait portrait-large') . '<div><div class="eyebrow">CAREER HOME · ' . WebView::e($snapshot['date']->toIsoString()) . '</div><h1>' . WebView::e($player['preferred_name'] ?? 'Player') . '</h1><p>' . WebView::e(CareerLabels::position($player['primary_position'] ?? null)) . ' · ' . WebView::e(CareerLabels::nationality($player['primary_nation_id'] ?? null)) . '</p><div class="tag-row"><span class="tag">OVR ' . WebView::e($summary['current_ovr'] ?? '—') . '</span><span class="tag">' . WebView::e($club['name'] ?? 'Free Agent') . '</span><span class="tag">' . WebView::e(CareerLabels::value($summary['current_role'] ?? null)) . '</span><span class="tag">' . WebView::e(CareerLabels::value($summary['career_phase'] ?? null, 'Active Career')) . '</span></div></div></div>';
        $seasonBody = '<div class="stat-grid">' . WebView::stat('Appearances', $season['appearances'] ?? 0) . WebView::stat('Starts', $season['starts'] ?? 0) . WebView::stat('Minutes', $season['minutes'] ?? 0) . WebView::stat('Goals', $season['goals'] ?? 0) . WebView::stat('Assists', $season['assists'] ?? 0) . WebView::stat('Rating', $this->rating($season['average_match_rating'] ?? null)) . '</div><div class="metric-lines"><p><strong>Recent form</strong> ' . WebView::e($this->formLabel($form)) . '</p><p><strong>Season performance</strong> ' . WebView::e(CareerLabels::value($performance['classification'] ?? null, 'Not enough evidence')) . '</p></div>';
        $financialContext = (array) ($finance['financial_context'] ?? []);
        $availability = (string) ($summary['availability'] ?? 'available');
        $injury = is_array($summary['active_injury'] ?? null) ? $summary['active_injury'] : null;
        $availabilityText = match ($availability) {
            'unavailable' => $injury === null ? 'Unavailable — recovery required' : 'Unavailable — injured until ' . (string) ($injury['recovery_date'] ?? 'recovery'),
            'limited' => 'Limited availability',
            default => 'Available',
        };
        $readiness = (array) ($summary['readiness'] ?? []);
        $readinessLabel = CareerLabels::value($readiness['label'] ?? null, 'Ready');
        $readinessPanel = '<div class="stat-grid compact">' . WebView::stat('Status', $readinessLabel) . WebView::stat('Workload', ((int) ($readiness['fatigue'] ?? 0)) . '/100') . WebView::stat('Approach', CareerLabels::value($summary['training_intensity'] ?? 'normal')) . '</div><p class="muted">' . WebView::e((string) ($readiness['description'] ?? 'Available for the next football block.')) . '</p>';
        $managerContext = (array) ($summary['manager_context'] ?? []);
        $positionContext = (array) ($summary['position_development'] ?? []);
        $positionFocus = ($positionContext['developing_position'] ?? null) === null ? '' : ' · Learning ' . CareerLabels::position($positionContext['developing_position']) . ' (' . (int) ($positionContext['progress'] ?? 0) . '%)';
        $situation = '<div class="split-list"><p><span>Availability</span><strong>' . WebView::e($availabilityText) . '</strong></p><p><span>Readiness</span><strong>' . WebView::e($readinessLabel) . '</strong></p><p><span>Training focus</span><strong>' . WebView::e(CareerLabels::value($summary['training_focus'] ?? 'balanced')) . '</strong></p><p><span>Career priority</span><strong>' . WebView::e(CareerLabels::value($summary['priority'] ?? 'balanced')) . '</strong></p><p><span>Contract</span><strong>' . WebView::e($this->contractText($summary['current_contract'] ?? null)) . '</strong></p><p><span>Career outlook</span><strong>' . WebView::e(CareerLabels::value(((array) ($summary['career_outlook'] ?? []))['category'] ?? null, 'Not available')) . '</strong></p><p><span>Balance</span><strong>' . WebView::e($this->money((int) ($finance['balance'] ?? 0))) . '</strong></p><p><span>Lifestyle</span><strong>' . WebView::e($financialContext['label'] ?? 'Starting out') . '</strong></p></div><p class="muted">' . WebView::e($financialContext['description'] ?? '') . '</p>';
        if ($positionFocus !== '') {
            $situation = str_replace('</div><p class="muted">', '<p><span>Position development</span><strong>' . WebView::e(CareerLabels::position($positionContext['developing_position'])) . ' (' . (int) ($positionContext['progress'] ?? 0) . '%)</strong></p></div><p class="muted">', $situation);
        }
        $competitors = [];
        foreach ((array) (($summary['position_competition']['competitors'] ?? [])) as $competitor) {
            if (is_array($competitor)) { $competitors[] = (string) ($competitor['name'] ?? 'Teammate') . ' (' . CareerLabels::value($competitor['role'] ?? null, 'Squad') . ', OVR ' . (int) ($competitor['overall'] ?? 0) . ')'; }
        }
        $competitionDetail = $competitors === [] ? 'No direct same-position competitor currently listed.' : 'Closest competition: ' . implode(' · ', array_slice($competitors, 0, 3));
        $managerPanel = '<div class="stat-grid compact">' . WebView::stat('Football trust', CareerLabels::value($managerContext['trust_label'] ?? null, 'Not available')) . WebView::stat('Competition', CareerLabels::value($managerContext['competition_status'] ?? null, 'Not available')) . WebView::stat('Minutes', CareerLabels::value($managerContext['playing_time_status'] ?? null, 'Not enough evidence')) . '</div><p><strong>Expected usage:</strong> ' . WebView::e((string) ($managerContext['expected_usage'] ?? 'No established expectation')) . '</p><p>' . WebView::e($competitionDetail) . '</p><p class="muted">' . WebView::e((string) ($managerContext['feedback'] ?? 'Selection context follows role, form, readiness and competition.')) . '</p>';
        $nextLabel = (string) ($next['competition'] ?? 'Fixture');
        if (($next['round'] ?? null) !== null) { $nextLabel .= ' · ' . (string) $next['round']; }
        $nextBody = $next === null ? WebView::emptyState('No upcoming fixture currently scheduled.') : '<div class="fixture-card"><span class="eyebrow">' . WebView::e($nextLabel) . '</span><strong>' . WebView::e($next['home_club'] ?? '') . ' <span>vs</span> ' . WebView::e($next['away_club'] ?? '') . '</strong><small>' . WebView::e($next['date'] ?? '') . '</small></div>';
        $international = (array) ($summary['international'] ?? []);
        $social = (array) ($summary['social'] ?? []);
        $socialBody = '<div class="split-list"><p><span>Public profile</span><strong>' . WebView::e($social['public_profile_label'] ?? 'Unknown') . '</strong></p><p><span>Club standing</span><strong>' . WebView::e($social['club_standing_label'] ?? 'New Arrival') . '</strong></p><p><span>Supporters</span><strong>' . WebView::e($social['supporter_sentiment'] ?? 'Neutral') . '</strong></p><p><span>Manager relationship</span><strong>' . WebView::e($social['manager_relationship'] ?? 'Professional') . '</strong></p></div><p>' . WebView::link('relationships', ['save' => $saveId], 'View relationships', 'button button-secondary') . '</p>';
        $pulse = (array) ($summary['pulse'] ?? []);
        $pulseFeed = '';
        foreach ((array) ($summary['pulse_feed'] ?? []) as $item) { if (is_array($item)) { $pulseFeed .= '<li><strong>' . WebView::e($item['actor_name'] ?? 'Football world') . '</strong> ' . WebView::e($item['text'] ?? '') . '</li>'; } }
        $pulseBody = '<div class="stat-grid compact">' . WebView::stat('Audience', $pulse['audience_band'] ?? 'Local Following') . WebView::stat('Following', $pulse['followers_label'] ?? '120') . '</div>' . ($pulseFeed === '' ? WebView::emptyState('Your football story is only starting to get noticed.') : '<ul class="fixture-list">' . $pulseFeed . '</ul>') . '<p>' . WebView::link('pulse', ['save' => $saveId], 'Open Pulse', 'button button-secondary') . '</p>';
        $internationalBody = '<div class="split-list"><p><span>Country</span><strong>' . WebView::e($international['country'] ?? $summary['player']['primary_nation_id'] ?? '') . '</strong></p><p><span>Selection</span><strong>' . WebView::e(CareerLabels::value($international['selection_status'] ?? 'not_selected')) . '</strong></p><p><span>Caps</span><strong>' . WebView::e((int) (($international['stats']['caps'] ?? 0))) . '</strong></p></div>';
        $clubBody = $clubContext === null ? WebView::emptyState('League position is not available for this fixture context.') : '<div class="stat-grid compact">' . WebView::stat('Position', $clubContext['position'] ?? '—') . WebView::stat('Played', $clubContext['played'] ?? 0) . WebView::stat('Points', $clubContext['points'] ?? 0) . '</div>';
        $clubSeasonBody = $clubSeason === null
            ? WebView::emptyState('Club Season stakes are not available while you are without a Club.')
            : '<div class="stat-grid compact">'
                . WebView::stat('Expectation', CareerLabels::value($clubSeason['expectation'] ?? null, 'Stable Season'))
                . WebView::stat('Progress', CareerLabels::value($clubSeason['progress'] ?? null, 'Not available'))
                . WebView::stat('Pressure', CareerLabels::value($clubSeason['pressure'] ?? null, 'Normal'))
                . '</div><p class="muted">'
                . WebView::e(CareerLabels::value($clubSeason['season']['phase'] ?? null, 'Current Season'))
                . ' · League position ' . WebView::e($clubSeason['league']['position'] ?? '—')
                . '</p>';
        if ($clubSeason !== null && (($clubSeason['important_fixtures'] ?? []) !== [])) {
            $important = (array) $clubSeason['important_fixtures'][0];
            $clubSeasonBody .= '<p><strong>Important next fixture:</strong> ' . WebView::e(($important['date'] ?? '') . ' · ' . ($important['opponent'] ?? 'Opponent') . ' · ' . CareerLabels::value($important['reason'] ?? null, 'Season stakes')) . '</p>';
        }
        foreach ([['label' => 'Cup', 'key' => 'cup'], ['label' => 'Europe', 'key' => 'europe']] as $knockout) {
            $context = is_array($clubSeason[$knockout['key']] ?? null) ? $clubSeason[$knockout['key']] : null;
            if ($context !== null) {
                $clubSeasonBody .= '<p><strong>' . $knockout['label'] . ':</strong> ' . WebView::e($context['competition'] ?? $knockout['label']) . ' · ' . WebView::e(CareerLabels::value($context['status'] ?? null, 'Not started')) . '</p>';
            }
        }
        $actions = '<div class="action-grid">' . $this->primaryCareerAction($saveId, $summary, $session, $next) . WebView::link('career', ['save' => $saveId], 'Career') . WebView::link('squad', ['save' => $saveId], 'Squad') . WebView::link('world', ['save' => $saveId], 'World') . WebView::link('news', ['save' => $saveId], 'News') . WebView::link('pulse', ['save' => $saveId], 'Pulse') . WebView::link('relationships', ['save' => $saveId], 'Relationships') . WebView::link('training', ['save' => $saveId], 'Training') . WebView::link('finances', ['save' => $saveId], 'Finances') . WebView::link('lifestyle', ['save' => $saveId], 'Lifestyle') . '</div>';
        $actions .= $this->contextActions($saveId, $summary) . WebView::form('save_exit', 'Save & Exit', ['save' => $saveId], 'button button-secondary', 'data-busy');
        $body = $profile . '<div class="dashboard-grid"><div class="dashboard-main">' . WebView::section('CURRENT SEASON', $snapshot['summary']['current_season_label'] ?? 'Current Season', $seasonBody) . WebView::section('CLUB SEASON', 'What the Club is trying to achieve', $clubSeasonBody) . WebView::section('READINESS', 'Between Matches', $readinessPanel) . WebView::section('MANAGER / SQUAD STATUS', 'Your place in the team', $managerPanel) . WebView::section('NEXT MATCH', 'What is coming next', $nextBody) . WebView::section('CLUB', $club['name'] ?? 'Free Agent', $clubBody) . WebView::section('INTERNATIONAL DUTY', 'National-team context', $internationalBody) . WebView::section('PUBLIC CONTEXT', 'Football reputation', $socialBody) . WebView::section('PULSE', 'Trending on Pulse', $pulseBody) . '</div><aside class="dashboard-side">' . WebView::section('CAREER SITUATION', 'Your direction', $situation) . WebView::section('ACTIONS', 'Play', $actions) . '</aside></div>';

        return $this->html('Career Home', $body, $saveId, 'home', 200, $session);
    }

    private function career(string $saveId, array &$session): array
    {
        $snapshot = $this->snapshot($saveId, $this->database($saveId));
        $summary = $snapshot['summary'];
        $player = (array) ($summary['player'] ?? []);
        $history = '';
        foreach ((array) ($summary['season_history'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $history .= '<tr><td>' . WebView::e($row['season'] ?? '') . '</td><td>' . WebView::e($row['club']['name'] ?? 'Free Agent') . '</td><td>' . WebView::e($row['competition']['name'] ?? '') . '</td><td>' . WebView::e($row['appearances'] ?? 0) . '</td><td>' . WebView::e($row['starts'] ?? 0) . '</td><td>' . WebView::e($row['minutes'] ?? 0) . '</td><td>' . WebView::e($row['goals'] ?? 0) . '</td><td>' . WebView::e($row['assists'] ?? 0) . '</td><td>' . WebView::e($this->rating($row['average_match_rating'] ?? null)) . '</td></tr>';
        }
        $history = $history === '' ? WebView::emptyState('No Season history yet.') : '<div class="table-scroll"><table><thead><tr><th>Season</th><th>Club</th><th>Competition</th><th>Apps</th><th>Starts</th><th>Minutes</th><th>Goals</th><th>Assists</th><th>Rating</th></tr></thead><tbody>' . $history . '</tbody></table></div>';
        $cupHistory = '';
        foreach ((array) ($summary['cup_history'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $status = (string) ($row['club_status'] ?? 'active');
            $statusLabel = $status === 'eliminated' ? 'Eliminated' : (($row['status'] ?? '') === 'completed' ? 'Completed' : 'In progress');
            $cupHistory .= '<li><strong>' . WebView::e($row['season_id'] ?? '') . '</strong> · ' . WebView::e($row['competition_name'] ?? 'Domestic Cup') . ' · ' . WebView::e($statusLabel) . '</li>';
        }
        $cupHistory = $cupHistory === '' ? WebView::emptyState('No domestic cup history yet.') : '<ul class="timeline">' . $cupHistory . '</ul>';
        $europeHistory = '';
        foreach ((array) ($summary['europe_history'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $status = (string) ($row['club_status'] ?? 'active');
            $statusLabel = $status === 'eliminated' ? 'Eliminated' : (($row['status'] ?? '') === 'completed' ? 'Completed' : 'In progress');
            $europeHistory .= '<li><strong>' . WebView::e($row['season_id'] ?? '') . '</strong> · ' . WebView::e($row['competition_name'] ?? 'European Competition') . ' · ' . WebView::e($statusLabel) . '</li>';
        }
        $europeHistory = $europeHistory === '' ? WebView::emptyState('No European history yet.') : '<ul class="timeline">' . $europeHistory . '</ul>';
        $movement = '';
        foreach ((array) ($summary['movement_history'] ?? []) as $row) { if (is_array($row)) { $movement .= '<li><strong>' . WebView::e($row['date'] ?? '') . '</strong> ' . WebView::e($row['type'] ?? 'Career movement') . ' · ' . WebView::e($row['club']['name'] ?? $row['to_club'] ?? '') . '</li>'; } }
        $movement = $movement === '' ? WebView::emptyState('No movement recorded yet.') : '<ul class="timeline">' . $movement . '</ul>';
        $positionHistory = '';
        foreach ((array) ($summary['position_history'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $positionHistory .= '<li><strong>' . WebView::e($row['occurred_date'] ?? '') . '</strong> ' . WebView::e(CareerLabels::position($row['from_position'] ?? null)) . ' → ' . WebView::e(CareerLabels::position($row['to_position'] ?? null)) . '</li>';
        }
        $positionHistory = $positionHistory === '' ? WebView::emptyState('No permanent position changes yet.') : '<ul class="timeline">' . $positionHistory . '</ul>';
        $life = '';
        foreach ((array) ($summary['career_life_history'] ?? []) as $row) { if (is_array($row)) { $life .= '<li><strong>' . WebView::e($row['date'] ?? '') . '</strong> ' . WebView::e(((array) ($row['consequence'] ?? []))['history'] ?? $row['title'] ?? 'Career moment') . '</li>'; } }
        $life = $life === '' ? WebView::emptyState('No off-pitch milestones yet.') : '<ul class="timeline">' . $life . '</ul>';
        $socialHistory = '';
        foreach ((array) ($summary['social_history'] ?? []) as $row) { if (is_array($row)) { $socialHistory .= '<li><strong>' . WebView::e($row['event_date'] ?? '') . '</strong> ' . WebView::e($row['headline'] ?? 'Football social milestone') . '</li>'; } }
        $socialHistory = $socialHistory === '' ? WebView::emptyState('No public or relationship milestones yet.') : '<ul class="timeline">' . $socialHistory . '</ul>';
        $body = '<div class="page-heading"><div><div class="eyebrow">CAREER</div><h1>' . WebView::e($player['preferred_name'] ?? 'Player') . '</h1><p>' . WebView::e(CareerLabels::position($player['primary_position'] ?? null)) . ' · OVR ' . WebView::e($summary['current_ovr'] ?? '—') . '</p></div>' . WebView::portrait($this->portraitUrl($saveId, (string) ($player['id'] ?? ''), 'career', 128), 'Player portrait', 'portrait portrait-medium') . '</div>';
        $body .= '<div class="dashboard-grid"><div class="dashboard-main">' . WebView::section('CURRENT SEASON', $snapshot['summary']['current_season_label'] ?? 'Current Season', $this->seasonFacts($summary)) . WebView::section('SEASON HISTORY', 'Record', $history) . WebView::section('DOMESTIC CUP HISTORY', 'Knockout record', $cupHistory) . WebView::section('EUROPEAN HISTORY', 'Continental record', $europeHistory) . '</div><aside class="dashboard-side">' . WebView::section('MOVEMENT HISTORY', 'Clubs', $movement) . WebView::section('POSITION HISTORY', 'Permanent football evolution', $positionHistory) . WebView::section('OFF-PITCH LIFE', 'Milestones', $life) . WebView::section('FOOTBALL SOCIAL HISTORY', 'Public milestones', $socialHistory) . WebView::section('PROFILE', 'Appearance', WebView::link('appearance', ['save' => $saveId], 'Customize appearance', 'button button-secondary')) . '</aside></div>';

        return $this->html('Career', $body, $saveId, 'career', 200, $session);
    }

    private function legacy(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = (new CareerPresentationService($this->services))->snapshot($database, $saveId, true);
        $summary = $snapshot['summary'];
        $player = (array) ($summary['player'] ?? []);
        $legacy = is_array($summary['legacy'] ?? null) ? $summary['legacy'] : [];
        $clubStats = is_array($legacy['club_stats'] ?? null) ? $legacy['club_stats'] : [];
        $international = is_array($legacy['international_stats'] ?? null) ? $legacy['international_stats'] : [];
        $retirement = is_array($legacy['retirement'] ?? null) ? $legacy['retirement'] : null;
        $span = is_array($legacy['career_span'] ?? null) ? $legacy['career_span'] : [];
        $finalClubId = (string) ($retirement['final_club_id'] ?? '');
        $finalClub = 'Free Agent';
        if ($finalClubId !== '' && $finalClubId !== 'free-agent') {
            $clubRepository = $this->services->clubModule()->service()->repository($database);
            $finalClub = $clubRepository->exists($finalClubId) ? $clubRepository->get($finalClubId)->canonicalName() : 'Historical Club';
        }
        $retirementPanel = $retirement === null ? '' : WebView::section('CAREER COMPLETE', 'Retired playing Career', '<p><strong>RETIRED</strong> · Final Club: ' . WebView::e($finalClub) . ' · Retirement Season: ' . WebView::e($span['retirement'] ?? $retirement['retirement_season_id'] ?? 'Recorded') . '</p><p>Career span: ' . WebView::e($span['start'] ?? 'Recorded') . ' to ' . WebView::e($span['retirement'] ?? $span['latest'] ?? 'Recorded') . ' · ' . WebView::e($span['seasons_played'] ?? 0) . ' Seasons played.</p>');
        $overview = '<div class="stat-grid"><div class="stat"><span>Club appearances</span><strong>' . WebView::e($clubStats['appearances'] ?? 0) . '</strong></div><div class="stat"><span>Club goals</span><strong>' . WebView::e($clubStats['goals'] ?? 0) . '</strong></div><div class="stat"><span>Club assists</span><strong>' . WebView::e($clubStats['assists'] ?? 0) . '</strong></div><div class="stat"><span>International caps</span><strong>' . WebView::e($international['caps'] ?? 0) . '</strong></div><div class="stat"><span>International goals</span><strong>' . WebView::e($international['goals'] ?? 0) . '</strong></div></div>';
        $clubs = (array) ($legacy['clubs'] ?? []);
        $clubBody = $clubs === [] ? WebView::emptyState('No Club history yet.') : '<ul class="timeline">' . implode('', array_map(static fn (array $club): string => '<li>' . WebView::e($club['name'] ?? 'Club') . '</li>', $clubs)) . '</ul>';
        $honours = (array) ($legacy['honours'] ?? []);
        $honourBody = $honours === [] ? WebView::emptyState('No earned honours yet. Participation-based honours appear after completed competitions.') : '<ul class="timeline">' . implode('', array_map(static fn (array $row): string => '<li><strong>' . WebView::e($row['season_id'] ?? '') . '</strong> · ' . WebView::e($row['label'] ?? 'Honour') . '</li>', $honours)) . '</ul>';
        $awards = (array) ($legacy['awards'] ?? []);
        $awardBody = $awards === [] ? WebView::emptyState('No individual awards yet. Awards resolve from completed domestic League evidence.') : '<ul class="timeline">' . implode('', array_map(static fn (array $row): string => '<li><strong>' . WebView::e($row['season_id'] ?? '') . '</strong> · ' . WebView::e(CareerLabels::value($row['award_type'] ?? null)) . ' · ' . WebView::e(($row['evidence']['competition_name'] ?? 'League')) . '</li>', $awards)) . '</ul>';
        $records = (array) ($legacy['records'] ?? []);
        $recordBody = $records === [] ? WebView::emptyState('No personal bests recorded yet.') : '<ul class="timeline">' . implode('', array_map(static fn (array $row): string => '<li>' . WebView::e(CareerLabels::value($row['metric'] ?? null)) . ' · <strong>' . WebView::e($row['value'] ?? 0) . '</strong></li>', $records)) . '</ul>';
        $milestones = (array) ($legacy['milestones'] ?? []);
        $milestoneBody = $milestones === [] ? WebView::emptyState('No numeric milestones reached yet.') : '<ul class="timeline">' . implode('', array_map(static fn (array $row): string => '<li><strong>' . WebView::e($row['occurred_date'] ?? '') . '</strong> · ' . WebView::e($row['label'] ?? 'Milestone') . '</li>', $milestones)) . '</ul>';
        $body = '<div class="page-heading"><div><div class="eyebrow">CAREER LEGACY</div><h1>' . WebView::e($player['preferred_name'] ?? 'Player') . '</h1><p>Descriptive achievement derived from canonical football facts.</p></div>' . WebView::portrait($this->portraitUrl($saveId, (string) ($player['id'] ?? ''), 'career', 128), 'Player portrait', 'portrait portrait-medium') . '</div>';
        $body .= WebView::section('CAREER OVERVIEW', 'The record so far', $overview) . '<div class="dashboard-grid"><div class="dashboard-main">' . WebView::section('HONOURS', 'Competition achievements', $honourBody) . WebView::section('INDIVIDUAL AWARDS', 'Season recognition', $awardBody) . WebView::section('RECORDS & PERSONAL BESTS', 'Career-era evidence', $recordBody) . '</div><aside class="dashboard-side">' . WebView::section('CLUBS', 'Career chapters', $clubBody) . WebView::section('MILESTONES', 'Defining numbers', $milestoneBody) . WebView::section('INTERNATIONAL CAREER', 'National-team record', '<p>' . WebView::e($international['caps'] ?? 0) . ' caps · ' . WebView::e($international['goals'] ?? 0) . ' goals</p>' . WebView::link('international', ['save' => $saveId], 'Open International', 'button button-secondary')) . '</aside></div>';

        $body = $retirementPanel . $body;
        return $this->html('Career Legacy', $body, $saveId, 'legacy', 200, $session);
    }

    private function market(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = (new CareerPresentationService($this->services))->snapshot($database, $saveId, false);
        $summary = $snapshot['summary'];
        if (($summary['career_state'] ?? 'active') === 'retired') {
            $body = '<div class="page-heading"><div><div class="eyebrow">TRANSFER MARKET</div><h1>Career complete</h1><p>The playing Career is retired. No new playing offers or transfer requests are available.</p></div></div>' . WebView::section('MARKET STATUS', 'Read-only', WebView::emptyState('Your final Club, Contracts, and Career movement remain available in Legacy and Career History.')) . '<div class="form-actions">' . WebView::link('legacy', ['save' => $saveId], 'Open Career Legacy', 'button button-primary') . WebView::link('home', ['save' => $saveId], 'Career Home') . '</div>';
            return $this->html('Transfer Market', $body, $saveId, 'market', 200, $session);
        }
        $market = (array) ($summary['market'] ?? []);
        $playerId = (string) (($summary['player']['id'] ?? ''));
        $opportunities = $playerId === '' ? [] : (new CareerOpportunityRepository($database))->openForPlayer(new \Goal\Legacy\Modules\Player\Domain\PlayerId($playerId), $snapshot['date']);
        $cards = '';
        foreach ($opportunities as $opportunity) {
            if ($opportunity->type()->value !== 'transfer_interest') { continue; }
            $context = $opportunity->context();
            if (($context['decision_kind'] ?? null) === 'controlled_transfer') {
                $options = '';
                foreach ((array) ($context['options'] ?? []) as $option) {
                    if (($option['kind'] ?? '') !== 'accept_transfer') { continue; }
                    $reasons = implode(', ', array_map(static fn (mixed $reason): string => ucwords(str_replace('_', ' ', (string) $reason)), (array) ($option['reasons'] ?? [])));
                    $options .= '<li><strong>' . WebView::e($option['club_id'] ?? 'Club') . '</strong> · ' . WebView::e(CareerLabels::value($option['role'] ?? null)) . ' · ' . WebView::e($this->money((int) ($option['wage'] ?? 0))) . '/week' . ($reasons === '' ? '' : ' · ' . WebView::e($reasons)) . '</li>';
                }
                $cards .= WebView::section('TRANSFER INTEREST', 'A Club has opened a Career choice', ($options === '' ? WebView::emptyState('No valid offer remains in this decision.') : '<ul class="fixture-list">' . $options . '</ul>') . WebView::link('decision', ['save' => $saveId], 'Review Career decision', 'button button-primary'));
                continue;
            }
            $target = $opportunity->targetClubId()?->value() ?? 'Club';
            $cards .= WebView::section('TRANSFER OFFER', $target, '<p>' . WebView::e($context['target_club_level'] ?? 'A Club opportunity') . ' · ' . WebView::e(CareerLabels::value($context['proposed_role'] ?? null)) . ' · ' . WebView::e($this->money((int) ($context['wage'] ?? 0))) . '/week</p>' . WebView::form('accept_transfer_offer', 'Accept offer', ['save' => $saveId, 'offer_id' => $opportunity->id(), 'token' => $this->issueToken($session, 'offer_' . $saveId . '_' . $opportunity->id())], 'button button-primary', 'data-busy'));
        }
        if ($cards === '') { $cards = WebView::emptyState('No actionable transfer offers are currently available. Requesting a transfer increases search intent during the valid window, but does not create guaranteed offers.'); }
        $request = (array) ($summary['transfer_request'] ?? []);
        $requestText = ($request['status'] ?? 'none') === 'requested' ? 'Transfer request active.' : 'No active transfer request.';
        $body = '<div class="page-heading"><div><div class="eyebrow">TRANSFER MARKET</div><h1>Career mobility</h1><p>' . WebView::e($requestText) . '</p></div></div>'
            . WebView::section('MARKET CONTEXT', 'Derived from canonical football evidence', '<div class="stat-grid compact">' . WebView::stat('Market stature', $market['label'] ?? 'Unknown') . WebView::stat('OVR', $market['overall'] ?? '—') . WebView::stat('Age', $market['age'] ?? '—') . WebView::stat('Recent form', $market['recent_form'] ?? 0) . WebView::stat('Club level', $market['current_club_level'] ?? 'Free Agent') . WebView::stat('International caps', $market['international_caps'] ?? 0) . '</div><p class="metric-note">Interest considers playing evidence, role, Club need, competition level, recognition, Contract context, and bounded age/potential signals.</p>')
            . $cards . '<div class="form-actions">' . WebView::link('profile', ['save' => $saveId, 'player' => $playerId], 'Player Profile') . WebView::link('home', ['save' => $saveId], 'Career Home') . '</div>';

        return $this->html('Transfer Market', $body, $saveId, 'market', 200, $session);
    }

    private function existingAppearance(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $player = (new PlayerRepository($database))->get($career->playerId());
        $appearance = (new PlayerAppearanceService())->getOrGenerate($database, $player, $world->currentDate($worldService->calendar()));
        $session[self::DRAFT] = ['appearance' => $appearance->toArray(), 'existing_save' => $saveId];
        return $this->html('Update Appearance', $this->newAppearanceView($session[self::DRAFT]), $saveId, 'career', 200, $session);
    }

    private function squad(string $saveId, array &$session, ?string $requestedClubId = null): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $summary = $snapshot['summary'];
        $controlledClubId = (string) (((array) ($summary['current_club'] ?? []))['id'] ?? '');
        $clubId = $requestedClubId !== null && $requestedClubId !== '' ? $requestedClubId : $controlledClubId;
        $seasonId = (string) ($summary['current_season_id'] ?? '');
        $season = $seasonId === '' ? null : new SeasonId($seasonId);
        $groups = ['Goalkeepers' => [], 'Defenders' => [], 'Midfielders' => [], 'Forwards' => []];
        if ($clubId !== '') {
            $memberships = $this->services->clubModule()->service()->squadRepository($database)->byClub($clubId, $season);
            $roles = [];
            foreach ($memberships as $membership) { $roles[$membership->playerId()->value()] = $membership->role()->value; }
            $aggregates = [];
            if ($season !== null) {
                foreach ((new PlayerSeasonStatisticsRepository($database))->bySeason($season) as $row) {
                    if ((string) ($row['club_id'] ?? '') === $clubId) { $aggregates[(string) $row['player_id']] = $row; }
                }
            }
            foreach ($this->services->playerModule()->service()->byClub($database, $clubId, $season) as $player) {
                $groups[$this->positionGroup($player->primaryPosition())][] = [$player, $roles[$player->id()->value()] ?? null, $aggregates[$player->id()->value()] ?? null];
            }
        }
        $clubName = $clubId === '' ? 'Free Agent' : $this->services->clubModule()->service()->repository($database)->get($clubId)->canonicalName();
        $groupsHtml = '';
        foreach ($groups as $label => $players) {
            if ($players === []) { continue; }
            $cards = '';
            foreach ($players as [$player, $role, $aggregate]) {
                $meta = CareerLabels::position($player->primaryPosition()->value) . ' · OVR ' . $player->overallRating() . ' · ' . $player->ageAt($snapshot['date']);
                $context = $role === null ? '' : CareerLabels::value($role);
                if (is_array($aggregate)) { $context .= ($context === '' ? '' : ' · ') . 'Apps ' . (int) ($aggregate['appearances'] ?? 0) . ' · G ' . (int) ($aggregate['goals'] ?? 0); }
                $cards .= WebView::playerCard(WebView::url('profile', ['save' => $saveId, 'player' => $player->id()->value()]), $this->portraitUrl($saveId, $player->id()->value(), 'club', 128), $player->preferredName(), $meta, $context);
            }
            $groupsHtml .= WebView::section('SQUAD', $label, '<div class="squad-grid">' . $cards . '</div>', 'squad-group');
        }
        $back = $requestedClubId !== null && $requestedClubId !== '' ? WebView::link('club', ['save' => $saveId, 'club' => $clubId], 'Back to Club') : '';
        $body = '<div class="page-heading"><div><div class="eyebrow">SQUAD</div><h1>' . WebView::e($clubName) . '</h1><p>Current Players and their compact Season context.</p></div>' . $back . '</div>' . ($groupsHtml === '' ? WebView::emptyState('There is no current Club squad to display.') : $groupsHtml);

        return $this->html('Squad', $body, $saveId, 'squad', 200, $session);
    }

    private function profile(string $saveId, string $playerId, array &$session): array
    {
        if ($playerId === '') { return $this->redirect(WebView::url('squad', ['save' => $saveId])); }
        $database = $this->database($saveId);
        $data = (new CareerPresentationService($this->services))->playerProfile($database, $saveId, $playerId);
        $player = $data['player'];
        $club = $data['club'];
        $stats = (array) ($data['season_stats'] ?? []);
        $cupStats = is_array($data['cup_stats'] ?? null) ? $data['cup_stats'] : null;
        $europeStats = is_array($data['europe_stats'] ?? null) ? $data['europe_stats'] : [];
        $internationalStats = (array) ($data['international_stats'] ?? []);
        $internationalContext = (array) ($data['international'] ?? []);
        $careerStats = (array) ($data['career_stats'] ?? []);
        $form = (array) ($data['recent_form'] ?? []);
        $market = is_array($data['market'] ?? null) ? $data['market'] : [];
        $finance = ($data['controlled'] ?? false) === true ? $this->services->playerFinanceService()->summary($database, $playerId, $this->snapshot($saveId, $database)['date']) : null;
        $clubLink = $club === null ? 'Free Agent' : WebView::link('club', ['save' => $saveId, 'club' => $club->id()->value()], $club->canonicalName(), 'text-link');
        $positionDevelopment = (array) ($data['position_development'] ?? []);
        $secondaryLabel = ($positionDevelopment['secondary_positions'] ?? []) === [] ? 'None' : implode(', ', array_map(static fn (mixed $value): string => CareerLabels::position(is_string($value) ? $value : null), (array) $positionDevelopment['secondary_positions']));
        $facts = '<div class="stat-grid compact">' . WebView::stat('OVR', $player->overallRating()) . WebView::stat('Age', $data['age']) . WebView::stat('Position', CareerLabels::position($player->primaryPosition()->value)) . WebView::stat('Secondary', $secondaryLabel) . WebView::stat('Nationality', $data['nationality']) . WebView::stat('Role', CareerLabels::value($data['role'] ?? null, 'Not assigned')) . WebView::stat('Career phase', CareerLabels::value($data['career_phase'] ?? null, 'Active')) . WebView::stat('Status', CareerLabels::value($data['career_state'] ?? 'active')) . WebView::stat('Market stature', $market['label'] ?? 'Unknown') . WebView::stat('Season', $data['season_id']) . '</div>';
        $seasonLine = '<div class="stat-grid compact">' . WebView::stat('Appearances', $stats['appearances'] ?? 0) . WebView::stat('Starts', $stats['starts'] ?? 0) . WebView::stat('Minutes', $stats['minutes'] ?? 0) . WebView::stat('Goals', $stats['goals'] ?? 0) . WebView::stat('Assists', $stats['assists'] ?? 0) . WebView::stat('Rating', $this->rating($stats['average_match_rating'] ?? null)) . '</div>';
        $extras = '<p><strong>Recent form:</strong> ' . WebView::e($this->formLabel($form)) . '</p>';
        $cupPanel = '';
        if ($cupStats !== null && ((int) ($cupStats['appearances'] ?? 0) > 0 || (int) ($cupStats['minutes'] ?? 0) > 0)) {
            $cupPanel = WebView::section('DOMESTIC CUP', 'Current Season', '<div class="stat-grid compact">' . WebView::stat('Appearances', $cupStats['appearances'] ?? 0) . WebView::stat('Starts', $cupStats['starts'] ?? 0) . WebView::stat('Minutes', $cupStats['minutes'] ?? 0) . WebView::stat('Goals', $cupStats['goals'] ?? 0) . WebView::stat('Assists', $cupStats['assists'] ?? 0) . WebView::stat('Rating', $this->rating($cupStats['average_match_rating'] ?? null)) . '</div>');
        }
        $europePanel = '';
        foreach ($europeStats as $competitionId => $europeStat) {
            if (!is_array($europeStat) || ((int) ($europeStat['appearances'] ?? 0) === 0 && (int) ($europeStat['minutes'] ?? 0) === 0)) { continue; }
            $europePanel .= WebView::section('EUROPE · ' . strtoupper(str_replace('-', ' ', (string) $competitionId)), 'Current Season', '<div class="stat-grid compact">' . WebView::stat('Appearances', $europeStat['appearances'] ?? 0) . WebView::stat('Starts', $europeStat['starts'] ?? 0) . WebView::stat('Minutes', $europeStat['minutes'] ?? 0) . WebView::stat('Goals', $europeStat['goals'] ?? 0) . WebView::stat('Assists', $europeStat['assists'] ?? 0) . WebView::stat('Rating', $this->rating($europeStat['average_match_rating'] ?? null)) . '</div>');
        }
        $internationalPanel = WebView::section('INTERNATIONAL', (string) ($internationalContext['country'] ?? $data['nationality'] ?? 'National Team'), '<p><strong>Selection:</strong> ' . WebView::e(CareerLabels::value($internationalContext['selection_status'] ?? 'not_selected')) . '</p><div class="stat-grid compact">' . WebView::stat('Caps', $internationalStats['caps'] ?? 0) . WebView::stat('Starts', $internationalStats['starts'] ?? 0) . WebView::stat('Minutes', $internationalStats['minutes'] ?? 0) . WebView::stat('Goals', $internationalStats['goals'] ?? 0) . WebView::stat('Assists', $internationalStats['assists'] ?? 0) . WebView::stat('Rating', $this->rating($internationalStats['average_rating'] ?? null)) . '</div>');
        $social = (array) ($data['social'] ?? []);
        $managerContext = (array) ($data['manager_context'] ?? []);
        $clubSeason = is_array($data['club_season'] ?? null) ? $data['club_season'] : null;
        $positionPanel = ($data['controlled'] ?? false) !== true ? '' : '<p><strong>Position development:</strong> ' . WebView::e($secondaryLabel) . (($positionDevelopment['developing_position'] ?? null) === null ? '' : ' · learning ' . WebView::e(CareerLabels::position($positionDevelopment['developing_position'])) . ' (' . (int) ($positionDevelopment['progress'] ?? 0) . '%)') . '</p><p>' . WebView::link('training', ['save' => $saveId], 'Manage position development', 'button button-secondary') . '</p>';
        $clubSeasonPanel = $clubSeason === null ? '' : '<p><strong>Club Season:</strong> ' . WebView::e(CareerLabels::value($clubSeason['expectation'] ?? null)) . ' · ' . WebView::e(CareerLabels::value($clubSeason['progress'] ?? null)) . ' · pressure ' . WebView::e(CareerLabels::value($clubSeason['pressure'] ?? null)) . '</p>';
        $socialPanel = WebView::section('PUBLIC PROFILE', 'Football context', '<div class="stat-grid compact">' . WebView::stat('Public profile', $social['public_profile_label'] ?? 'Unknown') . WebView::stat('Club standing', $social['club_standing_label'] ?? 'New Arrival') . WebView::stat('Supporters', $social['supporter_sentiment'] ?? 'Neutral') . WebView::stat('Manager', $social['manager_relationship'] ?? 'Professional') . '</div>' . (($data['controlled'] ?? false) === true ? '<p><strong>Football trust:</strong> ' . WebView::e(CareerLabels::value($managerContext['trust_label'] ?? null, 'Not available')) . ' · <strong>Competition:</strong> ' . WebView::e(CareerLabels::value($managerContext['competition_status'] ?? null, 'Not available')) . '</p><p class="muted">' . WebView::e((string) ($managerContext['feedback'] ?? '')) . '</p>' : '') . $clubSeasonPanel . $positionPanel . WebView::link('relationships', ['save' => $saveId], 'Open relationships', 'button button-secondary'));
        $pulseContext = (array) ($data['pulse'] ?? []);
        $pulsePanel = ($data['controlled'] ?? false) === true ? WebView::section('PULSE', 'Public presence', '<div class="stat-grid compact">' . WebView::stat('Audience', $pulseContext['audience_band'] ?? 'Local Following') . WebView::stat('Following', $pulseContext['followers_label'] ?? '120') . '</div>' . WebView::link('pulse', ['save' => $saveId], 'Open Pulse', 'button button-secondary')) : '';
        if (($data['controlled'] ?? false) === true) {
            $financialContext = (array) ($finance['financial_context'] ?? []);
            $notable = array_values(array_filter((array) ($finance['owned'] ?? []), static fn (array $item): bool => LifestyleCatalog::tierRank((string) ($item['tier'] ?? '')) >= 3));
            $notableText = $notable === [] ? 'No major lifestyle assets yet.' : implode(' · ', array_map(static fn (array $item): string => (string) ($item['label'] ?? 'Lifestyle asset'), array_slice($notable, 0, 3)));
            $readiness = (array) ($data['readiness'] ?? []);
            $extras .= '<p><strong>Readiness:</strong> ' . WebView::e(CareerLabels::value($readiness['label'] ?? null, 'Ready')) . ' · <strong>Workload:</strong> ' . WebView::e(((int) ($readiness['fatigue'] ?? 0)) . '/100') . ' · <strong>Training intensity:</strong> ' . WebView::e(CareerLabels::value($data['training_intensity'] ?? 'normal')) . '</p><p><strong>Training focus:</strong> ' . WebView::e(CareerLabels::value($data['training_focus'] ?? null)) . ' · <strong>Priority:</strong> ' . WebView::e(CareerLabels::value($data['priority'] ?? null)) . ' · <strong>Contract:</strong> ' . WebView::e($this->contractText($data['contract'] ?? null)) . ' · <strong>Balance:</strong> ' . WebView::e($this->money((int) (($finance['balance'] ?? 0)))) . '</p><p><strong>Lifestyle:</strong> ' . WebView::e($financialContext['label'] ?? 'Starting out') . ' · <strong>Notable:</strong> ' . WebView::e($notableText) . '</p>';
            $extras .= '<p>' . WebView::link('lifestyle', ['save' => $saveId], 'View Lifestyle', 'button button-secondary') . '</p>';
        }
        $careerLine = '<div class="stat-grid compact">' . WebView::stat('Career apps', $careerStats['appearances'] ?? 0) . WebView::stat('Career goals', $careerStats['goals'] ?? 0) . WebView::stat('Career assists', $careerStats['assists'] ?? 0) . WebView::stat('Cards', ((int) ($careerStats['yellow_cards'] ?? 0)) . 'Y / ' . ((int) ($careerStats['red_cards'] ?? 0)) . 'R') . '</div>';
        $legacyPanel = '';
        if (($data['controlled'] ?? false) === true) {
            $legacy = is_array($data['legacy'] ?? null) ? $data['legacy'] : [];
            $legacyPanel = WebView::section('CAREER LEGACY', 'Historical achievement', '<div class="stat-grid compact">' . WebView::stat('Honours', count((array) ($legacy['honours'] ?? []))) . WebView::stat('Awards', count((array) ($legacy['awards'] ?? []))) . WebView::stat('Milestones', count((array) ($legacy['milestones'] ?? []))) . '</div>' . WebView::link('legacy', ['save' => $saveId], 'Open Career Legacy', 'button button-secondary'));
        }
        $history = '';
        foreach ((array) ($data['match_history'] ?? []) as $match) {
            if (!is_array($match)) { continue; }
            $line = ($match['date'] ?? '') . ' · ' . ($match['competition'] ?? 'Competition') . ' · ' . ($match['home'] ?? '') . ' ' . ($match['home_goals'] ?? 0) . '-' . ($match['away_goals'] ?? 0) . ' ' . ($match['away'] ?? '');
            if (($match['detailed'] ?? false) === true && $match['minutes'] !== null) { $line .= ' · ' . $match['minutes'] . ' min · Rating ' . $this->rating($match['rating'] ?? null) . ' · ' . ($match['goals'] ?? 0) . 'G ' . ($match['assists'] ?? 0) . 'A'; }
            $history .= '<li>' . WebView::e($line) . (isset($match['match_id']) && ($match['detailed'] ?? false) === true ? ' ' . WebView::link('matchday', ['save' => $saveId, 'match' => $match['match_id']], 'Open Match', 'text-link') : '') . '</li>';
        }
        $historyBody = $history === '' ? WebView::emptyState('No completed Match history in this Season.') : '<ul class="timeline compact-timeline">' . $history . '</ul>';
        $marketPanel = ($data['controlled'] ?? false) === true && ($data['career_state'] ?? 'active') !== 'retired' ? WebView::section('TRANSFER MARKET', 'Current context', '<p>' . WebView::e($market['label'] ?? 'Unknown') . ' · ' . WebView::e($market['current_club_level'] ?? 'Free Agent') . '</p>' . WebView::link('market', ['save' => $saveId], 'Open Transfer Market', 'button button-secondary')) : '';
        $body = '<div class="profile-hero profile-hero-profile">' . WebView::portrait($this->portraitUrl($saveId, $playerId, 'club', 256), $player->preferredName(), 'portrait portrait-large') . '<div><div class="eyebrow">PLAYER PROFILE</div><h1>' . WebView::e($player->preferredName()) . '</h1><p>' . WebView::e($data['age'] . ' years · ' . $data['nationality'] . ' · ') . $clubLink . '</p>' . $facts . '</div></div>' . WebView::section('CURRENT SEASON', 'All competitions', $seasonLine . $extras) . $marketPanel . $cupPanel . $europePanel . $internationalPanel . $socialPanel . $pulsePanel . WebView::section('CAREER TOTALS', 'Recorded career evidence', $careerLine) . $legacyPanel . WebView::section('MATCH HISTORY', 'Recent canonical results', $historyBody) . '<div class="form-actions">' . WebView::link('squad', ['save' => $saveId, 'club' => $club?->id()->value()], 'Back to Squad') . '</div>';

        return $this->html('Player Profile', $body, $saveId, 'squad', 200, $session);
    }

    private function world(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $world = (new CareerPresentationService($this->services))->world($database, $snapshot['summary'], $snapshot['date']);
        $standings = '';
        foreach ((array) ($world['standings'] ?? []) as $index => $row) {
            if (!is_array($row)) { continue; }
            $class = ($row['controlled'] ?? false) ? ' class="controlled-row"' : '';
            $clubLink = WebView::url('club', ['save' => $saveId, 'club' => $row['club_id'] ?? '']);
            $standings .= '<tr' . $class . '><td>' . ($index + 1) . '</td><td><a class="table-link" href="' . WebView::e($clubLink) . '">' . WebView::e($row['club'] ?? '') . '</a>' . (($row['controlled'] ?? false) ? ' <span class="you-mark">YOU</span>' : '') . '</td><td>' . WebView::e($row['played'] ?? 0) . '</td><td>' . WebView::e($row['wins'] ?? 0) . '</td><td>' . WebView::e($row['draws'] ?? 0) . '</td><td>' . WebView::e($row['losses'] ?? 0) . '</td><td>' . WebView::e($row['goal_difference'] ?? $row['gd'] ?? 0) . '</td><td><strong>' . WebView::e($row['points'] ?? 0) . '</strong></td></tr>';
        }
        $table = $standings === '' ? WebView::emptyState('Standings are not available for this competition.') : '<div class="table-scroll"><table><thead><tr><th>Pos</th><th>Club</th><th>P</th><th>W</th><th>D</th><th>L</th><th>GD</th><th>Pts</th></tr></thead><tbody>' . $standings . '</tbody></table></div>';
        $recent = '<ul class="fixture-list">'; foreach ((array) ($world['recent_results'] ?? []) as $item) { $recent .= '<li>' . WebView::e($item) . '</li>'; } $recent .= '</ul>'; if (($world['recent_results'] ?? []) === []) { $recent = WebView::emptyState('No completed results yet.'); }
        $upcoming = '<ul class="fixture-list">'; foreach ((array) ($world['upcoming_fixtures'] ?? []) as $item) { $upcoming .= '<li>' . WebView::e($item) . '</li>'; } $upcoming .= '</ul>'; if (($world['upcoming_fixtures'] ?? []) === []) { $upcoming = WebView::emptyState('No upcoming fixtures currently scheduled.'); }
        $competitionLinks = '';
        foreach ((array) ($world['competitions'] ?? []) as $link) {
            if (!is_array($link)) { continue; }
            $label = match ($link['type'] ?? '') {
                'domestic_cup' => 'Domestic Cup · ',
                'continental' => 'European Competition · ',
                'international' => 'International · ',
                default => 'League · ',
            } . (string) ($link['name'] ?? 'Competition');
            $competitionLinks .= '<li>' . WebView::link('competition', ['save' => $saveId, 'competition' => $link['id'] ?? ''], $label, 'text-link') . (($link['controlled'] ?? false) ? ' <span class="you-mark">YOUR CLUB</span>' : '') . '</li>';
        }
        $competitionSection = $competitionLinks === '' ? '' : WebView::section('COMPETITIONS', 'Follow the football world', '<ul class="fixture-list">' . $competitionLinks . '</ul>');
        $competitionId = (string) (((array) ($snapshot['summary']['current_competition'] ?? []))['id'] ?? '');
        $competitionTitle = $competitionId === '' ? WebView::e($world['competition'] ?? 'Football World') : '<a class="text-link" href="' . WebView::e(WebView::url('competition', ['save' => $saveId, 'competition' => $competitionId])) . '">' . WebView::e($world['competition'] ?? 'Football World') . '</a>';
        $body = '<div class="page-heading"><div><div class="eyebrow">WORLD</div><h1>' . $competitionTitle . '</h1><p>Current competition context, standings, results, and fixtures.</p></div></div>' . $competitionSection . WebView::section('STANDINGS', 'League table', $table) . '<div class="two-column">' . WebView::section('RECENT RESULTS', 'What just happened', $recent) . WebView::section('UPCOMING FIXTURES', 'What comes next', $upcoming) . '</div>';

        return $this->html('World', $body, $saveId, 'world', 200, $session);
    }

    private function competition(string $saveId, string $competitionId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $competitionId = $competitionId !== '' ? $competitionId : (string) (((array) ($snapshot['summary']['current_competition'] ?? []))['id'] ?? '');
        if ($competitionId === '') { return $this->redirect(WebView::url('world', ['save' => $saveId])); }
        $controlledClubId = (string) (((array) ($snapshot['summary']['current_club'] ?? []))['id'] ?? '');
        if ($controlledClubId === '' && (($snapshot['summary']['international']['selected'] ?? false) === true)) { $controlledClubId = (string) ($snapshot['summary']['international']['team_id'] ?? ''); }
        $view = (new CareerPresentationService($this->services))->competitionView($database, $competitionId, new SeasonId((string) $snapshot['summary']['current_season_id']), $snapshot['date'], $controlledClubId);
        $competition = $view['competition'];
        if ($competition->type() === CompetitionType::Continental) {
            $europe = (array) ($view['europe'] ?? []);
            $groups = '';
            foreach ((array) ($europe['groups'] ?? []) as $group) {
                if (!is_array($group)) { continue; }
                $rows = '';
                foreach ((array) ($group['table'] ?? []) as $index => $row) {
                    if (!is_array($row)) { continue; }
                    $class = ((string) ($row['club_id'] ?? '') === $controlledClubId) ? ' class="controlled-row"' : '';
                    $rows .= '<tr' . $class . '><td>' . ($index + 1) . '</td><td>' . WebView::e($row['club'] ?? $row['club_id'] ?? '') . '</td><td>' . (int) ($row['played'] ?? 0) . '</td><td>' . (int) ($row['wins'] ?? 0) . '</td><td>' . (int) ($row['draws'] ?? 0) . '</td><td>' . (int) ($row['losses'] ?? 0) . '</td><td>' . (int) ($row['goal_difference'] ?? 0) . '</td><td><strong>' . (int) ($row['points'] ?? 0) . '</strong></td></tr>';
                }
                $table = $rows === '' ? WebView::emptyState('No group results yet.') : '<div class="table-scroll"><table><thead><tr><th>Pos</th><th>Club</th><th>P</th><th>W</th><th>D</th><th>L</th><th>GD</th><th>Pts</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
                $groups .= WebView::section('GROUP ' . WebView::e($group['group'] ?? ''), 'Group-stage table', $table);
            }
            $rounds = '';
            foreach ((array) ($europe['rounds'] ?? []) as $round) {
                if (!is_array($round)) { continue; }
                $fixtures = '';
                foreach ((array) ($round['fixtures'] ?? []) as $fixture) {
                    if (!is_array($fixture)) { continue; }
                    $score = ($fixture['status'] ?? '') === 'completed' ? ' · ' . ($fixture['home_goals'] ?? 0) . '-' . ($fixture['away_goals'] ?? 0) : '';
                    $resolution = is_array($fixture['resolution'] ?? null) ? $fixture['resolution'] : [];
                    if (($resolution['extra_time_home_goals'] ?? 0) !== 0 || ($resolution['extra_time_away_goals'] ?? 0) !== 0) { $score .= ' (AET ' . (int) ($resolution['aet_home_goals'] ?? 0) . '-' . (int) ($resolution['aet_away_goals'] ?? 0) . ')'; }
                    if (($resolution['shootout_home_goals'] ?? null) !== null) { $score .= ' (PEN ' . (int) $resolution['shootout_home_goals'] . '-' . (int) $resolution['shootout_away_goals'] . ')'; }
                    $fixtures .= '<li>' . WebView::e(($fixture['date'] ?? '') . ' · ' . ($fixture['home'] ?? '') . ' vs ' . ($fixture['away'] ?? '') . $score) . (($fixture['controlled'] ?? false) ? ' <span class="you-mark">YOUR CLUB</span>' : '') . '</li>';
                }
                $rounds .= WebView::section(strtoupper((string) ($round['stage'] ?? 'ROUND')), 'Fixtures and results', $fixtures === '' ? WebView::emptyState('Fixtures will appear when this stage is drawn.') : '<ul class="fixture-list">' . $fixtures . '</ul>');
            }
            $remaining = array_map(static fn (array $club): string => (string) ($club['name'] ?? 'Club'), array_filter((array) ($europe['remaining_clubs'] ?? []), 'is_array'));
            $body = '<div class="page-heading"><div><div class="eyebrow">EUROPEAN COMPETITION</div><h1>' . WebView::e($competition->name()) . '</h1><p>Season ' . WebView::e($snapshot['summary']['current_season_id'] ?? '') . ' · stage: ' . WebView::e($europe['stage'] ?? '—') . ' · status: ' . WebView::e(ucwords(str_replace('_', ' ', (string) ($europe['status'] ?? 'not started')))) . '</p></div>' . WebView::link('world', ['save' => $saveId], 'Back to World') . '</div>' . WebView::section('REMAINING CLUBS', 'Continental field', $remaining === [] ? WebView::emptyState('The field is not available yet.') : '<p>' . WebView::e(implode(' · ', $remaining)) . '</p>') . $groups . $rounds;
            return $this->html('European Competition', $body, $saveId, 'world', 200, $session);
        }
        if ($competition->type() === CompetitionType::International) {
            $international = (array) ($view['international'] ?? []);
            $groups = '';
            foreach ((array) ($international['groups'] ?? []) as $group) {
                if (!is_array($group)) { continue; }
                $rows = '';
                foreach ((array) ($group['table'] ?? []) as $index => $row) {
                    if (!is_array($row)) { continue; }
                    $class = ((string) ($row['team_id'] ?? '') === $controlledClubId) ? ' class="controlled-row"' : '';
                    $rows .= '<tr' . $class . '><td>' . ($index + 1) . '</td><td>' . WebView::e($row['team'] ?? $row['team_id'] ?? '') . '</td><td>' . (int) ($row['played'] ?? 0) . '</td><td>' . (int) ($row['wins'] ?? 0) . '</td><td>' . (int) ($row['draws'] ?? 0) . '</td><td>' . (int) ($row['losses'] ?? 0) . '</td><td>' . (int) ($row['gd'] ?? 0) . '</td><td><strong>' . (int) ($row['points'] ?? 0) . '</strong></td></tr>';
                }
                $groups .= WebView::section('GROUP ' . WebView::e($group['group'] ?? ''), 'National-team table', $rows === '' ? WebView::emptyState('No group results yet.') : '<div class="table-scroll"><table><thead><tr><th>Pos</th><th>Team</th><th>P</th><th>W</th><th>D</th><th>L</th><th>GD</th><th>Pts</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
            }
            $rounds = '';
            foreach ((array) ($international['rounds'] ?? []) as $round) {
                if (!is_array($round)) { continue; }
                $fixtures = '';
                foreach ((array) ($round['fixtures'] ?? []) as $fixture) {
                    if (!is_array($fixture)) { continue; }
                    $score = ($fixture['status'] ?? '') === 'completed' ? ' · ' . ($fixture['home_goals'] ?? 0) . '-' . ($fixture['away_goals'] ?? 0) : '';
                    $resolution = is_array($fixture['resolution'] ?? null) ? $fixture['resolution'] : [];
                    if (($resolution['extra_time_home_goals'] ?? 0) !== 0 || ($resolution['extra_time_away_goals'] ?? 0) !== 0) { $score .= ' (AET ' . (int) ($resolution['aet_home_goals'] ?? 0) . '-' . (int) ($resolution['aet_away_goals'] ?? 0) . ')'; }
                    if (($resolution['shootout_home_goals'] ?? null) !== null) { $score .= ' (PEN ' . (int) $resolution['shootout_home_goals'] . '-' . (int) $resolution['shootout_away_goals'] . ')'; }
                    $fixtures .= '<li>' . WebView::e(($fixture['date'] ?? '') . ' · ' . ($fixture['home'] ?? '') . ' vs ' . ($fixture['away'] ?? '') . $score) . (($fixture['controlled'] ?? false) ? ' <span class="you-mark">YOUR COUNTRY</span>' : '') . '</li>';
                }
                $rounds .= WebView::section(strtoupper((string) ($round['stage'] ?? 'ROUND')), 'Fixtures and results', $fixtures === '' ? WebView::emptyState('Fixtures will appear when this stage is drawn.') : '<ul class="fixture-list">' . $fixtures . '</ul>');
            }
            $remaining = array_map(static fn (array $team): string => (string) ($team['name'] ?? 'National Team'), array_filter((array) ($international['remaining_teams'] ?? []), 'is_array'));
            $body = '<div class="page-heading"><div><div class="eyebrow">INTERNATIONAL FOOTBALL</div><h1>' . WebView::e($competition->name()) . '</h1><p>Season ' . WebView::e($snapshot['summary']['current_season_id'] ?? '') . ' · stage: ' . WebView::e($international['stage'] ?? '—') . ' · status: ' . WebView::e(ucwords(str_replace('_', ' ', (string) ($international['status'] ?? 'not started')))) . '</p></div>' . WebView::link('international', ['save' => $saveId], 'Back to International') . '</div>' . WebView::section('REMAINING TEAMS', 'Championship field', $remaining === [] ? WebView::emptyState('The field is not available yet.') : '<p>' . WebView::e(implode(' · ', $remaining)) . '</p>') . $groups . $rounds;
            return $this->html('World Championship', $body, $saveId, 'international', 200, $session);
        }
        if ($competition->type() === CompetitionType::DomesticCup) {
            $cup = (array) ($view['cup'] ?? []);
            $rounds = '';
            foreach ((array) ($cup['rounds'] ?? []) as $round) {
                if (!is_array($round)) { continue; }
                $fixtures = '';
                foreach ((array) ($round['fixtures'] ?? []) as $fixture) {
                    if (!is_array($fixture)) { continue; }
                    $score = ($fixture['status'] ?? '') === 'completed' ? ' · ' . ($fixture['home_goals'] ?? 0) . '-' . ($fixture['away_goals'] ?? 0) : '';
                    if (($fixture['extra_time_home_goals'] ?? 0) !== 0 || ($fixture['extra_time_away_goals'] ?? 0) !== 0) { $score .= ' (AET ' . (int) ($fixture['aet_home_goals'] ?? 0) . '-' . (int) ($fixture['aet_away_goals'] ?? 0) . ')'; }
                    if (($fixture['shootout_home_goals'] ?? null) !== null) { $score .= ' (pens ' . (int) $fixture['shootout_home_goals'] . '-' . (int) $fixture['shootout_away_goals'] . ')'; }
                    $fixtures .= '<li>' . WebView::e(($fixture['date'] ?? '') . ' · ' . ($fixture['home'] ?? '') . ' vs ' . ($fixture['away'] ?? '') . $score) . (($fixture['controlled'] ?? false) ? ' <span class="you-mark">YOUR CLUB</span>' : '') . '</li>';
                }
                $rounds .= WebView::section(strtoupper((string) ($round['stage'] ?? 'ROUND')), 'Round fixtures', $fixtures === '' ? WebView::emptyState('Fixtures will appear when this round is drawn.') : '<ul class="fixture-list">' . $fixtures . '</ul>');
            }
            $remaining = array_map(static fn (array $club): string => (string) ($club['name'] ?? 'Club'), array_filter((array) ($cup['remaining_clubs'] ?? []), 'is_array'));
            $body = '<div class="page-heading"><div><div class="eyebrow">DOMESTIC CUP</div><h1>' . WebView::e($competition->name()) . '</h1><p>Knockout football · current round: ' . WebView::e($cup['current_round'] ?? '—') . ' · status: ' . WebView::e(ucwords(str_replace('_', ' ', (string) ($cup['status'] ?? 'not started')))) . '</p></div>' . WebView::link('world', ['save' => $saveId], 'Back to World') . '</div>' . WebView::section('REMAINING CLUBS', 'Still in the cup', $remaining === [] ? WebView::emptyState('The draw is not available yet.') : '<p>' . WebView::e(implode(' · ', $remaining)) . '</p>') . $rounds;
            return $this->html('Domestic Cup', $body, $saveId, 'world', 200, $session);
        }
        $rows = '';
        foreach ((array) ($view['standings'] ?? []) as $index => $row) {
            $class = ($row['controlled'] ?? false) ? ' class="controlled-row"' : '';
            $rows .= '<tr' . $class . '><td>' . ($index + 1) . '</td><td><a class="table-link" href="' . WebView::e(WebView::url('club', ['save' => $saveId, 'club' => $row['club_id'] ?? ''])) . '">' . WebView::e($row['club'] ?? '') . '</a></td><td>' . (int) ($row['played'] ?? 0) . '</td><td>' . (int) ($row['wins'] ?? 0) . '</td><td>' . (int) ($row['draws'] ?? 0) . '</td><td>' . (int) ($row['losses'] ?? 0) . '</td><td>' . (int) ($row['goal_difference'] ?? $row['gd'] ?? 0) . '</td><td><strong>' . (int) ($row['points'] ?? 0) . '</strong></td></tr>';
        }
        $table = $rows === '' ? WebView::emptyState('Standings are not available.') : '<div class="table-scroll"><table><thead><tr><th>Pos</th><th>Club</th><th>P</th><th>W</th><th>D</th><th>L</th><th>GD</th><th>Pts</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $recent = $this->fixtureList($view['recent_results'] ?? [], 'No completed results yet.');
        $upcoming = $this->fixtureList($view['upcoming_fixtures'] ?? [], 'No upcoming fixtures currently scheduled.');
        $body = '<div class="page-heading"><div><div class="eyebrow">COMPETITION</div><h1>' . WebView::e($competition->name()) . '</h1><p>Tier ' . WebView::e($competition->tier()) . ' · canonical standings and fixtures.</p></div>' . WebView::link('world', ['save' => $saveId], 'Back to World') . '</div>' . WebView::section('STANDINGS', 'League table', $table) . '<div class="two-column">' . WebView::section('RECENT RESULTS', 'Latest completed fixtures', $recent) . WebView::section('UPCOMING FIXTURES', 'Next scheduled fixtures', $upcoming) . '</div>';

        return $this->html('Competition', $body, $saveId, 'world', 200, $session);
    }

    private function international(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $seasonId = (string) ($snapshot['summary']['current_season_id'] ?? '');
        $links = '';
        foreach ((new CompetitionRepository($database))->bySeason(new SeasonId($seasonId)) as $competition) {
            if ($competition->type() !== CompetitionType::International) { continue; }
            $links .= '<li>' . WebView::link('competition', ['save' => $saveId, 'competition' => $competition->id()->value()], $competition->name(), 'text-link') . '</li>';
        }
        $teams = '';
        foreach ($this->services->nationalTeams()->teams($database) as $team) {
            $teams .= '<li>' . WebView::link('national-team', ['save' => $saveId, 'team' => $team['id']], $team['name'], 'text-link') . ' · ' . WebView::e($team['pool']) . ' eligible Players · strength ' . WebView::e($team['strength']) . '</li>';
        }
        $body = '<div class="page-heading"><div><div class="eyebrow">WORLD · INTERNATIONAL</div><h1>National Teams</h1><p>Generic national presentation built from the existing Player world. No national-team management or licensed federation content is included.</p></div></div>' . WebView::section('CHAMPIONSHIP', 'Competitions', $links === '' ? WebView::emptyState('The next World Championship is not initialized yet.') : '<ul class="fixture-list">' . $links . '</ul>') . WebView::section('NATIONAL TEAMS', 'Supported countries', '<ul class="fixture-list">' . $teams . '</ul>');

        return $this->html('International Football', $body, $saveId, 'international', 200, $session);
    }

    private function nationalTeam(string $saveId, string $teamId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $seasonId = (string) ($snapshot['summary']['current_season_id'] ?? '');
        if ($teamId === '' || $seasonId === '' || !$this->services->nationalTeams()->isNationalTeam($teamId)) {
            return $this->redirect(WebView::url('international', ['save' => $saveId]));
        }
        $team = array_values(array_filter($this->services->nationalTeams()->teams($database), static fn (array $row): bool => $row['id'] === $teamId))[0] ?? null;
        if ($team === null) {
            return $this->redirect(WebView::url('international', ['save' => $saveId]));
        }
        $season = new SeasonId($seasonId);
        $playerRepository = new PlayerRepository($database);
        $clubSquads = $this->services->clubModule()->service()->squadRepository($database);
        $clubRepository = $this->services->clubModule()->service()->repository($database);
        $groups = ['Goalkeepers' => [], 'Defenders' => [], 'Midfielders' => [], 'Forwards' => []];
        foreach ($this->services->nationalTeams()->squad($database, $teamId, $season) as $row) {
            $player = $playerRepository->get(new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $row['player_id']));
            $memberships = array_values(array_filter($clubSquads->byPlayer($player->id()), static fn ($membership): bool => $membership->seasonId()->value() === $seasonId));
            $clubName = 'Free Agent';
            if ($memberships !== []) {
                $clubName = $clubRepository->get($memberships[0]->clubId())->canonicalName();
            }
            $groups[$this->positionGroup($player->primaryPosition())][] = WebView::playerCard(
                WebView::url('profile', ['save' => $saveId, 'player' => $player->id()->value()]),
                $this->portraitUrl($saveId, $player->id()->value(), 'club', 128),
                $player->preferredName(),
                CareerLabels::position($player->primaryPosition()->value) . ' · OVR ' . $player->overallRating() . ' · ' . $clubName,
                CareerLabels::value((string) ($row['status'] ?? 'selected')) . ' · ' . CareerLabels::value((string) ($row['role'] ?? $player->primaryPosition()->value)),
            );
        }
        $squadHtml = '';
        foreach ($groups as $label => $cards) {
            if ($cards !== []) { $squadHtml .= WebView::section('SQUAD', $label, '<div class="squad-grid">' . implode('', $cards) . '</div>', 'squad-group'); }
        }
        $competitionRepository = new CompetitionRepository($database);
        $fixtures = array_values(array_filter((new MatchRepository($database))->byClub($teamId, $season), static fn (GameMatch $match): bool => $match->status() !== MatchStatus::Scheduled || !$match->scheduledDate()->isBefore($snapshot['date'])));
        usort($fixtures, static fn (GameMatch $left, GameMatch $right): int => strcmp($left->scheduledDate()->toIsoString() . $left->id()->value(), $right->scheduledDate()->toIsoString() . $right->id()->value()));
        $fixtureLines = '';
        foreach (array_slice($fixtures, 0, 8) as $fixture) {
            $opponent = $fixture->homeClubId()->value() === $teamId ? $fixture->awayClubId()->value() : $fixture->homeClubId()->value();
            $opponent = $this->services->nationalTeams()->displayName($database, $opponent);
            $score = $fixture->result() === null ? 'upcoming' : $fixture->result()->homeGoals() . '-' . $fixture->result()->awayGoals();
            $fixtureLines .= '<li>' . WebView::e($fixture->scheduledDate()->toIsoString() . ' · ' . ($fixture->homeClubId()->value() === $teamId ? $team['name'] . ' vs ' . $opponent : $opponent . ' vs ' . $team['name']) . ' · ' . $score . ' · ' . ($competitionRepository->get($fixture->competitionId())->name())) . '</li>';
        }
        $body = '<div class="page-heading"><div><div class="eyebrow">NATIONAL TEAM</div><h1>' . WebView::e($team['name']) . '</h1><p>Season ' . WebView::e($seasonId) . ' · strength ' . WebView::e($team['strength']) . ' · ' . WebView::e($team['pool']) . ' eligible Players</p></div>' . WebView::link('international', ['save' => $saveId], 'Back to International') . '</div>' . ($squadHtml === '' ? WebView::emptyState('This Nation does not yet have a usable senior squad.') : $squadHtml) . WebView::section('FIXTURES', 'Upcoming and recent international football', $fixtureLines === '' ? WebView::emptyState('No national-team fixtures are scheduled.') : '<ul class="fixture-list">' . $fixtureLines . '</ul>');

        return $this->html('National Team', $body, $saveId, 'international', 200, $session);
    }

    private function club(string $saveId, string $clubId, array &$session): array
    {
        if ($clubId === '') { return $this->redirect(WebView::url('world', ['save' => $saveId])); }
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $controlledClubId = (string) (((array) ($snapshot['summary']['current_club'] ?? []))['id'] ?? '');
        $view = (new CareerPresentationService($this->services))->clubView($database, $clubId, new SeasonId((string) $snapshot['summary']['current_season_id']), $snapshot['date'], $controlledClubId);
        $club = $view['club'];
        $competition = $view['competition'];
        $nation = (new NationRepository($database))->find($club->nationId());
        $position = $view['position'] === null ? '—' : (string) $view['position'];
        $clubSeason = is_array($view['club_season'] ?? null) ? $view['club_season'] : null;
        $recent = $this->fixtureList($view['recent_results'] ?? [], 'No completed results yet.');
        $upcoming = $this->fixtureList($view['upcoming_fixtures'] ?? [], 'No upcoming fixtures currently scheduled.');
        $objectiveBody = $clubSeason === null ? WebView::emptyState('No League objective is available for this Club in the current Season.') : '<div class="stat-grid compact">' . WebView::stat('Expectation', CareerLabels::value($clubSeason['expectation'] ?? null)) . WebView::stat('Progress', CareerLabels::value($clubSeason['progress'] ?? null)) . WebView::stat('Pressure', CareerLabels::value($clubSeason['pressure'] ?? null)) . '</div><p class="muted">' . WebView::e(CareerLabels::value($clubSeason['season']['phase'] ?? null, 'Current Season')) . ' · Position ' . WebView::e($clubSeason['league']['position'] ?? '—') . ' of ' . WebView::e($clubSeason['league']['size'] ?? '—') . '</p>';
        foreach ([['label' => 'Cup', 'key' => 'cup'], ['label' => 'Europe', 'key' => 'europe']] as $knockout) {
            $context = $clubSeason === null || !is_array($clubSeason[$knockout['key']] ?? null) ? null : $clubSeason[$knockout['key']];
            if ($context !== null) {
                $objectiveBody .= '<p><strong>' . $knockout['label'] . ':</strong> ' . WebView::e($context['competition'] ?? $knockout['label']) . ' · ' . WebView::e(CareerLabels::value($context['status'] ?? null, 'Not started')) . '</p>';
            }
        }
        $body = '<div class="page-heading"><div><div class="eyebrow">CLUB</div><h1>' . WebView::e($club->canonicalName()) . '</h1><p>' . WebView::e($club->city()) . ' · ' . WebView::e($nation?->displayName() ?? 'Football world') . ($competition === null ? '' : ' · ' . WebView::e($competition->name())) . '</p></div>' . WebView::link('competition', ['save' => $saveId, 'competition' => $competition?->id()->value()], 'Back to Competition') . '</div>' . WebView::section('CLUB CONTEXT', 'Current position', '<div class="stat-grid compact">' . WebView::stat('League position', $position) . WebView::stat('Competition', $competition?->name() ?? 'Not available') . WebView::stat('Tier', $competition?->tier() ?? '—') . '</div>') . WebView::section('SEASON EXPECTATION', 'Football stakes', $objectiveBody) . '<div class="two-column">' . WebView::section('RECENT RESULTS', 'Club results', $recent) . WebView::section('UPCOMING FIXTURES', 'Club schedule', $upcoming) . '</div>' . WebView::section('SQUAD', 'Current Players', WebView::link('squad', ['save' => $saveId, 'club' => $clubId], 'Open Squad'));

        return $this->html('Club', $body, $saveId, 'world', 200, $session);
    }

    private function relationships(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $summary = $snapshot['summary'];
        $social = (array) ($summary['social'] ?? []);
        $socialService = $this->services->playerModule()->service()->socialService();
        $playerId = (string) ($summary['player']['id'] ?? '');
        $relationships = $socialService->relationships($database, $playerId);
        $cards = '';
        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) { continue; }
            $cards .= '<div class="panel relationship-card"><div class="eyebrow">' . WebView::e(strtoupper((string) ($relationship['type'] ?? 'relationship'))) . '</div><h2>' . WebView::e((string) ($relationship['name'] ?? 'Player')) . '</h2><p>' . WebView::e((string) ($relationship['context'] ?? 'Football relationship')) . '</p><small>' . WebView::e(CareerLabels::position($relationship['position'] ?? null)) . '</small></div>';
        }
        $history = '';
        foreach ($socialService->history($database, $playerId, 8) as $item) {
            if (is_array($item)) { $history .= '<li>' . WebView::e((string) ($item['event_date'] ?? '')) . ' · ' . WebView::e((string) ($item['headline'] ?? '')) . '</li>'; }
        }
        $manager = '<div class="stat-grid compact">' . WebView::stat('Public profile', $social['public_profile_label'] ?? 'Unknown') . WebView::stat('Club standing', $social['club_standing_label'] ?? 'New Arrival') . WebView::stat('Supporters', $social['supporter_sentiment'] ?? 'Neutral') . WebView::stat('Manager', $social['manager_relationship'] ?? 'Professional') . '</div><p class="muted">Manager context is Club-scoped; GOAL: Legacy does not simulate manager lives or a world social graph.</p>';
        $body = '<div class="page-heading"><div><div class="eyebrow">CAREER · RELATIONSHIPS</div><h1>Football relationships</h1><p>Meaningful relationships emerge from football moments and remain bounded to your Career.</p></div></div>' . WebView::section('CURRENT CONTEXT', 'Club and public standing', $manager) . ($cards === '' ? WebView::section('RELATIONSHIPS', 'Dressing room and rivals', WebView::emptyState('No persistent football relationships yet.')) : '<div class="two-column">' . $cards . '</div>') . WebView::section('SOCIAL HISTORY', 'Landmark moments', $history === '' ? WebView::emptyState('No landmark social moments yet.') : '<ul class="timeline compact-timeline">' . $history . '</ul>');

        return $this->html('Relationships', $body, $saveId, 'relationships', 200, $session);
    }

    private function pulse(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $summary = $snapshot['summary'];
        $player = (array) ($summary['player'] ?? []);
        $playerId = (string) ($player['id'] ?? '');
        $pulse = $this->services->playerModule()->service()->pulseService();
        $context = $pulse->context($database, $playerId);
        $feed = $pulse->feed($database, $playerId, 40);
        $pending = $pulse->pendingResponse($database, $playerId);
        $posts = '';
        $actorLabels = ['fan' => 'Supporters', 'club' => 'Club', 'media' => 'Media', 'teammate' => 'Teammate', 'rival' => 'Rival', 'competition' => 'Competition', 'national' => 'National Team', 'player' => 'You'];
        foreach ($feed as $item) {
            if (!is_array($item)) { continue; }
            $actorType = (string) ($item['actor_type'] ?? 'fan');
            $label = $actorLabels[$actorType] ?? 'Football world';
            $posts .= '<article class="pulse-post"><div class="pulse-post-meta"><strong>' . WebView::e($item['actor_name'] ?? 'Football world') . '</strong><span>' . WebView::e($label) . ' · ' . WebView::e($item['date'] ?? '') . '</span></div><p>' . WebView::e($item['text'] ?? '') . '</p><small>' . WebView::e(number_format((int) ($item['engagement'] ?? 0))) . ' reactions</small></article>';
        }
        $response = '';
        if (is_array($pending)) {
            $choices = '';
            foreach ((array) ($pending['choices'] ?? []) as $choice) {
                if (!is_array($choice)) { continue; }
                $choices .= '<label class="choice-card"><input type="radio" name="choice" value="' . WebView::e($choice['id'] ?? '') . '" required><span>' . WebView::e($choice['label'] ?? 'Respond') . '</span></label>';
            }
            $response = WebView::section('PENDING RESPONSE', 'Your voice on Pulse', '<form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel choice-panel" data-busy><input type="hidden" name="action" value="resolve_pulse"><input type="hidden" name="save" value="' . WebView::e($saveId) . '"><input type="hidden" name="source_key" value="' . WebView::e($pending['source_key'] ?? '') . '"><input type="hidden" name="token" value="' . WebView::e($this->issueToken($session, 'pulse_' . $saveId . '_' . (string) ($pending['source_key'] ?? ''))) . '"><div class="choice-list">' . $choices . '</div><button class="button button-primary" type="submit">Post response</button></form>');
        }
        $header = '<div class="page-heading"><div><div class="eyebrow">PULSE · PUBLIC FOOTBALL WORLD</div><h1>' . WebView::e($player['preferred_name'] ?? 'Player') . ' on Pulse</h1><p>Public reaction derived from canonical football facts. Pulse never changes the Match or Career truth.</p></div></div>';
        $profile = WebView::section('PLAYER SOCIAL HEADER', 'Audience', '<div class="stat-grid compact">' . WebView::stat('Audience', $context['audience_band'] ?? 'Local Following') . WebView::stat('Following', $context['followers_label'] ?? '120') . WebView::stat('Public profile', ((array) ($summary['social'] ?? []))['public_profile_label'] ?? 'Unknown') . WebView::stat('Supporters', ((array) ($summary['social'] ?? []))['supporter_sentiment'] ?? 'Neutral') . '</div>');
        $feedBody = $posts === '' ? WebView::emptyState('Your football story is only starting to get noticed.') : '<div class="pulse-feed">' . $posts . '</div>';

        return $this->html('Pulse', $header . $profile . $response . WebView::section('PULSE FEED', 'Reactions on Pulse', $feedBody), $saveId, 'pulse', 200, $session);
    }

    private function news(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $items = '';
        foreach ((new CareerPresentationService($this->services))->news($database, $snapshot['summary'], $snapshot['date']) as $item) { $items .= '<article class="news-item"><time>' . WebView::e($item['date'] ?? '') . '</time><p>' . WebView::e($item['headline'] ?? '') . '</p></article>'; }
        $body = '<div class="page-heading"><div><div class="eyebrow">NEWS</div><h1>Your football world</h1><p>Canonical results, movement, performance, and career moments.</p></div></div>' . ($items === '' ? WebView::emptyState('No career news yet.') : '<div class="news-feed">' . $items . '</div>');

        return $this->html('News', $body, $saveId, 'news', 200, $session);
    }

    private function training(string $saveId, array &$session): array
    {
        $snapshot = $this->snapshot($saveId, $this->database($saveId));
        $summary = $snapshot['summary'];
        if (($summary['career_state'] ?? 'active') === 'retired') {
            return $this->html('Training', '<div class="page-heading"><div><div class="eyebrow">TRAINING & PRIORITIES</div><h1>Career complete</h1><p>Training and active playing priorities are closed after retirement.</p></div></div>' . WebView::section('READ-ONLY', 'Final Career state', WebView::emptyState('Your development record remains available in Career Legacy.')) . '<div class="form-actions">' . WebView::link('legacy', ['save' => $saveId], 'Open Career Legacy', 'button button-primary') . '</div>', $saveId, 'training', 200, $session);
        }
        $focus = (string) ($summary['training_focus'] ?? 'balanced');
        $priority = (string) ($summary['priority'] ?? 'balanced');
        $focusOptions = ''; foreach (TrainingFocus::cases() as $item) { $focusOptions .= '<option value="' . $item->value . '"' . ($focus === $item->value ? ' selected' : '') . '>' . WebView::e(CareerLabels::value($item->value)) . '</option>'; }
        $priorityOptions = ''; foreach (CareerPriority::cases() as $item) { $priorityOptions .= '<option value="' . $item->value . '"' . ($priority === $item->value ? ' selected' : '') . '>' . WebView::e(CareerLabels::value($item->value)) . '</option>'; }
        $readiness = (array) ($summary['readiness'] ?? []);
        $readinessPanel = '<div class="stat-grid compact">' . WebView::stat('Readiness', CareerLabels::value($readiness['label'] ?? null, 'Ready')) . WebView::stat('Workload', ((int) ($readiness['fatigue'] ?? 0)) . '/100') . WebView::stat('Intensity', CareerLabels::value($summary['training_intensity'] ?? 'normal')) . '</div><p class="muted">' . WebView::e((string) ($readiness['description'] ?? 'Recovery is calculated from simulation time, not page views.')) . '</p>';
        $position = (array) ($summary['position_development'] ?? []);
        $positionActions = '';
        foreach ((array) ($position['eligible_next_positions'] ?? []) as $candidate) {
            if (!is_array($candidate)) { continue; }
            $value = (string) ($candidate['position'] ?? '');
            $positionActions .= '<p><strong>' . WebView::e(CareerLabels::position($value)) . '</strong> · eligible development path ' . WebView::form('set_position_focus', 'Develop this position', ['save' => $saveId, 'position' => $value], 'button button-secondary') . '</p>';
        }
        if (($position['developing_position'] ?? null) !== null) {
            $positionActions .= '<p><strong>Current focus:</strong> ' . WebView::e(CareerLabels::position($position['developing_position'])) . ' · ' . (int) ($position['progress'] ?? 0) . '% complete ' . WebView::form('cancel_position_focus', 'Cancel focus', ['save' => $saveId], 'button button-secondary') . '</p>';
        } elseif (($position['secondary_positions'] ?? []) !== []) {
            foreach ((array) $position['secondary_positions'] as $secondary) {
                $positionActions .= WebView::form('change_primary_position', 'Make ' . CareerLabels::position(is_string($secondary) ? $secondary : null) . ' primary', ['save' => $saveId, 'position' => (string) $secondary], 'button button-secondary');
            }
        }
        $positionPanel = '<div class="stat-grid compact">' . WebView::stat('Primary', CareerLabels::position($position['primary_position'] ?? null)) . WebView::stat('Secondary', ($position['secondary_positions'] ?? []) === [] ? 'None' : implode(', ', array_map(static fn (mixed $value): string => CareerLabels::position(is_string($value) ? $value : null), (array) $position['secondary_positions']))) . WebView::stat('Progress', ($position['developing_position'] ?? null) === null ? 'No active focus' : ((int) ($position['progress'] ?? 0)) . '%') . '</div><p class="muted">Position development is a medium-term training choice. It uses canonical training blocks and does not change attributes or guarantee selection. Making a completed secondary position primary preserves the Player and attributes while changing future football context.</p>' . ($positionActions === '' ? WebView::emptyState('No adjacent position currently fits this Player profile.') : $positionActions);
        $body = '<div class="page-heading"><div><div class="eyebrow">TRAINING & PRIORITIES</div><h1>Shape the next block</h1><p>These choices feed the canonical development and readiness systems.</p></div></div>' . WebView::section('READINESS', 'Current football state', $readinessPanel) . WebView::section('POSITION DEVELOPMENT', 'Build another football option', $positionPanel) . '<div class="two-column"><form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel form-panel" data-busy><input type="hidden" name="action" value="set_training"><input type="hidden" name="save" value="' . WebView::e($saveId) . '"><label>Training focus<select name="focus">' . $focusOptions . '</select></label><p class="muted">Focus influences where existing development progress is directed.</p><button class="button button-primary" type="submit">Save training focus</button></form><form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel form-panel" data-busy><input type="hidden" name="action" value="set_priority"><input type="hidden" name="save" value="' . WebView::e($saveId) . '"><label>Career priority<select name="priority">' . $priorityOptions . '</select></label><p class="muted">Recovery and lifestyle priorities use a light training load; Development uses an intense block; other priorities remain normal.</p><button class="button button-primary" type="submit">Save priority</button></form></div>';

        return $this->html('Training', $body, $saveId, 'training', 200, $session);
    }

    private function finances(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $playerId = (string) (($snapshot['summary']['player']['id'] ?? ''));
        $finance = $this->services->playerFinanceService()->summary($database, $playerId, $snapshot['date']);
        $transactionRows = '';
        foreach ((array) ($finance['transactions'] ?? []) as $transaction) {
            if (!is_array($transaction)) { continue; }
            $amount = (int) ($transaction['amount'] ?? 0);
            $transactionRows .= '<tr><td>' . WebView::e($transaction['occurred_date'] ?? '') . '</td><td>' . WebView::e($this->financeType((string) ($transaction['type'] ?? ''))) . '</td><td>' . WebView::e($transaction['context'] ?? '') . '</td><td class="money ' . ($amount < 0 ? 'money-out' : 'money-in') . '">' . WebView::e(($amount < 0 ? '-' : '+') . $this->money(abs($amount))) . '</td><td>' . WebView::e($this->money((int) ($transaction['balance_after'] ?? 0))) . '</td></tr>';
        }
        $transactions = $transactionRows === '' ? WebView::emptyState('No financial transactions yet.') : '<div class="table-scroll"><table><thead><tr><th>Date</th><th>Type</th><th>Context</th><th>Amount</th><th>Balance</th></tr></thead><tbody>' . $transactionRows . '</tbody></table></div>';
        $owned = (array) ($finance['owned'] ?? []);
        $ownedText = $owned === [] ? WebView::emptyState('No lifestyle assets owned yet.') : '<ul class="timeline">' . implode('', array_map(static fn (array $item): string => '<li>' . WebView::e($item['label'] ?? $item['item_id'] ?? 'Lifestyle asset') . ' · ' . WebView::e(($item['active'] ?? false) ? 'Active' : 'Owned') . '</li>', $owned)) . '</ul>';
        $financialContext = (array) ($finance['financial_context'] ?? []);
        $body = '<div class="page-heading"><div><div class="eyebrow">FINANCES</div><h1>Your football earnings</h1><p>Wages arrive through simulated calendar time. Browsing this page never processes payroll.</p><p><strong>' . WebView::e($financialContext['label'] ?? 'Starting out') . ':</strong> ' . WebView::e($financialContext['description'] ?? '') . '</p></div></div>';
        $body .= '<div class="stat-grid"><div class="stat stat-highlight"><span>Balance</span><strong>' . WebView::e($this->money((int) ($finance['balance'] ?? 0))) . '</strong></div>' . WebView::stat('Current wage', $finance['current_wage'] === null ? 'No active wage' : $this->money((int) $finance['current_wage']) . ' / week') . WebView::stat('Wage earnings', $this->money((int) ($finance['wage_income'] ?? 0))) . WebView::stat('All income', $this->money((int) ($finance['income'] ?? 0))) . WebView::stat('Career spending', $this->money((int) ($finance['spending'] ?? 0))) . '</div>';
        $body .= '<div class="dashboard-grid"><div class="dashboard-main">' . WebView::section('RECENT ACTIVITY', 'Financial history', $transactions) . '</div><aside class="dashboard-side">' . WebView::section('LIFESTYLE', 'Owned assets', $ownedText) . WebView::section('NEXT STEP', 'Use your earnings', WebView::link('lifestyle', ['save' => $saveId], 'Browse Lifestyle', 'button button-primary')) . '</aside></div>';

        return $this->html('Finances', $body, $saveId, 'finances', 200, $session);
    }

    private function lifestyle(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $playerId = (string) (($snapshot['summary']['player']['id'] ?? ''));
        if (($snapshot['summary']['career_state'] ?? 'active') === 'retired') {
            return $this->html('Lifestyle', '<div class="page-heading"><div><div class="eyebrow">LIFESTYLE</div><h1>Career complete</h1><p>New active Career purchases are closed after retirement.</p></div></div>' . WebView::section('OWNED ASSETS', 'Historical balance', WebView::emptyState('Existing assets and balance remain visible in Finances.')) . '<div class="form-actions">' . WebView::link('finances', ['save' => $saveId], 'Open Finances', 'button button-primary') . WebView::link('legacy', ['save' => $saveId], 'Open Career Legacy') . '</div>', $saveId, 'lifestyle', 200, $session);
        }
        $finance = $this->services->playerFinanceService()->summary($database, $playerId, $snapshot['date']);
        $owned = (array) ($finance['owned_ids'] ?? []);
        $ownedRows = [];
        foreach ((array) ($finance['owned'] ?? []) as $ownedItem) { if (is_array($ownedItem)) { $ownedRows[(string) ($ownedItem['item_id'] ?? $ownedItem['id'] ?? '')] = $ownedItem; } }
        $groups = [];
        foreach (LifestyleCatalog::all() as $item) { $groups[$item['category']][] = $item; }
        $sections = '';
        foreach ($groups as $category => $items) {
            $cards = '';
            foreach ($items as $item) {
                $isOwned = isset($owned[$item['id']]);
                $affordable = (int) ($finance['balance'] ?? 0) >= (int) $item['price'];
                $ownedRow = $ownedRows[$item['id']] ?? null;
                $isActive = is_array($ownedRow) && (bool) ($ownedRow['active'] ?? false);
                $status = $isOwned ? ($isActive ? 'ACTIVE' : 'OWNED') : ($affordable ? 'AFFORDABLE' : 'LOCKED · EARN MORE');
                $action = $isOwned ? ($isActive || LifestyleCatalog::isExperience($item) ? '<span class="tag">' . WebView::e($isActive ? 'Active' : 'Owned') . '</span>' : WebView::form('activate_lifestyle', 'Make active', ['save' => $saveId, 'item' => $item['id'], 'token' => $this->issueToken($session, 'activate_' . $saveId . '_' . $item['id'])], 'button button-secondary', 'data-busy')) : ($affordable ? WebView::form('purchase_lifestyle', 'Purchase', ['save' => $saveId, 'item' => $item['id'], 'confirm' => '1', 'token' => $this->issueToken($session, 'purchase_' . $saveId)], 'button button-primary', 'onsubmit="return confirm(\'Purchase this item?\')" data-busy') : '<span class="muted">Not affordable yet</span>');
                $effects = [];
                foreach ((array) ($item['effects'] ?? []) as $effect => $value) { $effects[] = $this->effectLabel((string) $effect); }
                $cards .= '<article class="lifestyle-card"><div><span class="eyebrow">' . WebView::e($item['tier']) . '</span><h2>' . WebView::e($item['label']) . '</h2><p>' . WebView::e($item['description']) . '</p><small>' . WebView::e($effects === [] ? 'Career flavor' : implode(' · ', $effects)) . '</small></div><div class="lifestyle-action"><strong>' . WebView::e($this->money((int) $item['price'])) . '</strong><span class="tag">' . WebView::e($status) . '</span>' . $action . '</div></article>';
            }
            $sections .= WebView::section('LIFESTYLE', $category, '<div class="lifestyle-list">' . $cards . '</div>');
        }
        $body = '<div class="page-heading"><div><div class="eyebrow">LIFESTYLE</div><h1>Build life around the football</h1><p>Balance: ' . WebView::e($this->money((int) ($finance['balance'] ?? 0))) . '. Purchases are optional, persistent, and never directly change attributes.</p></div></div>' . $sections . '<div class="form-actions">' . WebView::link('finances', ['save' => $saveId], 'Back to Finances') . WebView::link('home', ['save' => $saveId], 'Career Home') . '</div>';

        return $this->html('Lifestyle', $body, $saveId, 'finances', 200, $session);
    }

    private function event(string $saveId, array &$session): array
    {
        $database = $this->database($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $event = $this->services->playerModule()->service()->careerExperienceService()->pendingEvent($database, $career->playerId());
        if ($event === null) { return $this->redirect(WebView::url('home', ['save' => $saveId])); }
        $data = $event->toArray(); $choices = ''; foreach ($event->choices() as $index => $choice) { $choices .= '<label class="choice-card"><input type="radio" name="choice" value="' . ($index + 1) . '" required><span><strong>' . ($index + 1) . '.</strong> ' . WebView::e($choice['label'] ?? 'Available choice') . '</span></label>'; }
        $body = '<div class="decision-shell"><div class="eyebrow">CAREER EVENT · ' . WebView::e(CareerLabels::value($data['category'] ?? null)) . '</div><h1>' . WebView::e($data['title'] ?? 'Career moment') . '</h1><p class="lead">' . WebView::e($data['description'] ?? 'A decision is waiting.') . '</p><form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel choice-panel" data-busy><input type="hidden" name="action" value="resolve_event"><input type="hidden" name="save" value="' . WebView::e($saveId) . '"><input type="hidden" name="event_id" value="' . WebView::e($event->id()) . '"><input type="hidden" name="token" value="' . WebView::e($this->issueToken($session, 'event_' . $saveId)) . '"><div class="choice-list">' . $choices . '</div><button class="button button-primary button-large" type="submit">Confirm choice</button></form></div>';

        return $this->html('Career Event', $body, $saveId, 'home', 200, $session);
    }

    private function decision(string $saveId, array &$session): array
    {
        $database = $this->database($saveId); $snapshot = $this->snapshot($saveId, $database); $decision = (new CareerPresentationService($this->services))->decision($snapshot['summary'], $database);
        if ($decision === null) { return $this->redirect(WebView::url('home', ['save' => $saveId])); }
        $options = ''; foreach ((array) ($decision['options'] ?? []) as $index => $option) { $club = is_array($option['club'] ?? null) ? ' · ' . ($option['club']['name'] ?? '') . (isset($option['club']['competition']) ? ' · ' . $option['club']['competition'] : '') : ''; $details = ''; if (($option['club_level'] ?? null) !== null) { $details .= ' · ' . (string) $option['club_level']; } if (($option['market_path'] ?? null) !== null) { $details .= ' · ' . (string) $option['market_path']; } if (($option['projected_role'] ?? null) !== null) { $details .= ' · ' . (string) $option['projected_role']; } if (($option['wage'] ?? null) !== null) { $details .= ' · GC ' . number_format((int) $option['wage']) . '/week'; } if (($option['european_qualification'] ?? false) === true) { $details .= ' · Europe'; } if (($option['reasons'] ?? []) !== []) { $details .= ' · ' . implode(', ', (array) $option['reasons']); } $options .= '<label class="choice-card"><input type="radio" name="choice" value="' . ($index + 1) . '" required><span><strong>' . ($index + 1) . '.</strong> ' . WebView::e($option['label'] ?? 'Available choice') . WebView::e($club . $details) . '</span></label>'; }
        $retirementDetails = ($decision['decision_kind'] ?? null) === 'retirement' ? '<div class="panel"><p><strong>Age:</strong> ' . WebView::e($decision['age'] ?? '—') . ' · <strong>Phase:</strong> ' . WebView::e(CareerLabels::value($decision['career_phase'] ?? null)) . ' · <strong>Role:</strong> ' . WebView::e(CareerLabels::value($decision['role'] ?? null, 'Not assigned')) . '</p><p><strong>Recent performance:</strong> ' . WebView::e(CareerLabels::value($decision['performance'] ?? null, 'Not enough evidence')) . ' · <strong>Career record:</strong> ' . WebView::e(((array) ($decision['career_stats'] ?? []))['appearances'] ?? 0) . ' appearances, ' . WebView::e(((array) ($decision['career_stats'] ?? []))['goals'] ?? 0) . ' goals, ' . WebView::e(((array) ($decision['career_stats'] ?? []))['assists'] ?? 0) . ' assists</p><p><strong>Honours:</strong> ' . WebView::e($decision['honours'] ?? 0) . ' · <strong>Awards:</strong> ' . WebView::e($decision['awards'] ?? 0) . '</p></div>' : '';
        $body = '<div class="decision-shell"><div class="eyebrow">CAREER DECISION · ' . WebView::e(CareerLabels::value($decision['decision_kind'] ?? null)) . '</div><h1>' . (($decision['decision_kind'] ?? null) === 'retirement' ? 'Your playing Career is at a boundary' : 'A decision is waiting') . '</h1><p class="lead">Current Club: ' . WebView::e($decision['current_club'] ?? 'Free Agent') . ' · ' . WebView::e(((array) ($decision['current_competition'] ?? []))['name'] ?? 'No competition') . '</p>' . $retirementDetails . '<form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel choice-panel" data-busy><input type="hidden" name="action" value="resolve_decision"><input type="hidden" name="save" value="' . WebView::e($saveId) . '"><input type="hidden" name="token" value="' . WebView::e($this->issueToken($session, 'decision_' . $saveId)) . '"><div class="choice-list">' . $options . '</div><button class="button button-primary button-large" type="submit">Confirm decision</button></form></div>';

        return $this->html('Career Decision', $body, $saveId, 'home', 200, $session);
    }

    private function matchday(string $saveId, string $matchId, array &$session): array
    {
        if ($matchId === '') {
            return $this->html('Match unavailable', $this->errorPage('This Match link is missing. Return to Career Home and choose an available Match.'), $saveId, 'home', 404, $session);
        }
        $database = $this->database($saveId); $snapshot = $this->snapshot($saveId, $database); $career = (new CareerPlayerRepository($database))->get($saveId);
        try {
            $match = (new MatchRepository($database))->get($matchId);
        } catch (\Throwable) {
            return $this->html('Match unavailable', $this->errorPage('That Match is no longer available in this Career. No simulation was rerun.'), $saveId, 'home', 404, $session);
        }
        if ($match->status() !== MatchStatus::Completed) {
            return $this->html('Match unavailable', $this->errorPage('This Match has not been completed yet. Return to Career Home for the next action.'), $saveId, 'home', 409, $session);
        }
        $view = (new CareerPresentationService($this->services))->matchday($database, $match, $career->playerId()->value(), (string) (((array) ($snapshot['summary']['current_club'] ?? []))['id'] ?? ''));
        $performance = (array) ($view['performance'] ?? []); $result = (array) ($view['result'] ?? []);
        $cupResolution = is_array($view['cup_resolution'] ?? null) ? $view['cup_resolution'] : null;
        $europeResolution = is_array($view['europe_resolution'] ?? null) ? $view['europe_resolution'] : null;
        $internationalResolution = is_array($view['international_resolution'] ?? null) ? $view['international_resolution'] : null;
        $competitionResolution = $internationalResolution ?? $europeResolution ?? $cupResolution;
        $cupContext = '';
        if ($competitionResolution !== null) {
            $cupContext = '<p class="metric-note">' . WebView::e((string) ($competitionResolution['stage'] ?? 'Competition round'))
                . ' · Regulation ' . (int) ($competitionResolution['regulation_home_goals'] ?? ($result['home_goals'] ?? 0)) . '-' . (int) ($competitionResolution['regulation_away_goals'] ?? ($result['away_goals'] ?? 0));
            if (($competitionResolution['extra_time_home_goals'] ?? 0) !== 0 || ($competitionResolution['extra_time_away_goals'] ?? 0) !== 0) {
                $cupContext .= ' · AET ' . (int) ($competitionResolution['aet_home_goals'] ?? 0) . '-' . (int) ($competitionResolution['aet_away_goals'] ?? 0);
            }
            $cupContext .= ($competitionResolution['shootout_home_goals'] ?? null) === null
                ? ' · Winner decided by ' . str_replace('_', ' ', (string) ($competitionResolution['decided_by'] ?? 'regulation')) . '.'
                : ' · Penalty shootout: ' . (int) $competitionResolution['shootout_home_goals'] . '-' . (int) $competitionResolution['shootout_away_goals'];
            $cupContext .= '</p>';
        }
        $progression = $view['international_progression'] ?? $view['europe_progression'] ?? $view['cup_progression'] ?? null;
        if (is_string($progression)) {
            $cupContext .= '<p class="result-badge">' . WebView::e($progression) . '</p>';
        }
        if (is_array($view['rival_context'] ?? null)) {
            $cupContext .= '<p class="metric-note">Facing rival: ' . WebView::e((string) ($view['rival_context']['name'] ?? 'Opponent')) . '</p>';
        }
        $stats = ''; foreach (['goals' => 'Goals', 'assists' => 'Assists', 'shots' => 'Shots', 'shots_on_target' => 'Shots on target', 'passes_completed' => 'Passes completed', 'passes_attempted' => 'Passes attempted', 'tackles' => 'Tackles', 'interceptions' => 'Interceptions', 'blocks' => 'Blocks', 'saves' => 'Saves', 'yellow_cards' => 'Yellow cards', 'red_cards' => 'Red cards'] as $key => $label) { if (array_key_exists($key, $performance) && $performance[$key] !== null) { $stats .= WebView::stat($label, $performance[$key]); } }
        $highlights = ''; foreach ((array) ($view['highlights'] ?? []) as $line) { $highlights .= '<li>' . WebView::e($line) . '</li>'; }
        $playerMoments = ''; foreach ($this->playerMomentLines((array) ($performance['player_highlight_facts'] ?? [])) as $line) { $playerMoments .= '<li>' . WebView::e($line) . '</li>'; }
        $explanation = (array) ($performance['rating_explanation'] ?? []); $factors = ''; foreach (array_merge(array_map(static fn (string $line): string => '+ ' . $line, (array) ($explanation['positive'] ?? [])), array_map(static fn (string $line): string => '− ' . $line, (array) ($explanation['negative'] ?? []))) as $line) { $factors .= '<li>' . WebView::e($line) . '</li>'; }
        $decision = is_array($performance['decisive_contribution'] ?? null) ? '<p class="result-badge">' . WebView::e((string) ($performance['decisive_contribution']['label'] ?? 'Decisive contribution')) . '</p>' : '';
        $potm = ($performance['player_of_match'] ?? false) === true ? '<p class="result-badge">Player of the Match</p>' : '';
        $participation = '<p class="tag">' . WebView::e($performance['participation_label'] ?? 'Match status unavailable') . '</p><p class="metric-note">' . WebView::e($performance['position_label'] ?? CareerLabels::position($performance['position'] ?? '')) . ($performance['substitution_on_minute'] === null ? '' : ' · Entered ' . (int) $performance['substitution_on_minute'] . "'") . ($performance['substitution_off_minute'] === null ? '' : ' · Left ' . (int) $performance['substitution_off_minute'] . "'") . ($performance['dismissal_minute'] === null ? '' : ' · Dismissed ' . (int) $performance['dismissal_minute'] . "'") . '</p>';
        $ratingPanel = WebView::section('RATING EXPLANATION', (string) ($performance['performance_label'] ?? 'Performance'), '<p><strong>' . WebView::e($this->rating($performance['rating'] ?? null)) . '</strong> from canonical Match evidence.</p>' . ($factors === '' ? WebView::emptyState('No additional rating factors recorded.') : '<ul class="fixture-list">' . $factors . '</ul>'));
        $impact = (array) (($view['post_match']['career_impact'] ?? [])['items'] ?? []); $impactHtml = $impact === [] ? WebView::emptyState('No material Career change recorded.') : '<ul class="fixture-list">' . implode('', array_map(static fn (string $line): string => '<li>' . WebView::e($line) . '</li>', $impact)) . '</ul>';
        $yourMatch = '<div class="match-player"><img class="portrait portrait-medium" src="' . WebView::e($this->portraitUrl($saveId, $career->playerId()->value(), 'matchday', 128)) . '" alt="Player portrait"><div>' . $participation . '<div class="stat-grid compact">' . WebView::stat('Minutes', $performance['minutes'] ?? 0) . WebView::stat('Rating', $this->rating($performance['rating'] ?? null)) . '</div></div></div><div class="stat-grid compact">' . $stats . '</div>' . $decision . $potm;
        $postMatch = (array) ($view['post_match'] ?? []); $postMatch['performance'] = $performance;
        $body = '<div class="match-header"><div class="eyebrow">MATCHDAY · ' . WebView::e($view['competition'] ?? '') . '</div><p>' . WebView::e($view['date'] ?? '') . '</p><div class="scoreline"><strong>' . WebView::e($view['home_club'] ?? '') . '</strong><span>' . WebView::e($result['home_goals'] ?? 0) . ' — ' . WebView::e($result['away_goals'] ?? 0) . '</span><strong>' . WebView::e($view['away_club'] ?? '') . '</strong></div><div class="result-badge">' . WebView::e(strtoupper((string) ($view['perspective_result'] ?? 'result'))) . '</div>' . $cupContext . '</div><div class="match-grid"><div>' . WebView::section('YOUR MATCH', (string) ($performance['player_name'] ?? 'Player'), $yourMatch) . $ratingPanel . WebView::section('YOUR MOMENTS', 'Player impact', $playerMoments === '' ? WebView::emptyState('No decisive Player moments recorded.') : '<ul class="fixture-list">' . $playerMoments . '</ul>') . WebView::section('MATCH STORY', 'Canonical timeline', $highlights === '' ? WebView::emptyState('No highlights recorded.') : '<ul class="fixture-list">' . $highlights . '</ul>') . '</div><aside>' . WebView::section('POST-MATCH', 'Updated context', $this->postMatch($postMatch)) . WebView::section('CAREER IMPACT', 'What changed', $impactHtml) . '</aside></div><div class="form-actions">' . WebView::link('home', ['save' => $saveId], 'Career Home', 'button button-primary') . WebView::link('training', ['save' => $saveId], 'Training') . '</div>';

        return $this->html('Matchday', $body, $saveId, 'home', 200, $session);
    }

    private function portrait(array $query, array $session): array
    {
        $size = (int) ($query['size'] ?? 256); $size = in_array($size, [32, 64, 128, 256, 512], true) ? $size : 256; $appearance = null; $context = [];
        if ((string) ($query['draft'] ?? '') === '1') {
            $raw = (string) ($query['spec'] ?? ''); $decoded = $raw === '' ? null : base64_decode($raw, true); $values = is_string($decoded) ? json_decode($decoded, true) : null; $values = is_array($values) ? $values : (((array) ($session[self::DRAFT] ?? []))['appearance'] ?? []); $appearance = PlayerAppearance::fromArray((array) $values); $context = ['label' => 'Avatar preview', 'background' => 'career'];
        } else {
            $saveId = $this->saveId($query['save'] ?? null); $playerId = (string) ($query['player'] ?? ''); if ($saveId === null || $playerId === '') { return $this->response('Portrait unavailable', 404, ['Content-Type' => 'text/plain']); }
            $database = $this->database($saveId); $worldService = $this->services->worldModule()->service(); $world = $worldService->load($database, $saveId); $player = (new PlayerRepository($database))->get($playerId); $club = null; $membership = $this->services->clubModule()->service()->squadRepository($database)->byPlayer($playerId, $world->currentSeasonId())[0] ?? null; if ($membership !== null) { $club = $this->services->clubModule()->service()->repository($database)->get($membership->clubId()); } $appearance = (new PlayerAppearanceService())->getOrGenerate($database, $player, $world->currentDate($worldService->calendar())); $context = PortraitContext::forPlayer($player, $world->currentDate($worldService->calendar()), $club, (string) ($query['context'] ?? 'career'), (string) ($query['expression'] ?? 'neutral'));
        }
        $renderer = new PortraitRenderer();
        if ((string) ($query['draft'] ?? '') === '1') {
            $svg = $renderer->renderSvg($appearance, $context, $size);
        } else {
            $path = $renderer->render($appearance, $context, $size);
            $svg = file_get_contents($path);
            if ($svg === false) { throw new RuntimeException('Portrait cache could not be read.'); }
        }

        return $this->response($svg, 200, ['Content-Type' => 'image/svg+xml; charset=UTF-8', 'Cache-Control' => 'private, max-age=31536000']);
    }

    private function seasonFacts(array $summary): string
    {
        $stats = (array) ($summary['season_stats'] ?? []);
        return '<div class="stat-grid">' . WebView::stat('Appearances', $stats['appearances'] ?? 0) . WebView::stat('Starts', $stats['starts'] ?? 0) . WebView::stat('Minutes', $stats['minutes'] ?? 0) . WebView::stat('Goals', $stats['goals'] ?? 0) . WebView::stat('Assists', $stats['assists'] ?? 0) . WebView::stat('Average rating', $this->rating($stats['average_match_rating'] ?? null)) . '</div><p class="metric-note">Recent form: ' . WebView::e($this->formLabel((array) ($summary['recent_form'] ?? []))) . ' · Outlook: ' . WebView::e(CareerLabels::value(((array) ($summary['career_outlook'] ?? []))['category'] ?? null, 'Not available')) . '</p>';
    }

    private function postMatch(array $post): string
    {
        $stats = (array) ($post['season_stats'] ?? []); $form = (array) ($post['recent_form'] ?? []); $performance = (array) ($post['performance'] ?? []); $readiness = (array) ($post['readiness'] ?? []); $manager = (array) ($post['manager_context'] ?? []); $impact = (array) (($post['career_impact'] ?? [])['items'] ?? []); $impactText = $impact === [] ? 'No material change recorded' : implode(' · ', array_slice(array_map('strval', $impact), 0, 2)); return '<div class="split-list"><p><span>Match performance</span><strong>' . WebView::e($performance['performance_label'] ?? 'No rating') . '</strong></p><p><span>Recent form</span><strong>' . WebView::e($this->formLabel($form)) . '</strong></p><p><span>Readiness after Match</span><strong>' . WebView::e(CareerLabels::value($readiness['label'] ?? null, 'Ready')) . '</strong></p><p><span>Selection context</span><strong>' . WebView::e($manager['selection_context'] ?? 'Role and availability apply') . '</strong></p><p><span>Season average</span><strong>' . WebView::e($this->rating($stats['average_match_rating'] ?? null)) . '</strong></p><p><span>League position</span><strong>' . WebView::e($post['club_position'] ?? 'Not available') . '</strong></p><p><span>Career impact</span><strong>' . WebView::e($impactText) . '</strong></p></div>';
    }

    /** @param list<array<string, mixed>> $facts @return list<string> */
    private function playerMomentLines(array $facts): array
    {
        $lines = [];
        foreach ($facts as $fact) {
            $kind = (string) ($fact['kind'] ?? '');
            $lines[] = match ($kind) {
                'goal' => 'Scored at ' . (int) ($fact['minute'] ?? 0) . "'",
                'assist' => 'Provided an assist at ' . (int) ($fact['minute'] ?? 0) . "'",
                'yellow_card' => 'Booked at ' . (int) ($fact['minute'] ?? 0) . "'",
                'red_card' => 'Sent off at ' . (int) ($fact['minute'] ?? 0) . "'",
                'substitution' => (($fact['direction'] ?? '') === 'in' ? 'Entered the Match at ' : 'Left the Match at ') . (int) ($fact['minute'] ?? 0) . "'",
                'saves' => 'Made ' . (int) ($fact['count'] ?? 0) . ' saves',
                'shots_on_target' => 'Recorded ' . (int) ($fact['count'] ?? 0) . ' shot' . ((int) ($fact['count'] ?? 0) === 1 ? '' : 's') . ' on target',
                'defending' => 'Made ' . (int) ($fact['tackles'] ?? 0) . ' tackles, ' . (int) ($fact['interceptions'] ?? 0) . ' interceptions and ' . (int) ($fact['blocks'] ?? 0) . ' blocks',
                'passing' => 'Completed ' . (int) ($fact['completed'] ?? 0) . '/' . (int) ($fact['attempted'] ?? 0) . ' passes',
                'clean_sheet' => 'Helped keep a clean sheet',
                default => null,
            };
        }

        return array_values(array_filter($lines, static fn (?string $line): bool => $line !== null));
    }

    private function contextActions(string $saveId, array $summary): string
    {
        $html = '<div class="context-actions">'; $transfer = (array) ($summary['transfer_request'] ?? []); $status = (string) ($transfer['status'] ?? '');
        foreach ((array) ($summary['available_actions'] ?? []) as $action) { if (!is_array($action)) { continue; } $type = $action['type'] ?? ''; if ($type === 'request_transfer' && $status !== 'requested') { $html .= WebView::form('request_transfer', 'Request transfer', ['save' => $saveId, 'confirm' => '1'], 'button button-secondary', 'onsubmit="return confirm(\'Request a transfer?\')" data-busy'); } if ($type === 'withdraw_transfer_request' && $status === 'requested') { $html .= WebView::form('withdraw_transfer', 'Withdraw transfer request', ['save' => $saveId], 'button button-secondary', 'data-busy'); } }
        return $html . '</div>';
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $session @param array<string, mixed>|null $next */
    private function primaryCareerAction(string $saveId, array $summary, array &$session, ?array $next): string
    {
        if (($summary['career_state'] ?? 'active') === 'retired') {
            return WebView::link('legacy', ['save' => $saveId], 'Career Complete — Open Legacy', 'button button-primary button-large');
        }
        if (($summary['pending_decisions'] ?? []) !== []) {
            return WebView::link('decision', ['save' => $saveId], 'Resolve career decision', 'button button-primary button-large');
        }
        if (($summary['pending_career_event'] ?? null) !== null) {
            return WebView::link('event', ['save' => $saveId], 'Resolve career event', 'button button-primary button-large');
        }
        $label = $next === null ? 'Continue Career' : 'Continue to next fixture';

        return WebView::form('continue', $label, ['save' => $saveId, 'token' => $this->issueToken($session, 'continue_' . $saveId)], 'button button-primary button-large', 'data-busy');
    }

    private function latestControlledMatch(DatabaseInterface $database, array $summary, SimulationDate $date): ?GameMatch
    {
        $clubId = (string) (((array) ($summary['current_club'] ?? []))['id'] ?? ''); $seasonId = (string) ($summary['current_season_id'] ?? ''); if ($clubId === '' || $seasonId === '') { return null; }
        $matches = array_values(array_filter((new MatchRepository($database))->byClub($clubId, new \Goal\Legacy\Modules\World\Domain\SeasonId($seasonId)), static fn (GameMatch $match): bool => $match->status() === MatchStatus::Completed && !$match->scheduledDate()->isAfter($date)));
        usort($matches, static fn (GameMatch $left, GameMatch $right): int => strcmp($right->scheduledDate()->toIsoString() . $right->id()->value(), $left->scheduledDate()->toIsoString() . $left->id()->value()));
        return $matches[0] ?? null;
    }

    private function careerRequest(array $draft, string $position, string $archetype, int $seed): CareerStartRequest
    {
        return new CareerStartRequest((string) $draft['save'], (string) $draft['name'], (string) $draft['nation'], (int) $draft['height'], (int) $draft['weight'], $position, $archetype, $seed);
    }

    private function ensureDraftAppearance(array &$session): void
    {
        $draft = (array) ($session[self::DRAFT] ?? []); if (isset($draft['appearance']) && is_array($draft['appearance'])) { return; }
        $catalog = new AvatarCatalog(); $draft['appearance'] = (array) (($catalog->presets()[0] ?? [])['appearance'] ?? []); $session[self::DRAFT] = $draft;
    }

    private function validatedAppearance(array $post, AvatarCatalog $catalog): PlayerAppearance
    {
        $map = ['skin_tone' => ['palette', 'skin'], 'face' => ['asset', 'face'], 'jaw' => ['asset', 'jaw'], 'ears' => ['asset', 'ears'], 'eyes' => ['asset', 'eyes'], 'eye_color' => ['palette', 'eye'], 'brows' => ['asset', 'brows'], 'nose' => ['asset', 'nose'], 'mouth' => ['asset', 'mouth'], 'hair' => ['asset', 'hair'], 'hair_color' => ['palette', 'hair'], 'facial_hair' => ['asset', 'facial_hair'], 'facial_hair_color' => ['palette', 'hair'], 'skin_detail' => ['asset', 'skin_detail'], 'scar' => ['asset', 'scar'], 'accessory' => ['asset', 'accessory']]; $values = [];
        foreach ($map as $field => [$kind, $category]) { $value = trim((string) ($post[$field] ?? '')); $valid = $kind === 'palette' ? array_filter($catalog->palettes($category), static fn (array $row): bool => ($row['id'] ?? '') === $value) !== [] : ($catalog->asset($value)['category'] ?? null) === $category; if (!$valid) { throw new RuntimeException('Choose a valid Avatar option for ' . str_replace('_', ' ', $field) . '.'); } $values[$field] = $value; }
        return PlayerAppearance::fromArray($values);
    }

    private function randomAppearance(AvatarCatalog $catalog): array
    {
        $values = []; foreach (['skin_tone' => ['palette', 'skin'], 'face' => ['asset', 'face'], 'jaw' => ['asset', 'jaw'], 'ears' => ['asset', 'ears'], 'eyes' => ['asset', 'eyes'], 'eye_color' => ['palette', 'eye'], 'brows' => ['asset', 'brows'], 'nose' => ['asset', 'nose'], 'mouth' => ['asset', 'mouth'], 'hair' => ['asset', 'hair'], 'hair_color' => ['palette', 'hair'], 'facial_hair' => ['asset', 'facial_hair'], 'facial_hair_color' => ['palette', 'hair'], 'skin_detail' => ['asset', 'skin_detail'], 'scar' => ['asset', 'scar'], 'accessory' => ['asset', 'accessory']] as $field => [$kind, $category]) { $values[$field] = $this->randomAppearanceField($catalog, $field, $kind, $category); } return $values;
    }

    private function randomAppearanceField(AvatarCatalog $catalog, string $field, ?string $kind = null, ?string $category = null): string
    {
        $map = ['skin_tone' => ['palette', 'skin'], 'face' => ['asset', 'face'], 'jaw' => ['asset', 'jaw'], 'ears' => ['asset', 'ears'], 'eyes' => ['asset', 'eyes'], 'eye_color' => ['palette', 'eye'], 'brows' => ['asset', 'brows'], 'nose' => ['asset', 'nose'], 'mouth' => ['asset', 'mouth'], 'hair' => ['asset', 'hair'], 'hair_color' => ['palette', 'hair'], 'facial_hair' => ['asset', 'facial_hair'], 'facial_hair_color' => ['palette', 'hair'], 'skin_detail' => ['asset', 'skin_detail'], 'scar' => ['asset', 'scar'], 'accessory' => ['asset', 'accessory']]; [$kind, $category] = $map[$field] ?? [$kind ?? 'asset', $category ?? $field]; $items = $kind === 'palette' ? $catalog->palettes($category) : array_values(array_filter($catalog->assets($category), static fn (array $item): bool => (bool) ($item['creator_enabled'] ?? false))); if ($items === []) { throw new RuntimeException('No Avatar options are available for ' . $field . '.'); } return (string) $items[array_rand($items)]['id'];
    }

    private function draft(array $session): array
    {
        $draft = $session[self::DRAFT] ?? null; if (!is_array($draft)) { throw new RuntimeException('Start a new career first.'); } return $draft;
    }

    private function database(string $saveId): DatabaseInterface { return $this->services->saveStore()->openDatabase($saveId); }
    private function requiredSave(array $values): string { $save = $this->saveId($values['save'] ?? null); if ($save === null) { throw new RuntimeException('A valid career is required.'); } return $save; }
    private function saveId(mixed $value): ?string { $save = trim((string) ($value ?? '')); return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $save) === 1 ? $save : null; }
    private function portraitUrl(string $saveId, string $playerId, string $context, int $size): string { return WebView::url('portrait', ['save' => $saveId, 'player' => $playerId, 'context' => $context, 'size' => $size]); }
    /** @param list<string> $items */
    private function fixtureList(array $items, string $empty): string
    {
        if ($items === []) { return WebView::emptyState($empty); }
        $html = '<ul class="fixture-list">';
        foreach ($items as $item) { $html .= '<li>' . WebView::e($item) . '</li>'; }

        return $html . '</ul>';
    }
    private function positionGroup(PlayerPosition $position): string
    {
        return match ($position) {
            PlayerPosition::Goalkeeper => 'Goalkeepers',
            PlayerPosition::CentreBack, PlayerPosition::LeftBack, PlayerPosition::RightBack => 'Defenders',
            PlayerPosition::DefensiveMidfielder, PlayerPosition::CentralMidfielder, PlayerPosition::AttackingMidfielder => 'Midfielders',
            PlayerPosition::LeftWinger, PlayerPosition::RightWinger, PlayerPosition::Striker => 'Forwards',
        };
    }
    private function rating(mixed $value): string { return $value === null || $value === '' ? 'Not available' : number_format((float) $value, 1); }
    private function money(int $amount): string { return 'GC ' . number_format($amount); }
    private function financeType(string $type): string { return match ($type) { 'opening_balance' => 'Career start', 'wage' => 'Wage', 'purchase' => 'Purchase', 'event_income' => 'Career event income', 'event_expense' => 'Career event expense', default => 'Finance activity' }; }
    private function effectLabel(string $effect): string { return match ($effect) { 'recovery_support' => 'Recovery support', 'training_support' => 'Training support', 'lifestyle_event_weight' => 'Lifestyle context', 'professional_event_weight' => 'Professional context', 'media_event_weight' => 'Media context', 'community_event_weight' => 'Community context', 'travel_convenience' => 'Travel convenience', default => 'Career context' }; }
    private function formLabel(array $form): string { return isset($form['classification']) ? CareerLabels::value($form['classification'], 'Not enough matches yet') : 'Not enough matches yet'; }
    private function contractText(mixed $contract): string { if (!is_array($contract)) { return 'No active Contract'; } $status = CareerLabels::value($contract['status'] ?? 'Contract'); $end = trim((string) ($contract['end_date'] ?? '')); return $end === '' ? $status : $status . ' through ' . $end; }
    private function issueToken(array &$session, string $key): string { if (!isset($session['web_tokens'][$key])) { $session['web_tokens'][$key] = bin2hex(random_bytes(16)); } return (string) $session['web_tokens'][$key]; }
    private function consumeToken(array &$session, string $key, string $token): bool { $expected = (string) ($session['web_tokens'][$key] ?? ''); if ($expected === '' || $token === '' || !hash_equals($expected, $token)) { return false; } unset($session['web_tokens'][$key]); return true; }
    private function html(string $title, string $body, ?string $saveId, string $active, int $status, array &$session): array
    {
        if (str_starts_with(ltrim($body), '<!doctype html>')) {
            $flash = isset($session['web_flash']) ? (string) $session['web_flash'] : null;
            unset($session['web_flash']);
            if ($flash !== null && trim($flash) !== '') {
                $flashHtml = '<div class="flash" role="status">' . WebView::e($flash) . '</div>';
                $body = str_replace('<main class="page-shell">', '<main class="page-shell">' . $flashHtml, $body, $count);
                if ($count === 0) { $body = str_replace('<main class="page-shell">', '<main class="page-shell">' . $flashHtml, $body); }
            }

            return $this->response($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        $flash = isset($session['web_flash']) ? (string) $session['web_flash'] : null;
        unset($session['web_flash']);
        return $this->response(WebView::layout($title, $body, $saveId, $active, $flash), $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
    private function redirect(string $location): array { return $this->response('', 303, ['Location' => $location]); }
    private function response(string $body, int $status, array $headers = []): array { return ['status' => $status, 'headers' => $headers, 'body' => $body]; }
    private function errorPage(string $message): string { return '<div class="error-page"><div class="eyebrow">SOMETHING WENT WRONG</div><h1>We could not complete that action.</h1><p>' . WebView::e($message) . '</p>' . WebView::link('menu', [], 'Return to Main Menu', 'button button-primary') . '</div>'; }
}
