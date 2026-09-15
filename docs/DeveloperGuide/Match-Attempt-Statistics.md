# Match Attempt Statistics

DOMAIN-028 extends the existing `MatchSimulationService` and
`PlayerMatchStatRepository`; it does not add another Match, event, goalkeeper,
or statistics system.

## Attempt and outcome policy

The existing deterministic Match result remains authoritative. Every
attributed goal contributes one shot and one shot on target for its scorer.
The simulation then creates a small bounded set of additional in-memory
attempts per Club. Each additional attempt selects an active starter or
substitute using the DOMAIN-027 position/Shooting/Dribbling weighting and
resolves on-target status with a bounded position/Shooting chance. An
additional on-target attempt is a saved shot when the defending active Match
state contains a goalkeeper; it never rerolls into a goal.

This preserves the Match invariants:

- shots are greater than or equal to shots on target;
- shots on target are greater than or equal to goals;
- Club Player goals equal the authoritative Match result;
- non-goal on-target attempts are assigned only to the active defending
  goalkeeper as saves.

No attempt highlight is emitted for ordinary shots. `MatchHighlight` remains
the notable goal/substitution representation, while the complete factual
attempt outcomes live in `PlayerMatchStat`.

## Goalkeepers and clean sheets

Goalkeepers are identified from the existing `PlayerPosition::Goalkeeper`
position in the active minute-aware lineup. A goalkeeper substitution is
therefore handled by the same starter/substitute state used for scorers. A
clean sheet requires the Club to concede zero authoritative goals and to have
an appearing goalkeeper. The appearing goalkeeper and participating
defenders (`GK`, `CB`, `LB`, `RB`) receive one clean-sheet stat. This is the
conservative supported rule; no unsupported partial-concession or tactical
clean-sheet model is inferred.

## Persistence and compatibility

`PlayerMatchStat` now exposes `shots`, `shots_on_target`, `saves`, and
`clean_sheets`, all non-negative and zero by default. Existing goal-only rows
are migrated non-destructively; their minimum shot and shot-on-target counts
are inferred from recorded goals so legacy data remains reconciled. Match
replacement remains atomic/idempotent, and Season aggregates include all
existing and new fields.

Additional attempt keys are separate from score, goal-minute, scorer, and
assist keys. Identical Match state, lineup, and seed therefore preserve the
existing score and reproduce the same attribution/statistics.

Match ratings, xG/xA, passing and defensive actions, cards, set pieces,
possession, and richer event chains remain deferred.
