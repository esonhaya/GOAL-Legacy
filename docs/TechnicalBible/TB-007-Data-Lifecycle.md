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

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-09-12 | Initial data lifecycle specification |

---

END OF DOCUMENT
