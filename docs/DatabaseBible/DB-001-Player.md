# GOAL: Legacy
# Database Bible

Document ID: DB-001
Title: Player Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

The Player table is the foundation of GOAL: Legacy.

Every footballer, from unknown academy prospects to legendary retirees, is represented by a single player record.

The Player table serves as the central source of truth for player identity and long-term career data. Other systems reference players by their unique identifier rather than duplicating player information.

---

# Ownership

Primary Module

Player Module

Supporting Modules

- Match
- Training
- Transfer
- Club
- Staff
- Relationship
- News
- Pulse
- Legacy
- Achievement

Only the Player Module may directly modify player-specific data unless explicitly authorized.

---

# Design Principles

- One record per player.
- Permanent Player ID.
- Historical continuity.
- Minimal duplication.
- Modular ownership.

DOMAIN-004 Phase 1 treats Player as the human football entity. It does not
introduce a generic Person table or inheritance hierarchy before Staff has a
separate canonical identity requirement.

---

# Lifecycle

A player record is created when:

- A new save is generated.
- A youth player is generated.
- A regenerated player is created (future phases).

A player record is never deleted.

When a player retires, the record becomes historical.

DOMAIN-014 adds a durable `career_state` (`active` or `retired`) to this
same Player record. Age remains derived from birth date and SimulationDate;
season-boundary lifecycle processing reuses PlayerDevelopmentService for
age-sensitive progression and bounded decline. Retired Players remain
queryable with all historical Match, development, Contract, registration,
injury, and transfer records, but cannot receive an active Contract, join a
future squad, register, transfer, or be selected.

Long-term replacement Players are deterministic newgens, but they are
ordinary Player records immediately. They are save-state entities rather
than static content and receive the normal Contract, squad, registration,
selection, availability, and development treatment.

P2-016 keeps the retirement transition Player-owned and adds one compact,
controlled-career `career_retirement_records` row containing the retirement
date, retirement Season, final Club when applicable, reason, and forced flag.
The table is created lazily when a controlled Player retires so legacy saves
without it remain readable and do not receive fabricated retirement history.
The row is idempotent by Player ID; it is not a per-Season NPC summary.

Career phase is derived from birth date, SimulationDate, and career state.
Retirement eligibility is a deterministic Season-boundary assessment using
bounded age, current football context, Contract availability, position, and
recent performance. It does not persist a hidden retirement score. Contract
expiry/free agency and existing controlled Career decisions take precedence
over opening a retirement choice, and the forced maximum age prevents an
unbounded active playing Career.

---

# Primary Key

Player ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

Every relationship in the database references Player ID.

---

# Identity

Stores permanent personal information.

Fields include:

- Player ID
- First Name
- Last Name
- Preferred Name
- Nationality
- Secondary Nationality
- Birth Date
- Birth Nation
- Birth City
- Height
- Weight
- Preferred Foot

Identity values rarely change.

Phase 1 stores a full name, preferred name, birth date, birth Nation ID,
primary Nation ID, optional secondary Nation IDs, height, and weight. Nation
names are never copied into Player records.

---

# Football Profile

Stores football-specific identity.

Fields include:

- Primary Position
- Secondary Positions
- Playing Style
- Personality Archetype
- Dominant Foot
- Weak Foot Rating
- Skill Move Rating

The primary position remains the one canonical value in `player_records`.
P2-019 adds controlled-Career-only positional development in compact
`player_position_development` state: established secondary positions,
one developing target, and bounded progress. It reuses the existing coarse
position enum and adjacent attribute-fit rules; it does not add a second
position taxonomy or NPC retraining rows. A completed target may become the
explicit primary position, while the previous primary is retained as a
secondary capability.

---

# Attributes

Stores technical and physical abilities.

Examples

- Pace
- Acceleration
- Strength
- Balance
- Passing
- Vision
- Dribbling
- Finishing
- Positioning
- Tackling
- Goalkeeping

Attribute balancing is defined separately by the Player Module.

The Phase 1 headline set is Pace, Shooting, Passing, Dribbling, Defending,
and Physicality, each bounded from 0 through 99. Overall rating is derived
from their arithmetic mean, rounded down; it is not stored as a second
authoritative value.

Potential is Player-owned and bounded from 1 through 99. A newly created
Player's derived overall rating cannot exceed potential. Development profile
is one of `late_bloomer`, `regular`, or `prodigy`. The Player Module owns
durable development state and immutable progression history; its development
service is the only path that applies training or Match experience to Player
attributes. Overall rating remains derived and cannot exceed potential.

---

# Dynamic State

Changes frequently during gameplay.

Includes

- Fitness
- Sharpness
- Morale
- Confidence
- Match Form
- Fatigue
- Injury Status

Updated weekly or after matches.

---

# Hidden Data

Not directly visible to players.

Examples

- Ambition
- Professionalism
- Loyalty
- Leadership
- Pressure Handling
- Injury Proneness
- Consistency
- Adaptability

Hidden values influence AI behaviour and long-term career development.

---

# Career Information

Tracks career progression.

Includes

- Current Club
- Contract
- Squad Number
- Reputation
- Market Value
- Salary
- Career Stage

The current Club is not duplicated on the Player record in Phase 1. A
season-bound squad relationship is the canonical assignment. The controlled
career Player is represented by a Career Player reference containing a
Career ID, Player ID, and deterministic career start date; it does not copy
Player identity or attributes.

---

# Relationships

References external tables.

Examples

- Club ID
- Manager ID
- Agent ID
- Partner ID (future)
- Parent ID (future)
- Child ID (future)

Only IDs are stored.

Squad membership is a normalized Club-owned relationship containing Club ID,
Player ID, and Season ID. It is not a Contract and does not imply wages,
transfer rights, or employment terms. A Player has at most one squad
membership per Season in this phase.

Nationality and international eligibility are distinct Player-owned ID
relationships. Eligibility is a minimal explicit Nation-ID set; it is not a
full national-team selection or FIFA-law engine.

DOMAIN-009 adds durable current availability state to the Player lifetime.
Fatigue is a bounded load value that is increased by real Match minutes and
explicit training blocks and recovered lazily from SimulationDate. Injury
history is retained as structured Player-referenced records; current
availability is derived from active Injury state, fatigue, and the existing
administrative eligibility path. No daily Player tick or wall-clock state is
stored.

---

# Statistics

Stores current career totals.

Examples

- Appearances
- Goals
- Assists
- Clean Sheets
- Minutes Played
- Cards
- Average Rating

Phase-1 season and career totals are projections from Match Player stat lines.
They are not a competing mutable counter source of truth.

---

# Awards

References achievement records.

Examples

- League Titles
- Cups
- Individual Awards
- Team Awards

---

# Validation Rules

Player ID cannot change.

Birth date cannot change.

Nationality may change only through approved gameplay rules.

Current club may only change through:

- Transfers
- Loans
- Free agency

Player creation rejects missing or duplicate Nation references, invalid
physical ranges, unsupported positions or development profiles, and
attributes or potential outside their bounded ranges.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

Player Module

Full Access

Match Module

Match statistics only

Training Module

Growth-related values only

Transfer Module

Club assignment and contract references only

Relationship Module

Relationship references only

Legacy Module

Historical records only

---

# Performance Notes

Frequently queried fields should be indexed.

Examples

- Player ID
- Current Club
- Nationality
- Reputation
- Current Status

Historical statistics should remain separated from active player records.

---

# Future Expansion

Future versions may include:

- DNA profiles
- Bloodlines
- Languages
- Media personality
- Political influence
- Club icon status
- Mentorship
- Family reputation

These additions should extend existing structures without breaking compatibility.

---

# Locked Decisions

✓ Permanent Player ID

✓ One Record Per Player

✓ Historical Preservation

✓ Hidden Personality System

✓ Modular Ownership

✓ Data-Driven Expansion

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

## DOMAIN-010 Population Boundary

NPC Players use the canonical Player record and are generated as
deterministic mutable save state, not static content-package records.
Generation metadata identifies the generator version and seed without adding
an NPC-only football model.

## P2-017 Readiness State

`player_availability_state` remains a compact current-state row: fatigue,
last processed SimulationDate, and revision. `player_availability_sources`
provides stable Match/training source identity, while `player_injuries` stores
bounded Injury occurrences and expected/actual recovery dates. No daily
fitness history is stored. Readiness labels such as Fresh, Ready, Managed,
Tired, Fatigued, and Injured are derived from these facts for the controlled
Player read model.

Training intensity is derived from existing Career priority and is not a new
persisted progression value. Light/normal/intense workload is applied by the
Player availability service; development stimulus is still owned by
`PlayerDevelopmentService`. Legacy saves safely default to zero fatigue and
no fabricated Injury history when these tables or rows do not exist.

## P2-018 Manager Football Context

Manager football trust is intentionally not a new table or continuously
mutating score. `ManagerTrustService` derives a controlled-player read model
from the existing `player_social_states.manager_score` relationship context,
current squad role, positional competitors, recent canonical selections and
Match minutes, form, availability, and discipline. It returns labels,
playing-time expectation, competition status, factual reasons, feedback, and
conversation eligibility.

The existing `career_events` table is reused for an occasional sustained
playing-time conversation. Its source key, Season repeatability, and
transactional resolution provide idempotency. NPC Players receive no manager
trust rows, competition-history rows, or conversation state. Position
competitor names are a bounded current read model, not a persistent rivalry
graph.

## P2-021 Preferred Foot and Weak-Foot Identity

`player_records.preferred_foot` is the single immutable preferred-foot identity
(`left` or `right`). `weak_foot` is a compact capability tier: `limited`,
`usable`, `comfortable`, or `strong`. These values are Player identity, not
attributes and not a second OVR. They are consumed only through bounded wide-
position context and controlled-Match action selection.

Newgens receive stable values from the versioned creation identity. Legacy
rows are migrated with nullable columns so hydration can derive the same value
from Player ID/creation seed and then persist it; no historical Match action is
assigned a foot retroactively. Only controlled Players may have a
`player_weak_foot_development` row. Training uses the existing cadence and
specialized-focus boundary, with compact progress and no NPC training history.

## P2-022 Derived Playing Style

There is deliberately no `player_traits` table. `PlayerTraitService` derives
the controlled or browsed Player's established and emerging identity from
`player_records`, `player_match_stats`, and `player_season_statistics`, plus
the canonical position-development and weak-foot state where applicable.
The result is deterministic and read-only, with an established display limit
of five and an emerging display limit of three. No weekly snapshots,
historical unlock dates, or NPC trait rows are persisted. Older saves therefore
need no fabricated trait migration; the same available evidence produces the
same Profile and Legacy result after reload.

The creation `development_profile` (`Prodigy`, `Regular`, or `Late Bloomer`)
remains a development/archetype input and is not a playing-style trait. A
derived style can therefore differ between Players with the same creation
profile, and no style value is written back into Player identity or attributes.

## P2-023 Derived Club Journey Context

Club attachment, Career direction, former Clubs, breakthrough Club, Club
journey, returns, longest spell, and one-Club descriptors are read-only
projections of existing `club_squad_memberships`, Contract/transfer history,
Season aggregates, roles, and controlled Career context. There is deliberately
no attachment, loyalty, ambition, sentiment, decision-score, or weekly
snapshot table. A former Club is only presented as a return context when a
legitimate existing offer or movement record identifies it; no offer is
manufactured.

Decision opportunities may retain the factual context needed to resolve an
already-canonical controlled choice, but no new gameplay state is introduced.
Save/reload and older P2-022 saves derive the same context from whatever
canonical records exist. Missing academy, breakthrough, return, or historical
decision facts remain unknown rather than being fabricated. NPCs create no
attachment rows, ambition ticks, Career-decision events, or relationship
history system.

## P2-024 On-Pitch Role Persistence

`career_player_references.preferred_on_pitch_role` is the single nullable
controlled-Career preference for position-compatible on-pitch usage. It is not
a role history, experience, training, or NPC table. Existing references with a
missing value derive the deterministic position default, so P2-023 and earlier
saves require no migration data and receive no fabricated history.

`controlled_match_positions.role` reuses the existing controlled Match
position snapshot. It records the actual role used for a controlled Player who
participated, allowing Matchday/Post-Match to distinguish the deployed role
from a current preference. No weekly snapshots, role-performance table, role
XP, or page-render writes are introduced. The nullable column is added safely
to older save schemas and unknown values resolve to the position default.

## P2-026 Injury Recovery Context

P2-017's `player_injuries`, `player_availability_state`, and availability
assessment remain the sole durable/derived owners of injury status, recovery
dates, fatigue, and readiness. `CareerRecoveryService` reads those records
together with retained completed Match statistics to classify a bounded
controlled-Player recovery phase and identify a first real Match back after a
meaningful injury. It does not add a rehabilitation, comeback, setback, or
medical-history table.

Only moderate/major injury rows are exposed as significant episodes. An
unused selection never counts as a return; the Match-stat `appeared` and
positive minutes boundary is authoritative. Recovery pages are read-only and
do not write state, daily snapshots, event rows, or NPC data. If old saves do
not retain enough injury or Match chronology, the projection omits the claim
rather than fabricating a date or comeback.

## P2-027 Disciplinary Eligibility State

`match_player_stats` remains the canonical card/dismissal fact store. The
`player_discipline_states` table is a compact current projection keyed by
`player_id` and normalized scope (`domestic_league`, `domestic_cup`, `europe`,
or `international`). It stores the current Season's yellow accumulator,
remaining applicable-Match ban, reason, and source Match/competition. The
`player_discipline_sources` table is only an idempotency boundary for a
card-bearing or actively suspended Player/Match pair; it is not an eligibility
history or narrative event table.

The projection is created lazily and is safe for P2-026 and older saves. Reads
derive no sanction from old aggregate card totals, so legacy saves begin with
no fabricated active ban. Five yellows create a one-Match scoped ban and reset
that accumulator; any red creates a one-Match ban and resets yellow
accumulation. The accumulator cycle changes with the Season without deleting an
active ban. Serving is written only by completed applicable detailed Match
processing, never by page rendering, calendar passage, friendly fixtures,
injury recovery, or free agency.

END OF DOCUMENT
