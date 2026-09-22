# GOAL: Legacy
# Engine Implementation Guide

Document ID: EIG-018
Title: Career Experience Boundary
Version: 1.0
Status: Phase 1 implementation guide

## Ownership

`CareerExperienceService` owns the player-facing between-match event
boundary. It is separate from the Core EventDispatcher: the dispatcher
delivers transient module notifications, while Career Experience persists a
bounded gameplay situation, its choices, and its resolved consequence.

Canonical football services remain authoritative. Career Experience may
route a choice to `PlayerDevelopmentService` through the existing training
service or to the priority repository. It does not calculate Match ratings,
form, Season performance, role, standings, movement, Contracts, or
development outcomes.

## Catalog and selection

`CareerEventCatalog` contains declarative definitions with stable IDs,
categories, copy, choices, eligibility requirements, repeatability policy,
priority relevance, and optional chain metadata. Eligibility reads a bounded
career snapshot and recent Match evidence. It does not scan the world or
hydrate every Player.

Selection is deterministic from Player, Season, calendar-month source key,
priority, and the eligible catalog. Contextual candidates are scored above
generic candidates; recent categories are lightly cooled down. Continue
remains the sole calendar owner and creates no more than one event per
calendar month before a controlled fixture.

## Persistence and replay protection

The existing `career_events` record is the durable boundary. Its structured
context stores the definition, Club context, repeatability, chain stage, and
the canonical context signals used for presentation. A resolved consequence
stores the selected choice, any training/priority change, and a small list of
narrative memory flags.

The existing monthly source-key uniqueness and transactional resolve path
protect event generation and consequences against reloads and retries. A
resolved event is returned unchanged if resolution is attempted again.
Pending events therefore show the same copy and options after save/reload.

## Chains and transfer continuity

Chains are ordinary catalog definitions linked by `chain_id`, `chain_stage`,
and memory requirements. They are intentionally limited to two or three
stages. A transfer changes the current Club context for new eligibility;
historical event records remain queryable and memory flags remain durable.
Rollover resets only seasonal repeatability through the existing Season ID.

## Deferred systems

Phase 1 does not add a balance, salary-payment, purchase, asset, relationship,
morale, reputation, follower, social, or generic event ledger system. It also
does not add a daily event scheduler, training minigame, or new Match facts.
That is the historical Phase 1 boundary; P2-005 adds a separate
controlled-Player finance owner without changing event ownership or
introducing NPC personal finance.

## P2-018 Manager Context

`ManagerTrustService` is a read-only adapter over existing football evidence.
It distinguishes interpersonal `FootballSocialService` manager relationship
from selection-facing football trust, without persisting a second trust
score. It derives a bounded label, same-position competition summary,
role-based playing-time expectation, recent minutes assessment, factual
feedback, and selection context for the controlled Player.

`MatchSelectionService` remains authoritative. In production it receives a
small controlled-Player-only trust tie-break after role, OVR, form, position,
and availability; World/NPC selection does not calculate detailed manager
trust. `CareerOutlookService` consumes the derived mismatch context but does
not become a second playing-time owner.

The `manager-playing-time-review` catalog event is eligible only after at
least three recent selection observations show below-expectation minutes. It
is once per Season, resolves through the existing Career Event transaction,
and routes manager consequences through `FootballSocialService`. Reads never
create or reroll it; the monthly event source key and resolved memory protect
reload/double-submit behavior.

## Revision History

| Version | Date | Notes |
|---|---|---|
| 1.0 | 2026-09-17 | Documented the enriched Phase 1 career-event boundary. |

## P2-029 Club Captaincy

`ClubCaptaincyService` owns Club/Season appointments and exposes a read model
separate from Squad Role, Manager Trust, Club attachment, on-pitch role, and
traits. The only persisted identity is the compact current Club/Season
captain/vice-captain appointment. Appointment review happens at explicit
career-start, population, transfer, and Season-boundary writes; no weekly
candidate ranking or page-render write exists.

Match captaincy is derived from the starting XI: Club captain, then
vice-captain, then a deterministic eligible senior fallback. It is stored only
as the existing detailed selection snapshot and is descriptive. It cannot
modify Match action distribution or success, rating, attributes, team strength,
morale, readiness, development, or RNG. World fidelity does not simulate NPC
leadership. Legacy saves use a deterministic read fallback and do not receive
fabricated historical appointments. International captaincy is deferred.

## P2-020 Club Season Objective Context

`ClubSeasonObjectiveService` is the controlled-Career adapter for Club Season
stakes. It consumes `ClubService`, `StandingsService`,
`PromotionRelegationService`, existing Cup/European memberships, fixture
dates, and compact Player contribution facts. It derives a stable expectation,
Season phase, progress, pressure, important fixtures, and final outcome. It
does not own standings, promotion/relegation, Cup draws, European stages,
Player role, Career Outlook, News, or Pulse.

`SeasonRolloverService::prepareNext()` resolves controlled Club Season
outcomes after the outgoing Season is complete and before evidence is
compacted. `ClubSeasonObjectiveRepository` persists one row per controlled
Player/Season/Club only; the composite key and stored outcome prevent reload
and rollover duplication. Current reads remain deterministic and do not
create weekly snapshots. NPC lifecycle and world simulation do not calculate
per-Player objectives or narrative pressure.

## P2-030 Set-Piece Responsibility Experience

Career Home and Profile expose only the current Club's supported penalty
responsibility. Matchday/Post-Match expose actual penalty facts from canonical
Match highlights and stats; assignment alone is never presented as a goal,
assist, or specialist achievement. Direct free kicks, corners, and
international set pieces are deferred. Transfers reevaluate Club-scoped
responsibility, free agents have none, and reads on legacy saves use a stable
fallback without fabricating historical appointments. No milestone, News,
Echo, or Pulse entry is produced for routine responsibility changes.

## P2-033 Season Leaders in Career Surfaces

Competition pages expose current Goals and Assists leaders from the canonical
competition/Season read model. Career Home and controlled Player Profile may
show a compact standing only when the Player is currently in a meaningful
top-ten context; neither surface persists rank history. Competition labels,
readable Season labels, Club context, deterministic ties, and a factual gap to
the leader remain visible. Empty or early Seasons say that no ranked total is
available rather than using race or prediction language.

## P2-034 Read-path performance consolidation

Competition and Trophy Room rendering retain their existing ownership and
canonical output. The production read path now avoids repeated Club and
Competition schema initialization per PDO connection, memoizes repeated
immutable entity labels only for the active request/connection, and gives the
Competition page only its progression context before formatting fixture data
for the bounded displayed rows. The Trophy Room uses the existing progression,
legacy, and captaincy facts directly rather than loading the broader Career
Home snapshot.

These are data-access changes only: there is no global cache, persisted
achievement summary, new index, schema migration, gameplay change, RNG use,
or write-on-read behavior. The P2-031 fixture-context service, P2-032
achievement projection, and P2-033 competition leaderboard query remain the
owners of their facts. Regression tests retain output equality, zero-DML
reads, deterministic ordering, legacy/compacted save compatibility, and a
bounded schema-initialization sentinel.

## P2-041 Season Review

`CareerPresentationService::seasonReview()` is the read-only Season Review
projection. It composes Season-scoped competition aggregates, Club/loan
movement, role and development history, injury/comeback evidence, discipline,
captaincy, objectives, and the existing legacy and leaderboard projections.
It owns no statistics, awards, honours, milestones, movement facts, or new
Season summary rows. `seasonSummary()` retains compatibility aliases for the
CLI boundary while the graphical `season-review` route consumes the richer
projection.

Completed Seasons are the primary review surface; an active Season is clearly
labelled as in progress when selected. Competition totals are derived from
canonical competition rows, international evidence remains separate, and loan
Clubs remain distinct from the parent Contract Club. Position/substitution
history is not reconstructed after compaction. Missing legacy evidence is
omitted rather than fabricated. The projection has no Season score, no random
prose, and no write-on-read path; ordering is stable by Season, competition,
movement, and canonical source key.

END OF DOCUMENT
