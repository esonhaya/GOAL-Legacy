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

DOMAIN-004 implements only a stable primary position. Secondary positions,
preferred foot, and deeper role taxonomies remain deferred until their
canonical rules are defined.

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

END OF DOCUMENT
