# GOAL: Legacy
# European Competition State

Document ID: DB-016
Title: European Club Competition State
Version: 1.0
Status: Phase 2 implementation contract

## Ownership

`EuropeanCompetitionService` owns qualification and tournament structure.
`MatchRepository` owns every scheduled/completed Match. `StandingsService`
owns the group-table calculation through a supplied Club field. No European
Match, Player statistics, or development table is duplicated.

The normalized state is:

- `european_seasons`: tier, field size, group shape, current stage, status,
  qualification Season, winner, and runner-up;
- `european_entries`: Club, nation, qualification source/position, seed,
  group, and eliminated/qualified status;
- `european_group_memberships`: one row per Club and group;
- `european_match_states`: stage, group, round, resolved winner, extra-time
  goals, and shootout result keyed by canonical Match ID.

## Invariants

There are exactly 16 entries and four groups of four per initialized tier.
The same Club cannot appear in both tiers. Draws are stable after save/reload.
Group Matches have no winner requirement; knockout Matches have exactly one
winner. A next stage is inserted only after all required prior state rows are
resolved. A final stores winner and runner-up once.

NPC European Matches use the compact existing Season aggregate path. Detailed
NPC selection, evaluation, and Player Match rows are not created. Controlled
Matches retain canonical detailed evidence. No rendered UI or bracket snapshot
is stored in SQLite.

## Historical and rollover rule

Completed state remains queryable through the canonical Match rows and compact
history view. New Season rows are initialized once after prior domestic
results are complete. Previous European memberships are not copied as
qualification; new entries are resolved from the completed domestic Season.

END OF DOCUMENT
