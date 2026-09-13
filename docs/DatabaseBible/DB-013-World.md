# GOAL: Legacy
# Database Bible

Document ID: DB-013
Title: World Database

Version: 1.1
Status: Draft
Author: Jaime Haya
Last Updated: 2026-09-12

---

# Purpose

The World table stores the global/root simulation state of GOAL: Legacy.

It coordinates the global simulation timeline, world-level configuration,
content references, and indexes shared across modules.

Unlike other tables, the World table represents the universe itself rather
than an individual football entity. It references Nation records; it does not
own Nation identity or Nation-specific state. See DB-014.

---

# Ownership

Primary Module

World Module

Supporting Modules

- Nation
- Player
- Club
- Staff
- Competition
- Match
- Economy
- News
- Pulse
- Legacy

Only the World Module may directly modify World-owned state. Nation-owned
records remain under the Nation Module.

---

# Design Principles

- One world per save.
- Permanent World ID.
- Single source of truth.
- Deterministic simulation.
- Modular coordination.

---

# Lifecycle

A World record is created when a new save is generated.

The record persists for the lifetime of the save.

A World record is never deleted unless the save itself is removed.

World state is save-scoped. Runtime objects, event listeners, scheduler
callbacks, and derived caches are not canonical World persistence.

---

# Primary Key

World ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

---

# World Identity

Stores permanent information.

Fields include:

- World ID
- Universe Seed
- Save Name
- Creation Date
- Game Version

The Universe Seed never changes after creation.

---

# Global Simulation Timeline

Stores or references the current global simulation time.

Examples:

- Simulation Time
- Calendar ID or timeline reference
- Current year/season reference where a domain defines one

The Core clock and scheduler advance simulation time. World owns the global
timeline reference; calendar policy and competition scheduling remain with
their owning modules.

---

# Global Cycle References

World may store references to global cycles needed to coordinate a save.
Detailed season and competition state is owned by the relevant domain.

The current Phase-1 persistence boundary supports a durable current Season
reference and historical completed Season data. `SeasonRolloverService` is
the single World lifecycle coordinator for the recurring league loop: after
all scheduled Matches are complete it prepares the next Aug 1--May 31
Season, applies bounded Club Contract renewal/release, and at activation
materializes seasonal Competition memberships, replenishes viable squads,
creates registrations, and generates fixtures through their owning modules.
Completed Season rows remain historical and are never reused as current
registration or fixture state. Promotion/relegation, retirement, newgens,
and autonomous NPC recruitment remain deferred.

Examples:

- Current Season ID
- Current Year ID
- Global timeline phase reference

---

# Simulation State

Stores the current world status.

Examples:

- Running
- Paused
- Simulating
- Processing Events

Used internally by the simulation engine.

---

# Global Indexes and Statistics

World may maintain non-authoritative indexes or derived counts for global
lookup and diagnostics.

Examples:

- Nation IDs present in the world
- Installed and selected content package IDs/versions
- Derived counts of domain records

These values are generated automatically and must not become duplicate
canonical records. The owning domain remains the source of truth.

---

# Global Settings

Stores save-specific settings.

Examples of generic world-level profiles:

- Difficulty profile
- Simulation profile
- Generation profile

Only global/save configuration is stored here. Domain-specific settings are
owned by their respective domains.

---

# Event Queue

Stores references to scheduled future events.

Examples:

- Serializable references to future world work
- Global timeline work identifiers

The Core scheduler owns runtime execution. Scheduled callbacks and event
listeners are not stored as World data. Detailed event processing remains
inside individual modules.

---

# Relationships

References:

- Current Season ID
- Active Competition IDs
- Nation IDs
- Installed/selected content package IDs and versions
- Calendar or global timeline ID

Only IDs are stored.

---

# Validation Rules

World ID cannot change.

Only one active World record exists per save.

Simulation date cannot move backwards through normal gameplay.

Referenced seasons, competitions, Nations, and content packages must exist in
the applicable world/save context.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

World Module

Full Access to World-owned state and global references.

Nation Module

Nation records only; it does not own the World record.

Competition Module

Season references and global Competition references only

Match Module

Fixture progression only

Legacy Module

Historical season references only

---

# Performance Notes

Frequently indexed fields include:

- World ID
- Current simulation time
- Current Season ID
- Nation IDs
- Simulation State

Only one active World record exists, making lookups efficient.

---

# Future Expansion

Future versions may include:

- Dynamic rule changes
- Climate effects
- Global economic trends
- Fictional universes
- Women's football integration
- Parallel timelines
- Historical start dates

These additions extend the architecture without breaking compatibility.

---

# Locked Decisions

✓ One World Per Save

✓ Permanent World ID

✓ Permanent Universe Seed

✓ Single Source of Truth

✓ Modular Coordination

✓ Historical Preservation

✓ Nation records are owned by the Nation Module and referenced by World

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |
| 1.1 | 2026-09-12 | Moved Nation ownership to DB-014; clarified global references and lifetimes |

---

## DOMAIN-010 Population Phase

World creation retains the existing initializer and adds an explicit
population phase for a viable career save. The Player Module consumes the
durable World seed after Nations, Season, Competitions, and Clubs exist;
World does not own generated Player records.

END OF DOCUMENT
