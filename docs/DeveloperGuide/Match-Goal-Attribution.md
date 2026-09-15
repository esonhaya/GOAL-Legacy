# Match Goal Attribution

DOMAIN-027 keeps `MatchSimulationService` as the scorer/assist owner and
`PlayerMatchStatRepository` as the authoritative persistence and Season
aggregation owner. It does not add a second event or statistics system.

## Attribution policy

Each ordinary generated goal selects a scorer from the scoring Club's active
selected Players at the goal minute. Starters removed by a substitution are
excluded and incoming substitutes are eligible after their substitution
minute. Position and existing Shooting/Dribbling attributes provide small,
explainable weights: attacking Players are more likely to score, but all
active positions remain eligible.

An ordinary goal has at most one assist. A deterministic 65% assist chance is
used, followed by position/Passing/Dribbling weights among active teammates
other than the scorer. The assistant must belong to the scoring Club and can
never be the scorer. Unassisted goals are valid. Penalties, direct free kicks,
and own goals are not represented by the current Match engine, so no special
goal policy is introduced here.

## Persistence and determinism

`PlayerMatchStat.assists()` defaults to zero for existing callers and legacy
SQLite rows. Repository initialization adds the `assists` column with a
non-destructive default when an older save is opened. Match replacement stays
atomic and idempotent, and Season aggregate queries expose appearances,
starts, minutes, goals, and assists together.

Score-generation keys are unchanged. Attribution uses separate deterministic
keys after the score and goal minutes have been selected, so the same Match
state, seed, and lineup reproduce the same result and attribution.

The current MatchHighlight record carries `assist_player_id` in its existing
goal data. `MatchService::playerSummary()` exposes the factual assist count
and includes goal highlights for an assisting Player. DOMAIN-022 career
classification and downstream Contract/market/role/development policies do
not consume assists in this milestone.

Detailed event chains and statistics such as shots, ratings, expected assists,
defensive actions, saves, cards, and set-piece/own-goal semantics remain
deferred.
