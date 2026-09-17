<?php

declare(strict_types=1);

namespace Goal\Legacy\Web;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Devtools\BufferedConsoleOutput;
use Goal\Legacy\Devtools\Commands\CareerContinueCommand;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Devtools\Presentation\CareerLabels;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
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
            'appearance' => $this->existingAppearance($saveId, $session),
            'squad' => $this->squad($saveId, $session, isset($query['club']) ? (string) $query['club'] : null),
            'profile' => $this->profile($saveId, (string) ($query['player'] ?? ''), $session),
            'world' => $this->world($saveId, $session),
            'competition' => $this->competition($saveId, (string) ($query['competition'] ?? ''), $session),
            'club' => $this->club($saveId, (string) ($query['club'] ?? ''), $session),
            'news' => $this->news($saveId, $session),
            'training' => $this->training($saveId, $session),
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
                'set_priority' => $this->setPriority($post, $session),
                'continue' => $this->continueCareer($post, $session),
                'save_exit' => $this->saveExit($session),
                'resolve_event' => $this->resolveEvent($post, $session),
                'resolve_decision' => $this->resolveDecision($post, $session),
                'request_transfer' => $this->transferRequest($post, $session, false),
                'withdraw_transfer' => $this->transferRequest($post, $session, true),
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
        return (new CareerPresentationService($this->services))->snapshot($database, $saveId);
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
        $next = (new CareerPresentationService($this->services))->nextMatch($database, $summary);
        $clubContext = (new CareerPresentationService($this->services))->clubContext($database, $summary);
        $profile = '<div class="profile-hero">' . WebView::portrait($portrait, (string) ($player['preferred_name'] ?? 'Player'), 'portrait portrait-large') . '<div><div class="eyebrow">CAREER HOME · ' . WebView::e($snapshot['date']->toIsoString()) . '</div><h1>' . WebView::e($player['preferred_name'] ?? 'Player') . '</h1><p>' . WebView::e(CareerLabels::position($player['primary_position'] ?? null)) . ' · ' . WebView::e(CareerLabels::nationality($player['primary_nation_id'] ?? null)) . '</p><div class="tag-row"><span class="tag">OVR ' . WebView::e($summary['current_ovr'] ?? '—') . '</span><span class="tag">' . WebView::e($club['name'] ?? 'Free Agent') . '</span><span class="tag">' . WebView::e(CareerLabels::value($summary['current_role'] ?? null)) . '</span></div></div></div>';
        $seasonBody = '<div class="stat-grid">' . WebView::stat('Appearances', $season['appearances'] ?? 0) . WebView::stat('Starts', $season['starts'] ?? 0) . WebView::stat('Minutes', $season['minutes'] ?? 0) . WebView::stat('Goals', $season['goals'] ?? 0) . WebView::stat('Assists', $season['assists'] ?? 0) . WebView::stat('Rating', $this->rating($season['average_match_rating'] ?? null)) . '</div><div class="metric-lines"><p><strong>Recent form</strong> ' . WebView::e($this->formLabel($form)) . '</p><p><strong>Season performance</strong> ' . WebView::e(CareerLabels::value($performance['classification'] ?? null, 'Not enough evidence')) . '</p></div>';
        $situation = '<div class="split-list"><p><span>Training focus</span><strong>' . WebView::e(CareerLabels::value($summary['training_focus'] ?? 'balanced')) . '</strong></p><p><span>Career priority</span><strong>' . WebView::e(CareerLabels::value($summary['priority'] ?? 'balanced')) . '</strong></p><p><span>Contract</span><strong>' . WebView::e($this->contractText($summary['current_contract'] ?? null)) . '</strong></p><p><span>Career outlook</span><strong>' . WebView::e(CareerLabels::value(((array) ($summary['career_outlook'] ?? []))['category'] ?? null, 'Not available')) . '</strong></p></div>';
        $nextBody = $next === null ? WebView::emptyState('No upcoming fixture currently scheduled.') : '<div class="fixture-card"><span class="eyebrow">' . WebView::e($next['competition'] ?? 'Fixture') . '</span><strong>' . WebView::e($next['home_club'] ?? '') . ' <span>vs</span> ' . WebView::e($next['away_club'] ?? '') . '</strong><small>' . WebView::e($next['date'] ?? '') . '</small></div>';
        $clubBody = $clubContext === null ? WebView::emptyState('League position is not available for this fixture context.') : '<div class="stat-grid compact">' . WebView::stat('Position', $clubContext['position'] ?? '—') . WebView::stat('Played', $clubContext['played'] ?? 0) . WebView::stat('Points', $clubContext['points'] ?? 0) . '</div>';
        $actions = '<div class="action-grid">' . WebView::form('continue', 'Continue', ['save' => $saveId, 'token' => $this->issueToken($session, 'continue_' . $saveId)], 'button button-primary button-large', 'data-busy') . WebView::link('career', ['save' => $saveId], 'Career') . WebView::link('squad', ['save' => $saveId], 'Squad') . WebView::link('world', ['save' => $saveId], 'World') . WebView::link('news', ['save' => $saveId], 'News') . WebView::link('training', ['save' => $saveId], 'Training') . '</div>';
        $actions .= $this->contextActions($saveId, $summary) . WebView::form('save_exit', 'Save & Exit', ['save' => $saveId], 'button button-secondary', 'data-busy');
        $body = $profile . '<div class="dashboard-grid"><div class="dashboard-main">' . WebView::section('CURRENT SEASON', $snapshot['summary']['current_season_label'] ?? 'Current Season', $seasonBody) . WebView::section('NEXT MATCH', 'What is coming next', $nextBody) . WebView::section('CLUB', $club['name'] ?? 'Free Agent', $clubBody) . '</div><aside class="dashboard-side">' . WebView::section('CAREER SITUATION', 'Your direction', $situation) . WebView::section('ACTIONS', 'Play', $actions) . '</aside></div>';

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
        $movement = '';
        foreach ((array) ($summary['movement_history'] ?? []) as $row) { if (is_array($row)) { $movement .= '<li><strong>' . WebView::e($row['date'] ?? '') . '</strong> ' . WebView::e($row['type'] ?? 'Career movement') . ' · ' . WebView::e($row['club']['name'] ?? $row['to_club'] ?? '') . '</li>'; } }
        $movement = $movement === '' ? WebView::emptyState('No movement recorded yet.') : '<ul class="timeline">' . $movement . '</ul>';
        $life = '';
        foreach ((array) ($summary['career_life_history'] ?? []) as $row) { if (is_array($row)) { $life .= '<li><strong>' . WebView::e($row['date'] ?? '') . '</strong> ' . WebView::e(((array) ($row['consequence'] ?? []))['history'] ?? $row['title'] ?? 'Career moment') . '</li>'; } }
        $life = $life === '' ? WebView::emptyState('No off-pitch milestones yet.') : '<ul class="timeline">' . $life . '</ul>';
        $body = '<div class="page-heading"><div><div class="eyebrow">CAREER</div><h1>' . WebView::e($player['preferred_name'] ?? 'Player') . '</h1><p>' . WebView::e(CareerLabels::position($player['primary_position'] ?? null)) . ' · OVR ' . WebView::e($summary['current_ovr'] ?? '—') . '</p></div>' . WebView::portrait($this->portraitUrl($saveId, (string) ($player['id'] ?? ''), 'career', 128), 'Player portrait', 'portrait portrait-medium') . '</div>';
        $body .= '<div class="dashboard-grid"><div class="dashboard-main">' . WebView::section('CURRENT SEASON', $snapshot['summary']['current_season_label'] ?? 'Current Season', $this->seasonFacts($summary)) . WebView::section('SEASON HISTORY', 'Record', $history) . '</div><aside class="dashboard-side">' . WebView::section('MOVEMENT HISTORY', 'Clubs', $movement) . WebView::section('OFF-PITCH LIFE', 'Milestones', $life) . WebView::section('PROFILE', 'Appearance', WebView::link('appearance', ['save' => $saveId], 'Customize appearance', 'button button-secondary')) . '</aside></div>';

        return $this->html('Career', $body, $saveId, 'career', 200, $session);
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
        $careerStats = (array) ($data['career_stats'] ?? []);
        $form = (array) ($data['recent_form'] ?? []);
        $clubLink = $club === null ? 'Free Agent' : WebView::link('club', ['save' => $saveId, 'club' => $club->id()->value()], $club->canonicalName(), 'text-link');
        $facts = '<div class="stat-grid compact">' . WebView::stat('OVR', $player->overallRating()) . WebView::stat('Age', $data['age']) . WebView::stat('Position', CareerLabels::position($player->primaryPosition()->value)) . WebView::stat('Nationality', $data['nationality']) . WebView::stat('Role', CareerLabels::value($data['role'] ?? null, 'Not assigned')) . WebView::stat('Season', $data['season_id']) . '</div>';
        $seasonLine = '<div class="stat-grid compact">' . WebView::stat('Appearances', $stats['appearances'] ?? 0) . WebView::stat('Starts', $stats['starts'] ?? 0) . WebView::stat('Minutes', $stats['minutes'] ?? 0) . WebView::stat('Goals', $stats['goals'] ?? 0) . WebView::stat('Assists', $stats['assists'] ?? 0) . WebView::stat('Rating', $this->rating($stats['average_match_rating'] ?? null)) . '</div>';
        $extras = '<p><strong>Recent form:</strong> ' . WebView::e($this->formLabel($form)) . '</p>';
        if (($data['controlled'] ?? false) === true) {
            $extras .= '<p><strong>Training focus:</strong> ' . WebView::e(CareerLabels::value($data['training_focus'] ?? null)) . ' · <strong>Priority:</strong> ' . WebView::e(CareerLabels::value($data['priority'] ?? null)) . ' · <strong>Contract:</strong> ' . WebView::e($this->contractText($data['contract'] ?? null)) . '</p>';
        }
        $careerLine = '<div class="stat-grid compact">' . WebView::stat('Career apps', $careerStats['appearances'] ?? 0) . WebView::stat('Career goals', $careerStats['goals'] ?? 0) . WebView::stat('Career assists', $careerStats['assists'] ?? 0) . WebView::stat('Cards', ((int) ($careerStats['yellow_cards'] ?? 0)) . 'Y / ' . ((int) ($careerStats['red_cards'] ?? 0)) . 'R') . '</div>';
        $history = '';
        foreach ((array) ($data['match_history'] ?? []) as $match) {
            if (!is_array($match)) { continue; }
            $line = ($match['date'] ?? '') . ' · ' . ($match['home'] ?? '') . ' ' . ($match['home_goals'] ?? 0) . '-' . ($match['away_goals'] ?? 0) . ' ' . ($match['away'] ?? '');
            if (($match['detailed'] ?? false) === true && $match['minutes'] !== null) { $line .= ' · ' . $match['minutes'] . ' min · Rating ' . $this->rating($match['rating'] ?? null); }
            $history .= '<li>' . WebView::e($line) . '</li>';
        }
        $historyBody = $history === '' ? WebView::emptyState('No completed Match history in this Season.') : '<ul class="timeline compact-timeline">' . $history . '</ul>';
        $body = '<div class="profile-hero profile-hero-profile">' . WebView::portrait($this->portraitUrl($saveId, $playerId, 'club', 256), $player->preferredName(), 'portrait portrait-large') . '<div><div class="eyebrow">PLAYER PROFILE</div><h1>' . WebView::e($player->preferredName()) . '</h1><p>' . WebView::e($data['age'] . ' years · ' . $data['nationality'] . ' · ') . $clubLink . '</p>' . $facts . '</div></div>' . WebView::section('CURRENT SEASON', 'Factual Season line', $seasonLine . $extras) . WebView::section('CAREER TOTALS', 'Recorded career evidence', $careerLine) . WebView::section('MATCH HISTORY', 'Recent canonical results', $historyBody) . '<div class="form-actions">' . WebView::link('squad', ['save' => $saveId, 'club' => $club?->id()->value()], 'Back to Squad') . '</div>';

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
        $competitionId = (string) (((array) ($snapshot['summary']['current_competition'] ?? []))['id'] ?? '');
        $competitionTitle = $competitionId === '' ? WebView::e($world['competition'] ?? 'Football World') : '<a class="text-link" href="' . WebView::e(WebView::url('competition', ['save' => $saveId, 'competition' => $competitionId])) . '">' . WebView::e($world['competition'] ?? 'Football World') . '</a>';
        $body = '<div class="page-heading"><div><div class="eyebrow">WORLD</div><h1>' . $competitionTitle . '</h1><p>Current competition context, standings, results, and fixtures.</p></div></div>' . WebView::section('STANDINGS', 'League table', $table) . '<div class="two-column">' . WebView::section('RECENT RESULTS', 'What just happened', $recent) . WebView::section('UPCOMING FIXTURES', 'What comes next', $upcoming) . '</div>';

        return $this->html('World', $body, $saveId, 'world', 200, $session);
    }

    private function competition(string $saveId, string $competitionId, array &$session): array
    {
        $database = $this->database($saveId);
        $snapshot = $this->snapshot($saveId, $database);
        $competitionId = $competitionId !== '' ? $competitionId : (string) (((array) ($snapshot['summary']['current_competition'] ?? []))['id'] ?? '');
        if ($competitionId === '') { return $this->redirect(WebView::url('world', ['save' => $saveId])); }
        $controlledClubId = (string) (((array) ($snapshot['summary']['current_club'] ?? []))['id'] ?? '');
        $view = (new CareerPresentationService($this->services))->competitionView($database, $competitionId, new SeasonId((string) $snapshot['summary']['current_season_id']), $snapshot['date'], $controlledClubId);
        $competition = $view['competition'];
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
        $recent = $this->fixtureList($view['recent_results'] ?? [], 'No completed results yet.');
        $upcoming = $this->fixtureList($view['upcoming_fixtures'] ?? [], 'No upcoming fixtures currently scheduled.');
        $body = '<div class="page-heading"><div><div class="eyebrow">CLUB</div><h1>' . WebView::e($club->canonicalName()) . '</h1><p>' . WebView::e($club->city()) . ' · ' . WebView::e($nation?->displayName() ?? 'Football world') . ($competition === null ? '' : ' · ' . WebView::e($competition->name())) . '</p></div>' . WebView::link('competition', ['save' => $saveId, 'competition' => $competition?->id()->value()], 'Back to Competition') . '</div>' . WebView::section('CLUB CONTEXT', 'Current position', '<div class="stat-grid compact">' . WebView::stat('League position', $position) . WebView::stat('Competition', $competition?->name() ?? 'Not available') . WebView::stat('Tier', $competition?->tier() ?? '—') . '</div>') . '<div class="two-column">' . WebView::section('RECENT RESULTS', 'Club results', $recent) . WebView::section('UPCOMING FIXTURES', 'Club schedule', $upcoming) . '</div>' . WebView::section('SQUAD', 'Current Players', WebView::link('squad', ['save' => $saveId, 'club' => $clubId], 'Open Squad'));

        return $this->html('Club', $body, $saveId, 'world', 200, $session);
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
        $focus = (string) ($summary['training_focus'] ?? 'balanced');
        $priority = (string) ($summary['priority'] ?? 'balanced');
        $focusOptions = ''; foreach (TrainingFocus::cases() as $item) { $focusOptions .= '<option value="' . $item->value . '"' . ($focus === $item->value ? ' selected' : '') . '>' . WebView::e(CareerLabels::value($item->value)) . '</option>'; }
        $priorityOptions = ''; foreach (CareerPriority::cases() as $item) { $priorityOptions .= '<option value="' . $item->value . '"' . ($priority === $item->value ? ' selected' : '') . '>' . WebView::e(CareerLabels::value($item->value)) . '</option>'; }
        $body = '<div class="page-heading"><div><div class="eyebrow">TRAINING & PRIORITIES</div><h1>Shape the next block</h1><p>These choices feed the canonical development and career-event systems.</p></div></div><div class="two-column"><form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel form-panel" data-busy><input type="hidden" name="action" value="set_training"><input type="hidden" name="save" value="' . WebView::e($saveId) . '"><label>Training focus<select name="focus">' . $focusOptions . '</select></label><p class="muted">Focus influences where existing development progress is directed.</p><button class="button button-primary" type="submit">Save training focus</button></form><form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel form-panel" data-busy><input type="hidden" name="action" value="set_priority"><input type="hidden" name="save" value="' . WebView::e($saveId) . '"><label>Career priority<select name="priority">' . $priorityOptions . '</select></label><p class="muted">Priority shapes the emphasis of bounded off-pitch opportunities.</p><button class="button button-primary" type="submit">Save priority</button></form></div>';

        return $this->html('Training', $body, $saveId, 'training', 200, $session);
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
        $options = ''; foreach ((array) ($decision['options'] ?? []) as $index => $option) { $club = is_array($option['club'] ?? null) ? ' · ' . ($option['club']['name'] ?? '') . (isset($option['club']['competition']) ? ' · ' . $option['club']['competition'] : '') : ''; $options .= '<label class="choice-card"><input type="radio" name="choice" value="' . ($index + 1) . '" required><span><strong>' . ($index + 1) . '.</strong> ' . WebView::e($option['label'] ?? 'Available choice') . WebView::e($club) . '</span></label>'; }
        $body = '<div class="decision-shell"><div class="eyebrow">CAREER DECISION · ' . WebView::e(CareerLabels::value($decision['decision_kind'] ?? null)) . '</div><h1>A decision is waiting</h1><p class="lead">Current Club: ' . WebView::e($decision['current_club'] ?? 'Free Agent') . ' · ' . WebView::e(((array) ($decision['current_competition'] ?? []))['name'] ?? 'No competition') . '</p><form method="post" action="' . WebView::e(WebView::url('action')) . '" class="panel choice-panel" data-busy><input type="hidden" name="action" value="resolve_decision"><input type="hidden" name="save" value="' . WebView::e($saveId) . '"><input type="hidden" name="token" value="' . WebView::e($this->issueToken($session, 'decision_' . $saveId)) . '"><div class="choice-list">' . $options . '</div><button class="button button-primary button-large" type="submit">Confirm decision</button></form></div>';

        return $this->html('Career Decision', $body, $saveId, 'home', 200, $session);
    }

    private function matchday(string $saveId, string $matchId, array &$session): array
    {
        $database = $this->database($saveId); $snapshot = $this->snapshot($saveId, $database); $career = (new CareerPlayerRepository($database))->get($saveId); $match = (new MatchRepository($database))->get($matchId);
        $view = (new CareerPresentationService($this->services))->matchday($database, $match, $career->playerId()->value(), (string) (((array) ($snapshot['summary']['current_club'] ?? []))['id'] ?? ''));
        $performance = (array) ($view['performance'] ?? []); $result = (array) ($view['result'] ?? []);
        $stats = ''; foreach (['goals' => 'Goals', 'assists' => 'Assists', 'shots' => 'Shots', 'shots_on_target' => 'Shots on target', 'passes_completed' => 'Passes', 'tackles' => 'Tackles', 'interceptions' => 'Interceptions', 'blocks' => 'Blocks', 'saves' => 'Saves', 'yellow_cards' => 'Yellow cards', 'red_cards' => 'Red cards'] as $key => $label) { if (array_key_exists($key, $performance) && $performance[$key] !== null) { $stats .= WebView::stat($label, $performance[$key]); } }
        $highlights = ''; foreach ((array) ($view['highlights'] ?? []) as $line) { $highlights .= '<li>' . WebView::e($line) . '</li>'; }
        $body = '<div class="match-header"><div class="eyebrow">MATCHDAY · ' . WebView::e($view['competition'] ?? '') . '</div><p>' . WebView::e($view['date'] ?? '') . '</p><div class="scoreline"><strong>' . WebView::e($view['home_club'] ?? '') . '</strong><span>' . WebView::e($result['home_goals'] ?? 0) . ' — ' . WebView::e($result['away_goals'] ?? 0) . '</span><strong>' . WebView::e($view['away_club'] ?? '') . '</strong></div><div class="result-badge">' . WebView::e(strtoupper((string) ($view['perspective_result'] ?? 'result'))) . '</div></div><div class="match-grid"><div>' . WebView::section('YOUR MATCH', (string) ($performance['player_name'] ?? 'Player'), '<div class="match-player"><img class="portrait portrait-medium" src="' . WebView::e($this->portraitUrl($saveId, $career->playerId()->value(), 'matchday', 128)) . '" alt="Player portrait"><div><p class="tag">' . WebView::e(CareerLabels::value($performance['selection_status'] ?? null)) . '</p><div class="stat-grid compact">' . WebView::stat('Minutes', $performance['minutes'] ?? 0) . WebView::stat('Rating', $this->rating($performance['rating'] ?? null)) . '</div></div></div><div class="stat-grid compact">' . $stats . '</div>') . WebView::section('HIGHLIGHTS', 'Match story', $highlights === '' ? WebView::emptyState('No highlights recorded.') : '<ul class="fixture-list">' . $highlights . '</ul>') . '</div><aside>' . WebView::section('POST-MATCH', 'Updated context', $this->postMatch($view['post_match'] ?? [])) . '</aside></div><div class="form-actions">' . WebView::link('home', ['save' => $saveId], 'Career Home', 'button button-primary') . WebView::link('training', ['save' => $saveId], 'Training') . '</div>';

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
        $stats = (array) ($post['season_stats'] ?? []); $form = (array) ($post['recent_form'] ?? []); return '<div class="split-list"><p><span>Recent form</span><strong>' . WebView::e($this->formLabel($form)) . '</strong></p><p><span>Season average</span><strong>' . WebView::e($this->rating($stats['average_match_rating'] ?? null)) . '</strong></p><p><span>Season goals</span><strong>' . WebView::e($stats['goals'] ?? 0) . '</strong></p><p><span>League position</span><strong>' . WebView::e($post['club_position'] ?? 'Not available') . '</strong></p></div>';
    }

    private function contextActions(string $saveId, array $summary): string
    {
        $html = '<div class="context-actions">'; $transfer = (array) ($summary['transfer_request'] ?? []); $status = (string) ($transfer['status'] ?? '');
        foreach ((array) ($summary['available_actions'] ?? []) as $action) { if (!is_array($action)) { continue; } $type = $action['type'] ?? ''; if ($type === 'request_transfer' && $status !== 'requested') { $html .= WebView::form('request_transfer', 'Request transfer', ['save' => $saveId, 'confirm' => '1'], 'button button-secondary', 'onsubmit="return confirm(\'Request a transfer?\')" data-busy'); } if ($type === 'withdraw_transfer_request' && $status === 'requested') { $html .= WebView::form('withdraw_transfer', 'Withdraw transfer request', ['save' => $saveId], 'button button-secondary', 'data-busy'); } }
        if (($summary['pending_career_event'] ?? null) !== null) { $html .= WebView::link('event', ['save' => $saveId], 'Open career event', 'button button-secondary'); }
        if (($summary['pending_decisions'] ?? []) !== []) { $html .= WebView::link('decision', ['save' => $saveId], 'Open career decision', 'button button-secondary'); }
        return $html . '</div>';
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
