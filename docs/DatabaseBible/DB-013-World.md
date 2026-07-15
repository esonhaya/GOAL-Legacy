# GOAL: Legacy
# Database Bible

Document ID: DB-013
Title: World Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

The World table stores the global simulation state of GOAL: Legacy.

It coordinates time progression, seasonal transitions, calendar management, procedural generation, and world-level settings shared across every module.

Unlike other tables, the World table represents the universe itself rather than an individual football entity.

---

# Ownership

Primary Module

World Module

Supporting Modules

- Player
- Club
- Staff
- Competition
- Match
- Economy
- News
- Pulse
- Legacy

Only the World Module may directly modify world state.

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

# Calendar

Stores current simulation time.

Examples:

- Current Date
- Current Week
- Current Month
- Current Year
- Current Season
- Day of Week

The calendar drives every scheduled event.

---

# Season State

Stores the current football season.

Examples:

- Season ID
- Transfer Window Status
- Registration Period
- Competition Phase
- International Break Status

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

# World Statistics

Examples:

- Total Players
- Total Clubs
- Total Staff
- Total Matches
- Total Competitions

These values are generated automatically.

---

# Global Settings

Stores save-specific settings.

Examples:

- Difficulty
- Simulation Speed
- Injury Frequency
- Youth Generation Level
- Financial Difficulty

Only gameplay settings are stored here.

---

# Event Queue

Stores references to scheduled future events.

Examples:

- Fixtures
- Contract Expirations
- Youth Intake
- Awards
- Transfer Window Events

Detailed event processing remains inside individual modules.

---

# Relationships

References:

- Current Season ID
- Active Competition IDs
- Calendar ID (future)

Only IDs are stored.

---

# Validation Rules

World ID cannot change.

Only one active World record exists per save.

Simulation date cannot move backwards through normal gameplay.

Referenced seasons must exist.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

World Module

Full Access

League Module

Season references only

Match Module

Fixture progression only

Legacy Module

Historical season references only

---

# Performance Notes

Frequently indexed fields include:

- World ID
- Current Date
- Current Season
- Simulation State

Only one active World record exists, making lookups efficient.

---

# Future Expansion

Future versions may include:

- Dynamic rule changes
- Climate effects
- Global economic trends
- International football politics
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

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT

