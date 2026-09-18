# GOAL: Legacy
# International Football State

Document ID: DB-017
Title: National Team and World Championship State
Version: 1.0
Status: Phase 2 implementation contract

## Ownership

`NationalTeamService` owns stable national-team identity, supported-country
pool checks, deterministic selection windows, squad rows, team strength, and
international Player aggregates. Player nationality remains owned by the
Player/Nation model. `InternationalCompetitionService` owns World
Championship qualification, field membership, groups, stage progression, and
compact competition history. `MatchRepository` owns every canonical fixture
and result; the shared knockout resolver owns AET and penalties.

## Normalized state

- `national_team_records`: national-team identity, nation, bounded strength,
  and current eligible pool count;
- `international_team_squads`: Season, national team, Player, selected role,
  deterministic selection score, and status;
- `international_player_statistics`: Season/competition caps, starts,
  minutes, goals, assists, cards, and rating aggregates;
- `international_competitions`: cycle, field/group shape, current stage,
  status, winner, and runner-up;
- `international_competition_entries` and group memberships: qualification
  source, seed, group, and advancement status;
- `international_match_states`: stage, resolution, winner, extra-time score,
  and shootout facts keyed by canonical Match ID;
- `international_history`: compact completed Season, competition, winner, and
  runner-up facts.

Presentation data is derived. Full brackets, repeated tables, NPC Player
Match rows, and rendered assets are not stored.

## Invariants

One Player maps to one national team through primary nationality. A team is
selected once per Season/window and never rerolled on page load. A World
Championship entry occurs in one tier only, group membership is stable, and a
next stage is inserted only after required prior Matches resolve. A completed
final has one winner and one runner-up. Penalty goals are not normal Match or
Season goals.

## Persistence and migration

International schema creation is additive and safe for P2-008 saves. Existing
nationality values map directly to team identity. Legacy Club, Cup, European,
League, finance, and history rows are not rewritten. Rollover closes completed
international state, preserves history, and initializes at most one next-cycle
state.

END OF DOCUMENT
