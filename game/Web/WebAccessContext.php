<?php

declare(strict_types=1);

namespace Goal\Legacy\Web;

use Goal\Legacy\Core\Simulation\SimulationPermission;

/**
 * Narrow server-side access boundary for the local web shell. In a deployed
 * product these values come from the authenticated session/entitlement layer;
 * they are never accepted from query strings or posted capability names.
 */
final readonly class WebAccessContext
{
    public function __construct(
        private string $permission,
        private string $accountId,
    ) {
    }

    /** @param array<string,mixed> $session */
    public static function fromSession(array $session): self
    {
        $role = strtoupper(trim((string) ($session['haya_role'] ?? 'PLAYER')));
        $permission = $role === SimulationPermission::DEVELOPER
            ? SimulationPermission::DEVELOPER
            : ((bool) ($session['haya_premium_sandbox'] ?? false) ? SimulationPermission::PREMIUM_SANDBOX : SimulationPermission::PLAYER);
        $accountId = trim((string) ($session['account_id'] ?? 'anonymous'));

        return new self($permission, $accountId === '' ? 'anonymous' : $accountId);
    }

    public function permission(): string { return $this->permission; }
    public function accountId(): string { return $this->accountId; }
    public function isDeveloper(): bool { return $this->permission === SimulationPermission::DEVELOPER; }
    public function canSandbox(): bool { return $this->isDeveloper() || $this->permission === SimulationPermission::PREMIUM_SANDBOX; }
}
