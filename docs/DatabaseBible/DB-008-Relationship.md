# GOAL: Legacy
# Database Bible

Document ID: DB-008
Title: Relationship Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

The Relationship table stores social, professional, and historical relationships between entities in GOAL: Legacy.

Relationships influence decisions, morale, chemistry, loyalty, media narratives, and long-term world simulation.

Rather than being limited to friendships, relationships represent how entities perceive one another over time.

---

# Ownership

Primary Module

Relationship Module

Supporting Modules

- Player
- Staff
- Club
- Match
- News
- Pulse
- Legacy

Only the Relationship Module may directly modify relationship values.

---

# Design Principles

- One record per relationship.
- Permanent Relationship ID.
- Dynamic evolution.
- Bidirectional support.
- Historical continuity.

---

# Lifecycle

Relationships are created when meaningful interactions occur.

Examples:

- Joining the same club.
- Playing together.
- Playing against each other repeatedly.
- Transfer negotiations.
- Coaching relationships.
- Rivalries.

Relationships evolve continuously throughout a career.

Historical relationships are never deleted.

---

# Primary Key

Relationship ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

---

# Relationship Participants

Stores references only.

Entity A

Entity B

Supported entity types include:

- Player
- Staff
- Club

Future phases may include:

- Board
- Agent
- Media
- Fans

---

# Relationship Type

Examples

- Friendship
- Respect
- Rivalry
- Mentorship
- Loyalty
- Professional
- Family (future)

Multiple relationship types may exist between the same entities.

---

# Relationship Strength

Stores a normalized value.

Examples:

100 = Exceptional

50 = Neutral

0 = Unknown

-100 = Hostile

Relationship strength changes gradually through gameplay events.

---

# Relationship Drivers

Examples

- Shared Matches
- Shared Clubs
- Championships
- Betrayals
- Transfers
- Training
- Media Events

These are references used by the Relationship Module.

---

# Hidden Components

Internal values may include:

- Trust
- Respect
- Familiarity
- Affection
- Jealousy
- Competitiveness
- Professionalism

These values influence AI decisions without always being shown to the player.

---

# Relationship Status

Examples

- Active
- Dormant
- Historical

Historical relationships continue to influence legacy systems.

---

# Gameplay Effects

Relationships may affect:

- Morale
- Transfer Interest
- Contract Negotiations
- Tactical Chemistry
- Leadership
- Squad Harmony
- Media Narratives

Actual calculations remain inside the Relationship Module.

---

# Validation Rules

Relationship ID cannot change.

Both referenced entities must exist.

Relationship values remain within defined limits.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

Relationship Module

Full Access

Match Module

Interaction references only

News Module

Narrative references only

Pulse Module

Discussion references only

Legacy Module

Historical archive only

---

# Performance Notes

Frequently indexed fields include:

- Relationship ID
- Entity A
- Entity B
- Relationship Type
- Relationship Status

Inactive relationships remain archived for historical purposes.

---

# Future Expansion

Future versions may include:

- Family trees
- Romantic relationships
- Agent networks
- Board politics
- Fan loyalty
- Media influence
- International rivalries

These additions should extend the existing structure without breaking compatibility.

---

# Locked Decisions

✓ Permanent Relationship ID

✓ Generic Relationship System

✓ Dynamic Relationship Evolution

✓ Hidden Relationship Components

✓ Historical Preservation

✓ Modular Ownership

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT
