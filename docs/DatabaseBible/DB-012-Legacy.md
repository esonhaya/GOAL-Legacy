# GOAL: Legacy
# Database Bible

Document ID: DB-012
Title: Legacy Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

The Legacy table preserves the long-term history of GOAL: Legacy.

It records achievements, records, milestones, awards, historical rankings, and career accomplishments that define the football world's collective memory.

Legacy ensures that past events continue to influence future generations.

---

# Ownership

Primary Module

Legacy Module

Supporting Modules

- Player
- Club
- Staff
- Match
- Competition
- News
- Pulse
- World

Only the Legacy Module may directly modify legacy records.

---

# Design Principles

- History is permanent.
- Achievements are immutable.
- Records are data-driven.
- Historical context is preserved.
- Legacy grows throughout the life of the universe.

---

# Lifecycle

Legacy records are created whenever a significant historical event occurs.

Examples:

- Trophy victories
- Record-breaking performances
- Career milestones
- Individual awards
- Hall of Fame induction
- Retirement
- Historic transfers

Legacy records are never deleted.

---

# Primary Key

Legacy ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

---

# Legacy Identity

Fields include:

- Legacy ID
- Legacy Type
- Created Date
- Season

---

# Legacy Types

Examples

- Trophy
- Record
- Award
- Milestone
- Hall of Fame
- Retirement
- Historic Match
- Historic Transfer

Additional types may be introduced later.

---

# Related Entities

Stores references only.

Examples:

- Player ID
- Club ID
- Staff ID
- Match ID
- Competition ID
- News ID

Only IDs are stored.

---

# Achievement Data

Examples

- Achievement Name
- Achievement Value
- Achievement Date
- Achievement Description

The Legacy Module determines how achievements are recorded.

---

# Historical Rankings

Examples

- Club Rankings
- Player Rankings
- Manager Rankings
- Competition Rankings

Ranking calculations remain inside the Legacy Module.

---

# Hall of Fame

Stores references to inducted entities.

Examples

- Player ID
- Staff ID
- Club ID (future)

Selection logic belongs to the Legacy Module.

---

# Career Timeline

Stores references to major career events.

Examples

- Debut
- First Goal
- First Trophy
- International Debut
- Retirement

Timeline presentation is handled separately.

---

# Validation Rules

Legacy ID cannot change.

Referenced entities must exist.

Legacy records cannot be removed through normal gameplay.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

Legacy Module

Full Access

Match Module

Achievement references only

Competition Module

Trophy references only

News Module

Historical references only

---

# Performance Notes

Frequently indexed fields include:

- Legacy ID
- Legacy Type
- Season
- Player ID
- Club ID

Legacy records remain permanently archived.

---

# Future Expansion

Future versions may include:

- Club museums
- Dynamic Hall of Fame voting
- Greatest XI selections
- Documentary features
- Historical anniversaries
- Fan-voted awards
- Statistical era comparisons

These additions extend the architecture without breaking compatibility.

---

# Locked Decisions

✓ Permanent Legacy ID

✓ Permanent Historical Records

✓ Data-Driven Achievements

✓ Hall of Fame Support

✓ Modular Ownership

✓ Historical Preservation

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT
