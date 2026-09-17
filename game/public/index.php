<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Career creation and Continue use the canonical world simulation. The local
// game server must not impose PHP's short web-request timeout on those calls.
set_time_limit(0);

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Web\WebApplication;

session_start();
$services = (new Bootstrap())->create(dirname(__DIR__, 2));
$application = new WebApplication($services, dirname(__DIR__, 2));
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$response = $application->handle(
    strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
    $path,
    $_GET,
    $_POST,
    $_SESSION,
);
http_response_code((int) ($response['status'] ?? 200));
foreach (($response['headers'] ?? []) as $name => $value) {
    header($name . ': ' . $value);
}
echo (string) ($response['body'] ?? '');
