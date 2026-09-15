# Defensive Match Actions

`MatchSimulationService` is the sole owner of Match tackles, interceptions,
and blocks. It creates a small bounded set of deterministic internal facts for
each defending side using separate hash keys, the active lineup at the selected
minute, canonical `PlayerPosition`, and the existing Defending/Physicality
attributes. GK is excluded from ordinary defensive-action attribution.

Tackles favour Defending and Physicality; interceptions favour Defending; and
blocks favour Defending with a modest Physicality contribution. These are
successful defensive-action facts, not a possession, passing, or duel model.
Blocks are conservative standalone defensive events because DOMAIN-028 does
not retain enough attempt-state detail to reclassify shots safely; they never
alter shots, shots on target, goals, or saves.

The fields live in `PlayerMatchStat`, persist through
`PlayerMatchStatRepository`, migrate legacy rows to zero, and aggregate by
Season. They are exposed by the existing Match player summary. Ratings, form,
season-performance policy, Career Hub presentation, and career consequences
remain deferred.
