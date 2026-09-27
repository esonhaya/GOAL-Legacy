<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Modules\Player\Avatar\AvatarCatalog;
use Goal\Legacy\Modules\Player\Domain\CareerStartRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerAppearance;
use Goal\Legacy\Web\WebCareerStartWorkflow;

$saveId = trim((string) ($argv[1] ?? ''));
if (!str_starts_with($saveId, 'p3019-browser-')) {
    throw new \InvalidArgumentException('Browser fixtures require the p3019-browser- save prefix.');
}

$root = dirname(__DIR__, 2);
$services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
$nation = $services->nationModule()->service()->loadSelected()[0] ?? null;
if ($nation === null) {
    throw new RuntimeException('Browser fixture requires a selected nation.');
}

$request = new CareerStartRequest(
    $saveId,
    'P3-019 Browser Player',
    $nation->id()->value(),
    180,
    75,
    'CM',
    'regular',
    24001,
    'right',
);
$workflow = new WebCareerStartWorkflow($services, $root);
$preview = $workflow->preview($request);
$opportunity = $preview['opportunities'][0] ?? null;
if (!is_array($opportunity) || !is_string($opportunity['club_id'] ?? null)) {
    throw new RuntimeException('Browser fixture requires a canonical Youth Camp opportunity.');
}

$preset = (new AvatarCatalog())->presets()[0]['appearance'] ?? null;
if (!is_array($preset)) {
    throw new RuntimeException('Browser fixture requires a canonical avatar preset.');
}

$workflow->create(
    $request,
    $opportunity['club_id'],
    PlayerAppearance::fromArray($preset),
    $preview['opportunities'],
);

printf("Created isolated browser Career %s\n", $saveId);
