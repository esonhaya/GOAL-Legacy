<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use InvalidArgumentException;

final readonly class PlayerMatchStat
{
    private int $shots;
    private int $shotsOnTarget;
    private int $saves;
    private int $cleanSheets;
    private int $tackles;
    private int $interceptions;
    private int $blocks;
    private int $passesAttempted;
    private int $passesCompleted;
    private int $foulsCommitted;
    private int $yellowCards;
    private int $redCards;

    public function __construct(private MatchId $matchId, private PlayerId $playerId, private ClubId $clubId, private bool $appeared, private bool $started, private int $minutes, private int $goals, private int $assists = 0, ?int $shots = null, ?int $shotsOnTarget = null, int $saves = 0, int $cleanSheets = 0, int $tackles = 0, int $interceptions = 0, int $blocks = 0, int $passesAttempted = 0, int $passesCompleted = 0, int $foulsCommitted = 0, int $yellowCards = 0, int $redCards = 0)
    {
        $this->shots = $shots ?? $goals;
        $this->shotsOnTarget = $shotsOnTarget ?? $goals;
        $this->saves = $saves;
        $this->cleanSheets = $cleanSheets;
        $this->tackles = $tackles;
        $this->interceptions = $interceptions;
        $this->blocks = $blocks;
        $this->passesAttempted = $passesAttempted;
        $this->passesCompleted = $passesCompleted;
        $this->foulsCommitted = $foulsCommitted;
        $this->yellowCards = $yellowCards;
        $this->redCards = $redCards;
        if (!$appeared && ($started || $minutes !== 0 || $goals !== 0 || $assists !== 0 || $this->shots !== 0 || $this->shotsOnTarget !== 0 || $this->saves !== 0 || $this->cleanSheets !== 0 || $this->tackles !== 0 || $this->interceptions !== 0 || $this->blocks !== 0 || $this->passesAttempted !== 0 || $this->passesCompleted !== 0 || $this->foulsCommitted !== 0 || $this->yellowCards !== 0 || $this->redCards !== 0)) { throw new InvalidArgumentException('A non-appearing Player cannot have Match statistics.'); }
        if ($appeared && $minutes < 1) { throw new InvalidArgumentException('An appearing Player must have positive Match minutes.'); }
        if ($started && !$appeared) { throw new InvalidArgumentException('A starting Player must appear in the Match.'); }
        if ($minutes < 0 || $minutes > 90 || $goals < 0 || $assists < 0 || $this->shots < 0 || $this->shotsOnTarget < 0 || $this->saves < 0 || $this->cleanSheets < 0 || $this->cleanSheets > 1 || $this->tackles < 0 || $this->interceptions < 0 || $this->blocks < 0 || $this->passesAttempted < 0 || $this->passesCompleted < 0 || $this->foulsCommitted < 0 || $this->yellowCards < 0 || $this->redCards < 0 || $this->redCards > 1) { throw new InvalidArgumentException('Player Match statistics are outside the Phase-1 bounds.'); }
        if ($goals > $minutes || $this->shotsOnTarget > $this->shots || $goals > $this->shotsOnTarget || $this->passesCompleted > $this->passesAttempted || $this->yellowCards > $this->foulsCommitted) { throw new InvalidArgumentException('Player Match statistics do not reconcile.'); }
    }
    public function matchId(): MatchId { return $this->matchId; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function clubId(): ClubId { return $this->clubId; }
    public function appeared(): bool { return $this->appeared; }
    public function started(): bool { return $this->started; }
    public function minutes(): int { return $this->minutes; }
    public function goals(): int { return $this->goals; }
    public function assists(): int { return $this->assists; }
    public function shots(): int { return $this->shots; }
    public function shotsOnTarget(): int { return $this->shotsOnTarget; }
    public function saves(): int { return $this->saves; }
    public function cleanSheets(): int { return $this->cleanSheets; }
    public function tackles(): int { return $this->tackles; }
    public function interceptions(): int { return $this->interceptions; }
    public function blocks(): int { return $this->blocks; }
    public function passesAttempted(): int { return $this->passesAttempted; }
    public function passesCompleted(): int { return $this->passesCompleted; }
    public function foulsCommitted(): int { return $this->foulsCommitted; }
    public function yellowCards(): int { return $this->yellowCards; }
    public function redCards(): int { return $this->redCards; }
    /** @return array<string, mixed> */
    public function toArray(): array { return ['match_id' => $this->matchId->value(), 'player_id' => $this->playerId->value(), 'club_id' => $this->clubId->value(), 'appeared' => $this->appeared ? 1 : 0, 'started' => $this->started ? 1 : 0, 'minutes' => $this->minutes, 'goals' => $this->goals, 'assists' => $this->assists, 'shots' => $this->shots, 'shots_on_target' => $this->shotsOnTarget, 'saves' => $this->saves, 'clean_sheets' => $this->cleanSheets, 'tackles' => $this->tackles, 'interceptions' => $this->interceptions, 'blocks' => $this->blocks, 'passes_attempted' => $this->passesAttempted, 'passes_completed' => $this->passesCompleted, 'fouls_committed' => $this->foulsCommitted, 'yellow_cards' => $this->yellowCards, 'red_cards' => $this->redCards]; }
}
