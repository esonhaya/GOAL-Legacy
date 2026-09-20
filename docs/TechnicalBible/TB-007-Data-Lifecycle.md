# GOAL: Legacy
# Technical Bible

Document ID: TB-007
Title: Data Lifecycle

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-09-12

---

# Purpose

This document defines how data moves between installed content, a career
save, and the running process. It applies to Core and domain modules without
defining domain-specific schemas.

---

# Lifecycle Categories

## Permanent

Content and reference identity expected to survive careers and compatible
version changes. Stable domain IDs are permanent references and are never
silently reused.

## Career

Persistent state belonging to one save and its world. Simulation time and
mutable Nation state are Career data. Career state is stored in the save
database, not in an installed content package.

## Seasonal

State that is rebuilt or replaced at a season boundary. Seasonal records are
owned by the relevant domain and must not overwrite permanent identity.

## Temporary

Short-lived domain state that may survive several simulation ticks but is not
part of permanent or career state, such as import, generation, or validation
working data.

## Runtime

Process-only wiring and derived state, including caches, resolved object
graphs, module services, event listeners, scheduler callbacks, and active
registries. Runtime state is never canonical save data.

---

# Content and Save Boundary

Content packages contain declarative baseline data. Loading content constructs
validated domain values; it does not make package files the mutable source of
career state.

The save database contains the durable state required to reconstruct a domain,
including stable IDs and source package/version provenance where needed.
Changing an installed package does not automatically synchronize or rewrite an
existing career. Compatibility and migration are explicit versioned behavior.

---

# Reconstruction Rules

- Persist data, not runtime wiring or executable behavior.
- Rebuild indexes and caches from durable records where practical.
- Keep IDs stable across all references.
- Keep simulation time as Career state; wall-clock timestamps are operational
  metadata only.
- Do not serialize scheduler callbacks or event listeners.
- Future migrations should preserve compatible durable state wherever practical
  and must fail clearly when compatibility is not available.

---

# Ownership

Each domain repository owns persistence of its domain records. Core provides
database, transaction, serialization, and save primitives; it does not own
football-domain records.

## Domestic Cup state

Domestic Cup Seasons are additive to the shared Season and Match lifecycle.
`DomesticCupService` persists compact Season, entry, and Match-resolution rows;
completed Match records remain the source of regulation scores and Player
evidence. Extra-time scores and penalty shootouts are Cup-resolution facts,
with shootout goals excluded from normal Match and Season statistics. Loading a
save reconciles a completed Cup Match whose winner write was interrupted before
advancing the bracket.

---

# Locked Decisions

✓ Runtime state is not canonical save data

✓ Content baseline is distinct from mutable career state

✓ Simulation time is Career state

✓ Runtime callbacks and listeners are not serialized

✓ Stable IDs survive compatible references and migrations

✓ Player identity, attributes, potential, and development profile are Career
state owned by the Player Module

✓ Season-bound Club squad membership is relationship state owned by the Club
Module

✓ A controlled career Player is a Player-ID reference, not a duplicate Player
record

✓ Employment Contracts are durable Career-state records owned by the Contract
Module and retain terminated historical rows where practical

✓ Player registration is Season-bound relationship state owned by the
Competition Module; it is distinct from Club squad membership and Contract
employment

✓ Transfers are durable historical transaction records owned by the Transfer
Module; execution coordinates Contract, squad, and registration transitions
inside one database transaction

✓ Fixtures and completed Matches are Career-durable records owned by the Match
Module; completed Match results are immutable

✓ Match Player stat lines and user-visible structured highlights are Career
state and reference stable Player/Club IDs without duplicating their records

✓ Player career state is Career durable; age is derived from Birth Date and
SimulationDate, and retirement is a permanent historical Player transition

✓ Newgens are ordinary Career-durable Player records generated at Season
boundaries for squad vacancies; they are not static content or a second Player
entity

✓ Matchday selections and substitution records are Career-durable Match state;
substitute minutes remain Match-owned facts and are not copied to Player

✓ Completed-Season awards, participation honours, personal bests, and
threshold milestones are durable Career facts owned by the Legacy boundary

✓ Career Timeline, Career Landmarks, Personal Bests, and Defining Seasons are
read-only projections over those durable facts and canonical Match/Event
history; they do not create a second history table

✓ Missing historical chronology on a legacy save is preserved as missing
evidence rather than reconstructed with fabricated dates or entities

✓ Career Memory reads are bounded to the controlled Player; NPCs receive no
milestone rows, milestone ticks, or World-wide timeline scan

✓ Awards resolve after the completed Season has all required competition
results and before replay-only Match detail is compacted

✓ World-fidelity competition aggregates are compact evidence for seasonal
consumers; they are not NPC Match-detail or NPC legacy simulation

✓ Standings are a Seasonal projection deterministically rebuilt from durable
completed Match results

✓ Player development state and immutable development history are Career data
owned by the Player Module.

✓ Training blocks are explicit durable source references for idempotency; the
training service itself is Runtime state.

✓ Match development is applied once per stable Match/Player source key inside
the existing Match persistence transaction.

✓ Season and career Player statistics are derived from durable Match Player
stat lines and remain rebuildable after reload.

✓ Squad roles and role history are Career relationship data owned by the Club
Module.

✓ Match selection decisions are Career-durable Match records; performance,
recent form, and expectations are rebuildable projections.

✓ Career opportunities are Career-durable structured records owned by the
Player career boundary and reference Player/Club IDs without embedding them.

✓ DOMAIN-012 transfer offers reuse those CareerOpportunity records. Offer
context is durable decision metadata until decline, expiry, or completion;
accepted movement becomes a normal Transfer record owned by the Transfer
Module. Transfer fees and proposed wages do not create a finance ledger.

✓ Player fatigue/load state is Career-durable current state and is recovered
from its last processed SimulationDate on demand. Match and training source
keys make load application idempotent.

✓ Injury occurrences are Career-durable structured Player records with an
expected SimulationDate recovery boundary. Availability is a derived/current
projection; recovery does not require a serialized callback or a daily global
Player tick.

✓ The Match Module applies an explicit Player/World simulation-fidelity
boundary. Controlled-player Match evidence remains detailed. NPC-only Match
results and decisive events remain durable, while replay-only NPC stat,
selection, substitution, and development-history rows are replaced by compact
Season aggregates and rolling evaluation summaries where their consumers
require them.

✓ Compact world aggregates are Career-durable and owned by the World/Player
statistics boundary. They are additive and rebuildable from legacy detailed
Match rows; existing detailed saves remain compatible.

## Presentation Read Safety and Generated Saves

Graphical Career Home, World, Competition, Club, Squad, and Player Profile
routes consume canonical repositories and read models. A GET used for
browsing must not mutate career facts, advance simulation time, or persist
presentation caches. Portrait output remains disposable cache data outside a
career SQLite database.

Repository-local runtime saves live in `game/saves/` and are ignored by Git.
Temporary benchmark databases, journals, preview directories, and old
development saves are generated artifacts. Keep only a useful current local
save and explicitly required test or migration fixtures; remove other
generated files individually after checking references. Ambiguous files are
retained.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-09-12 | Initial data lifecycle specification |

---

## DOMAIN-010 Generated Player Lifetime

Generated Players are Career-durable mutable state. Their generator version
and seed metadata are durable initialization state so reruns cannot silently
replace existing Players. Name pools and generation rules are static/runtime
configuration; generated Contracts are normal Career history and
Competition registrations remain Season-bound.

## P2-005 Controlled Player Finance

Personal finance is additive controlled-career state. `player_finance_state`
stores the current balance and payroll cursor, `player_finance_transactions`
stores traceable balance changes, and `player_lifestyle_ownership` stores
stable catalog ownership. These tables are created lazily for a new or
legacy controlled Player; loading a page never advances payroll. Weekly
payroll uses a Contract-and-period source key, making Continue and reload
retries idempotent. NPC Players never receive these rows. Rendered lifestyle
artifacts remain outside the Career SQLite database.

## P2-006 Finance and Lifestyle Lifecycle

The player_lifestyle_ownership.active column is an additive migration column.
Older ownership rows default to active and remain readable; new permanent
purchases deactivate older items in the same category before inserting the
new ownership row. Activating an already-owned item is a no-charge
transactional ownership update. Experience items do not use an active slot.
The stored purchase price is historical and is never rewritten when catalog
prices change.

Financial context is derived at read time from canonical finance summary
values and installed catalog metadata. Browsing Career Home, Finances,
Lifestyle, or Player Profile never processes payroll, creates ledger rows, or
changes active ownership. Payroll remains calendar-driven and its Contract /
week source identity remains the replay boundary. The controlled Player is
the only finance subject; NPC world simulation has no personal finance
tables or payroll work.

## DOMAIN-013 Season Rollover

`SeasonRolloverService` owns the single recurring Season transition. The
active Season must have all scheduled Competition Matches completed before it
can complete. The service then creates one deterministic upcoming successor,
continues Club/Competition memberships, applies the bounded Contract
renewal/release policy, and at the successor start creates fresh seasonal
registrations and fixtures. Replenishment fills only missing senior-squad
places and uses ordinary Player, Contract, squad, and registration services.

Season-bound rows are never mutated into the next Season: historical
Matches, registrations, memberships, and Contracts remain queryable. The
Phase-1 world keeps stable league membership; promotion/relegation,
retirement, true newgens, free-agent market behavior, and autonomous NPC
recruitment remain deferred. Rollover operations are retry-safe by existing
IDs and repository uniqueness checks.

## P2-013 Market-State Lifecycle

Market stature and Club compatibility are derived from current canonical
facts and are not a growing table. Controlled offers reuse bounded
`career_opportunities` rows; searches create no NPC market history. Offer
expiry, source-Club validation, and the existing idempotent TransferService
transition prevent stale or double-applied movement. Market reads and the
Transfer Market page do not generate offers or advance time. Legacy saves
receive no fabricated historical offers or facts.

## P2-014 Controlled Match Story Lifecycle

Controlled Match storytelling is a read model over finalized Match evidence.
`MatchStoryService` derives participation, minutes, timeline, highlights,
rating explanation, decisive contribution, and Career impact from immutable
Match results plus the existing selection, substitution, stat, development,
and social rows. Browsing Matchday, recent Match history, or the stable Match
detail route performs no gameplay writes and never reruns simulation.

Commentary uses deterministic `match-commentary:v1` templates and an isolated
hash namespace; it cannot perturb Match outcomes or future simulation. No new
timeline/detail rows are written for ordinary World Matches. Save/reload
therefore preserves the same story by re-derivation, while bounded integrity
checks validate score, minutes, participation, and Player-stat consistency.

## P2-016 Controlled Playing-Career Lifecycle

`PlayerLifecycleService` owns the controlled Player's Season-boundary age
phase, bounded decline handoff to `PlayerDevelopmentService`, retirement
assessment, and retirement decision resolution. Age is always derived from
Birth Date and `SimulationDate`; phase labels are read-model output. The
development source key remains the idempotency boundary, so retrying a
boundary cannot apply decline twice.

Completed-Season Legacy resolution runs before lifecycle retirement and before
compaction. A normal eligible Player receives one `CareerOpportunity` of type
`retirement` with Continue Playing and Retire options. Expiring/free-agent
Contract decisions and other open Career decisions take precedence. Retiring
terminates active payroll, removes future-season playing eligibility, persists
one lazy `career_retirement_records` row, and records one Career History
landmark. A forced maximum-age transition uses the same closure path without
creating a Player decision.

Retired saves remain readable and read-only. The progression query hides
active Contract/opportunity/action state, Career Continue stops safely, and
training, lifestyle purchases, transfer requests, offers, selection, and new
playing Contracts reject or close cleanly. Legacy saves without the retirement
table derive only the current phase and do not receive fabricated retirement
facts. Ordinary NPC retirement continues to use the compact population path;
no NPC retirement summary, Pulse ceremony, or post-Career simulation is added.

## P2-017 Readiness Ordering

Controlled Match completion applies workload from persisted participation
minutes and may apply one deterministic Match Injury source. Between-Match
training resolves once per `TrainingRequest` source key, first through the
existing development transaction and then through the availability workload
boundary. Intense training may add one deterministic training Injury source;
dispatch and Pulse processing happen after the transaction commits.

Fatigue recovery is a pure SimulationDate projection from current state.
Read-only Career Home, Profile, Training, and Matchday reads do not persist
recovery or reroll Injury state. At a simulation checkpoint, availability
reconciliation may mark due Injuries recovered before the next Match. No
temporary daily readiness rows are compacted or copied into a new Season.

## P2-018 Manager Context Ordering

Manager football context is derived after canonical social state, current
squad membership, recent selections, Match statistics, form, and availability
are available in the controlled Career summary. It is never a lifecycle
write. Current competitor presentation is rebuilt deterministically from the
bounded squad and does not survive as historical ranking data.

The playing-time conversation reuses the existing `career_events` source-key
and transactional resolve boundary. A Season rollover does not duplicate a
resolved conversation; a new Season only permits a new conversation if a new
sustained mismatch exists. No manager-context data is added to NPC lifecycle
passes or copied during compaction.

### P2-019 controlled positional state

`player_records.primary_position` remains the canonical primary-position
field. `player_position_development` stores only the controlled Player's
current established secondary positions, active target, bounded progress,
revision, and last simulation date. `career_position_changes` stores one
idempotent landmark per completed primary-position transition. Legacy saves
without these tables safely read as primary-only and do not receive
fabricated secondary history. NPCs create no positional-development rows.

Controlled detailed Matches additionally use `controlled_match_positions` to
snapshot the Player's position at Match time. Rating/history consumers prefer
that snapshot and fall back to the current primary position for old saves;
World-fidelity Matches never create the table's detail rows.

### P2-020 Club Season lifecycle

The current Club Season objective is a derived read model. Its source facts
are the current Season, Club/competition membership, canonical standings,
fixture schedule, promotion/relegation rules, and optional controlled Player
contribution. The original objective is derived from stable pre-Season Club
context and is not rewritten by a good or bad opening run.

At outgoing-Season completion, `SeasonRolloverService` resolves one compact
controlled Player/Club outcome before compaction removes or changes detailed
Season evidence. The outcome stores the objective, final evidence, and
resolution date. A new Season derives a fresh expectation after promotion or
relegation membership has been materialized. Existing saves without an
objective row safely receive current derived context; no historical objective
or pressure event is fabricated.

### P2-021 foot identity lifecycle

Player preferred-foot and weak-foot values are persisted with the canonical
Player row. New Players receive stable deterministic defaults. When an older
save lacks these columns, migration leaves them null and Player hydration
derives the identity from stable Player data; the first normal save persists
the result. This avoids both rerolls and a false all-right-footed migration.

Controlled weak-foot progress is compact, idempotent training state. It is
resolved in the existing training transaction before a save/reload boundary,
while Match action-foot metadata remains selective controlled-Match evidence.
No historical action is backfilled and no NPC foot-development rows are
created. Reading Profile, Training, or Match history does not advance state.

### P2-022 trait derivation lifecycle

Playing-style identity is derived at the Profile, Career summary, and Legacy
read boundaries. It is not a persisted state machine and page rendering does
not write. Detailed controlled Match evidence and compact NPC Season
aggregates are authoritative inputs; old saves safely show only what their
available evidence supports. Trait ordering, maturity, and active limits are
deterministic, and no trait derivation consumes gameplay RNG or participates in
Season lifecycle processing.

### P2-023 Career context lifecycle

`CareerClubContextService` is a pure read-model projection over the existing
progression summary. It groups chronological Club memberships into a bounded
journey, derives attachment and current Career direction, and identifies
former/breakthrough/longest/return context only when canonical evidence exists.
It writes no rows and is not called by lifecycle-wide NPC processing. Existing
transfer and Contract opportunity rows may carry the factual comparison needed
for an already-open controlled decision; no weekly attachment, ambition,
sentiment, or decision-score history is stored.

### P2-024 controlled role lifecycle

`OnPitchRoleService` owns the single controlled-Career role preference,
position compatibility, deterministic defaults, descriptive suitability, and
on-demand NPC derivation. P2-019 owns position capability, P2-021 owns
footedness, P2-022 owns evidence-derived style traits, and the Match selection,
action, and rating services retain their existing owners. A primary-position
change deterministically replaces an incompatible preference with the new
position's safe default; it does not grant a new capability.

Player-fidelity Match processing reads the role only for controlled Players and
uses it to nudge action-volume weights. It does not modify team outcome, action
success, rating, attributes, training, development, readiness, injury,
selection, finance, or RNG namespaces. World-fidelity processing remains
role-free. Match role is written only into the existing controlled position
snapshot, and no lifecycle-wide NPC work is scheduled.

The Profile owns normal presentation. Career Home surfaces only actionable or
meaningful direction, while Career and Legacy expose the compact Club journey.
Offer context is deterministic and neutral: source/target Club, competition,
level, role, wage, term, Europe, objective/playing-time context, and trade-offs
are facts rather than a universal score. Resolution continues through the
existing transfer/Contract owners. Legacy saves derive safely from available
records and never receive fabricated academy, breakthrough, return, or history
dates; the projection uses no RNG and cannot affect Match, rating, attributes,
development, readiness, selection, or finances.

### P2-026 recovery projection

Injury rehabilitation context is a read projection over P2-017's canonical
injury and availability rows plus completed controlled-Player Match statistics.
The projection has no write path: no rehab rows, comeback rows, daily fitness
rows, recovery scores, or page-render mutations are permitted. Planned and
actual recovery dates remain medical/availability facts, and Match selection
remains the authority for availability and return to selection.

The first Match back requires a retained meaningful injury followed by a real
appeared stat with positive minutes after the medical boundary. Substitution
and starter status are copied from canonical stats; unused bench and
unavailable states do not qualify. The projection is controlled-Player only,
on demand, bounded to significant episodes, and deterministic. It performs no
NPC rehabilitation ticks or World scan. Rehab choices and reinjury/setback
simulation remain deferred until an existing owner can support them without a
second injury state.

### P2-027 disciplinary lifecycle

Detailed Player-fidelity Match processing persists Match selections/statistics,
then processes disciplinary state in the same transaction. Existing active
state is served before current-Match card facts create a new sanction, so a
red received in the serving fixture cannot serve itself. A unique
`player_id|match_id|scope` source key makes repeated processing a no-op. The
selection gate reads this one state path and returns `Suspended`; it never
routes suspension through `PlayerAvailabilityService`.

The normalized scope mapper accepts League, Domestic Cup, Continental, and
International competitions and ignores friendlies. World-fidelity Matches do
not persist detailed Match stats or run a lifecycle-wide sanction scan. In
Player-fidelity Matches, compact state for detailed NPC participants is
eligible for the same selection gate, but no NPC narrative, history, Pulse,
or Career event is created. No RNG, development callback, manager penalty,
readiness mutation, or page-render write is attached to discipline.

### P2-028 Phase-2 integration and release gate

The Phase-2 stack keeps one owner per canonical fact: Match simulation writes
Match evidence, selection consumes medical and disciplinary eligibility,
P2-019 owns positional capability, P2-024 owns the single controlled on-pitch
role preference, P2-022 derives traits, P2-018 owns manager/squad context,
P2-020 owns Club objectives, P2-023 projects Club context and movement
decisions, P2-025 owns historical memory, P2-026 projects recovery context, and
P2-027 owns competition-scoped disciplinary eligibility. Position, role, traits,
squad role, readiness, medical availability, disciplinary eligibility, Club
attachment, Career direction, and reputation remain distinct read-model
concepts; none is a universal Player score or hidden gameplay modifier.

The consolidation gate found and removed two read-side Match lookup N+1 paths:
selection history and meaningful-injury return detection now use the chunked
`MatchRepository::byIds()` boundary. The same bounded read pattern is used by
Career Match-history projections, while transfer market context caches repeated
competition lookups within one projection. The batch stays below SQLite's
parameter limit and ignores missing historical references safely. This changes
no Match, injury, discipline, Career, or NPC state. `WorldService::load()` is
also strictly read-only; Season preparation, fixture materialization, and
national-team setup remain in initialize/advance/rollover paths. Profile/Home/
History reads therefore allow only the existing idempotent schema DDL guard,
not domain DML, narrative rows, World scans, or detailed NPC
traits/roles/rehab/social/finance processing. Save/reload remains the
persistence boundary for actual decisions, Match facts, and compact current
disciplinary state.

P2-028 validation covers the controlled production path, participation and
medical/disciplinary combinations, position-role-trait separation, Club and
international boundaries, transfer continuity, legacy defaults, idempotency,
determinism, bounded NPC/world fidelity, storage, and representative read and
Continue performance. Termux screenshot inspection remains an environment
limitation; automated graphical shell checks remain the release evidence.

END OF DOCUMENT
