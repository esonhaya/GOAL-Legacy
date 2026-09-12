# GOAL: Legacy
# Database Bible

Document ID: DB-014
Title: Nation Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-09-12

---

# Purpose

The Nation record stores the persistent identity and Nation-specific state
of a Nation in a GOAL: Legacy world.

Nation is a deliberate domain concept rather than an assumption that every
Nation is identical to a country. It can represent England, Scotland, Wales,
territories, or Nations created for a custom world while keeping relationships
stable.

This document defines database ownership and references. It does not define
football rules or the schemas of clubs, players, competitions, or matches.

---

# Ownership

Primary Module

Nation Module

Supporting Modules

- World
- Club
- Player
- Competition
- Content
- Legacy

Only the Nation Module may directly modify Nation-owned identity and state.
Other modules consume Nation data or maintain their own references to Nation
IDs according to their documented ownership.

---

# Design Principles

- One canonical Nation record per Nation in a world.
- Permanent, stable Nation ID.
- Nation identity is not duplicated in World, Club, Player, or Competition.
- Cross-domain relationships use stable IDs.
- Baseline content and mutable save state remain separate.
- Historical references are preserved rather than silently reassigned.

---

# Lifecycle

A Nation record is materialized when a world is created from installed and
selected content, or when an explicitly supported custom-world source adds a
Nation.

The record persists for the lifetime of the save. A Nation identity is not
deleted during normal play; an unavailable or defunct Nation remains
addressable for historical references. Any future archive or retirement
policy belongs to the Nation Module.

The lifecycle categories follow AF-010 without creating a separate lifecycle
schema in this document:

- **Permanent:** Nation ID and canonical identity for the world.
- **Career:** mutable Nation state materialized in a save, when such state is
  defined by the Nation Module.
- **Seasonal:** snapshots or participation records owned by the relevant
  seasonal or competition domain, not the Nation identity.
- **Temporary:** import, generation, and validation working data.
- **Runtime:** resolved objects, indexes, and caches; never canonical save
  state.

The World record has the same save lifetime, but owns only world-level state
and references to Nations.

---

# Primary Key

Nation ID

Properties

- Required.
- Stable within the world and save.
- Permanent once assigned.
- Unique and never reused within that world.
- Used for all cross-entity Nation references.

---

# Canonical Fields

## Identity

The Nation Module owns the following identity fields:

- Nation ID
- Canonical Name
- Display Name
- Optional Nation Code

Nation Code is a stable project/content identifier when supplied. It is not
required to be an ISO country code and must not be used to collapse Nation
and country into one concept.

## Association and Geography References

Optional references may include:

- Football Association or Federation ID
- Region ID
- Geography reference ID

These are references to separately owned records. Nation does not own clubs,
players, competitions, matches, or a global geography model merely because it
references them.

## Nation-Specific State

Mutable Nation-specific state may be added by the Nation Module in later
phases. Such state must have an explicit lifetime and owner. It must not be
used as a duplicate of player, club, competition, World, or runtime state.

---

# Relationships

Relationships store IDs only:

| Related domain | Relationship | Ownership boundary |
|---|---|---|
| World | World references the Nations present in its world | World owns the reference/index, Nation owns the record |
| Club | Club references its affiliated Nation by Nation ID | Club owns the affiliation; Nation owns Nation identity |
| Player | Player stores nationality, birth-Nation, or eligibility references | Player owns the player relationship; Nation owns Nation identity |
| Competition | Competition references participating or governing Nations | Competition owns competition relationships and rules |
| Content package | Nation records preserve baseline package ID/version provenance | Package owns declarative source data; save owns mutable state |
| Legacy/history | Historical records refer to Nation IDs | Legacy/history owns historical records |

References do not grant write ownership. A Nation may index or expose related
IDs, but it does not contain the canonical records for those domains.

---

# Invariants

- Nation ID is present, valid, unique, and immutable after creation.
- Canonical Name and Display Name are non-empty valid text values.
- Nation Code, association/federation, region, and geography references are
  optional but must be valid when present.
- Every referenced Nation ID resolves within the same world/save context.
- A Club, Player, or Competition stores a Nation ID rather than a duplicated
  Nation identity snapshot, except for explicitly historical immutable data.
- Malformed or unsupported source data fails validation; it is not silently
  normalized into a different Nation.

---

# Persistence

Nation records belong to the save database for the world in which they are
materialized. TB-003 and TB-005 define the database engine and save boundary;
this document does not prescribe SQL table details.

Installed content packages provide declarative baseline Nation data. The save
stores the selected package ID/version provenance and the mutable Nation state
needed by that save. A package manifest is not the canonical save schema and
does not replace the Nation record.

Nation data must be persisted as data, not executable behavior. Runtime
objects, caches, event listeners, and service wiring are not serialized as
Nation state.

---

# Versioning

Package version, content schema version, and save schema version are distinct:

- The package version identifies the source package release.
- The content schema version identifies the shape of package data.
- The save schema version identifies the persisted save format.

Known compatible content schemas may be loaded. An unsupported newer schema
fails clearly. No package or Nation data is silently migrated or rewritten by
this domain specification. Any future migration must preserve stable Nation
IDs and be defined by the relevant versioning architecture.

---

# Explicit Exclusions

Nation does not own:

- Clubs or club affiliation records.
- Players, staff, nationality/eligibility relationship records, or contracts.
- Matches, transfers, competitions, or competition runtime state.
- World identity, calendar, simulation configuration, or global indexes.
- Content package manifests or executable package behavior.
- Runtime caches, event listeners, scheduler callbacks, or service objects.

---

# Locked Decisions

✓ Nation is a first-class persistent domain entity

✓ Nation is distinct from country as a representation concept

✓ Nation owns Nation identity and Nation-specific state

✓ World, Club, Player, and Competition use Nation IDs for relationships

✓ One persistent fact has one canonical owner

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-09-12 | Initial Nation ownership specification |

---

END OF DOCUMENT
