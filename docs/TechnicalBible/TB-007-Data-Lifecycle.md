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

✓ Player fatigue/load state is Career-durable current state and is recovered
from its last processed SimulationDate on demand. Match and training source
keys make load application idempotent.

✓ Injury occurrences are Career-durable structured Player records with an
expected SimulationDate recovery boundary. Availability is a derived/current
projection; recovery does not require a serialized callback or a daily global
Player tick.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-09-12 | Initial data lifecycle specification |

---

END OF DOCUMENT
