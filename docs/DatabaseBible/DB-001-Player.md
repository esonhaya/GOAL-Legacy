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

Season-by-season breakdowns are stored separately.

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

END OF DOCUMENT
