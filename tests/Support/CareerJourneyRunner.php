<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Support;

use Goal\Legacy\Web\WebApplication;
use RuntimeException;

/**
 * Small production-route harness for bounded Career journey tests.
 * It only submits the existing WebApplication routes and extracts the
 * one-use form token rendered by those routes; gameplay remains canonical.
 */
final class CareerJourneyRunner
{
    /** @var array<string,mixed> */
    private array $session = [];

    /** @var array{status:int,headers:array<string,string>,body:string} */
    private array $lastResponse = ['status' => 0, 'headers' => [], 'body' => ''];

    public function __construct(private readonly WebApplication $application)
    {
    }

    /** @param array<string,mixed> $query @return array{status:int,headers:array<string,string>,body:string} */
    public function get(string $page, array $query = []): array
    {
        $this->lastResponse = $this->application->handle('GET', '/', array_merge(['page' => $page], $query), [], $this->session);

        return $this->lastResponse;
    }

    /** @param array<string,mixed> $payload @return array{status:int,headers:array<string,string>,body:string} */
    public function post(string $action, array $payload = []): array
    {
        $payload['action'] = $action;
        if (!isset($payload['token'])) {
            $token = $this->tokenFor($this->lastResponse, $action);
            if ($token !== null) {
                $payload['token'] = $token;
            }
        }
        $this->lastResponse = $this->application->handle('POST', '/', [], $payload, $this->session);

        return $this->lastResponse;
    }

    /** @param array{body:string} $response */
    public function tokenFor(array $response, string $action): ?string
    {
        $pattern = '/<input type="hidden" name="action" value="' . preg_quote($action, '/') . '">.*?<input type="hidden" name="token" value="([^"]+)"/s';
        if (preg_match($pattern, $response['body'], $matches) !== 1) {
            return null;
        }

        $token = html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES | ENT_HTML5);

        return $token === '' ? null : $token;
    }

    /** @return array<string,mixed> */
    public function session(): array
    {
        return $this->session;
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public function lastResponse(): array
    {
        return $this->lastResponse;
    }
}
