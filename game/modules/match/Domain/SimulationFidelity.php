<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

/**
 * Controls how much match evidence is produced for a completed fixture.
 *
 * Both modes use the same football outcome model. PLAYER is used when the
 * controlled career is involved and keeps the complete Matchday evidence
 * graph. WORLD keeps the result, decisive events, participation inputs, and
 * compact season signals needed by the rest of the simulation.
 */
enum SimulationFidelity: string
{
    case Player = 'player';
    case World = 'world';
}
