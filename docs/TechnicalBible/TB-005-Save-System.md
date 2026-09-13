# GOAL: Legacy
# Technical Bible

Document ID: TB-005
Title: Save System

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

This document defines how career saves are created, stored, loaded, protected, and maintained throughout the lifetime of GOAL: Legacy.

The save system is designed around one core philosophy:

A save is not just progress.

A save is an entire football universe.

---

# Design Philosophy

Every save represents a unique alternate football timeline.

Nothing should exist outside the save that is required to continue playing.

Every universe should remain completely independent from every other universe.

---

# Core Principles

## One Save = One Universe

Each save contains:

- Complete world state
- Players
- Clubs
- Competitions
- Calendar
- History
- News
- Pulse
- Relationships
- Legacy

DOMAIN-005 also persists Contract employment rows, Season-bound Player
registrations, and historical Transfer transaction rows in the same save
database. Runtime services and event listeners remain non-persistent.

Everything required to continue the career exists inside the save.

---

## One Save = One Database

Phase 1 stores every save as a single SQLite database.

Advantages:

- Portable
- Easy backup
- Fast loading
- No internet required
- Easy version upgrades

## Content Package and Save State

Installed content packages are declarative sources for baseline data. They
remain separate from mutable career state. A domain validates and materializes
selected content into the save database, retaining source package/version
provenance when required.

Changing installed content does not automatically rewrite an existing career.
Runtime services, event listeners, scheduler callbacks, and caches are not
serialized as save state; see TB-007.

---

# Save Metadata

Every save stores metadata separately from gameplay data.

Metadata includes:

- Save Name
- Manager Name
- Club
- Season
- Current Date
- Hours Played
- Difficulty
- Universe Seed
- Game Version
- Last Saved
- Autosave Timestamp

This information is displayed on the Load Game screen.

---

# Universe Seed

Every career receives a permanent Universe Seed.

The seed influences procedural generation including:

- Youth players
- Hidden traits
- Football cultures
- News flavour
- World randomness

The seed never changes after save creation.

---

# Save Slots

Phase 1 supports multiple manual save slots.

Each slot is completely independent.

No shared progression exists between saves except account-wide achievements and unlocks.

---

# Autosave

Autosave occurs automatically at safe checkpoints.

Examples:

- End of week
- End of match
- End of transfer window
- Season transition

Autosave should never interrupt gameplay unnecessarily.

---

# Manual Saves

Players may manually save at any safe point outside active simulations.

Manual saves never overwrite autosaves unless selected.

---

# Backup System

Each save maintains an automatic backup.

Purpose:

- Protect against corruption
- Recover interrupted writes
- Restore previous stable state

Backups are created before major write operations.

---

# Save Integrity

Every save performs integrity validation when loaded.

Checks include:

- Missing records
- Invalid references
- Corrupted IDs
- Version mismatch

If recoverable, the game repairs the save automatically.

If unrecoverable, the player is informed before loading.

---

# Version Compatibility

Every save stores the game version used when it was created.

When loading:

Same Version

↓

Load Normally

Newer Version

↓

Run Database Migration

Older Game Version

↓

Loading Not Supported

Migration scripts must preserve existing careers whenever technically possible.

---

# Historical Preservation

History is never discarded.

Examples:

- Retired players
- League champions
- Awards
- Records
- Transfers
- Manager careers

Historical data becomes part of the universe's legacy.

---

# Legacy Support

Every save contributes to the player's long-term account profile.

Examples:

- Career statistics
- Unlockable origins
- Hall of Fame entries
- Family bloodlines (future phases)
- Universe Score records

These unlocks do not modify existing saves.

---

# Save Performance

Save operations should prioritize reliability over speed.

Large updates should be grouped into transactions.

Database writes should avoid unnecessary duplication.

Loading should initialize only required systems first, with secondary systems loaded afterward when possible.

---

# Anti-Corruption Strategy

The save system should protect against:

- Interrupted writes
- Power loss
- Invalid migrations
- Missing assets
- Partial updates

Recovery should always attempt to preserve the career before reporting failure.

---

# Future Expansion

Future versions may include:

- Cloud synchronization
- Cross-device saves
- Save compression
- Export and import tools
- Career sharing
- Replay snapshots

These features are outside the scope of Phase 1.

---

# Locked Decisions

✓ One Save = One Universe

✓ One Save = One SQLite Database

✓ Permanent Universe Seed

✓ Automatic Autosaves

✓ Manual Save Slots

✓ Automatic Backup Creation

✓ Version Migration Support

✓ Historical Preservation

✓ Offline First Design

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT
