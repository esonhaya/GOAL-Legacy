# GOAL: Legacy
# Database Bible

Document ID: DB-002
Title: Club Database

Version: 1.1
Status: Draft
Author: Jaime Haya
Last Updated: 2026-09-12

---

# Purpose

The Club table stores the permanent identity and current state of every football club in GOAL: Legacy.

A club is more than a team sheet. It is a living organization with finances, facilities, staff, supporters, football culture, and history.

The Club table is the central source of truth for every club in the universe.

---

# Ownership

Primary Module

Club Module

Supporting Modules

- Match
- Competition
- Transfer
- Staff
- Economy
- World
- News
- Pulse
- Legacy

Only the Club Module may directly modify club-specific data unless explicitly authorized.

---

# Design Principles

- One record per club.
- Permanent Club ID.
- Historical continuity.
- Data-driven identity.
- Modular ownership.

---

# Lifecycle

A club record is created when a new universe is generated.

Official clubs are never deleted.

Defunct clubs remain archived for historical purposes.

Expansion clubs (future phases) receive new permanent records.

---

# Primary Key

Club ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

All systems reference clubs using Club ID.

---

# Identity

Stores permanent information.

Fields include:

- Club ID
- Club Name
- Short Name
- Nickname
- Nation ID
- Local Region
- City
- Year Founded
- Home Stadium
- Club Colors
- Club Badge ID

Identity rarely changes.

Nation ID is a stable reference to the canonical Nation record defined by
DB-014. Local Region describes the club's locality and does not duplicate
Nation geography or identity.

---

# Club Identity Model

Club identity is represented through three related layers. These layers must
not be collapsed into one permanent universal DNA field.

## Core Philosophy

Long-lived institutional tendencies and values that influence decisions over
multiple generations.

## Football Identity

The club's established sporting approach and football profile.

## Current Style

The current manager/team expression. It may change more readily than the
Core Philosophy or Football Identity.

The three layers are declarative baseline data in content and become mutable
Club state after materialization.

Fields include:

- Tactical Tradition
- Preferred Formation
- Playing Philosophy
- Youth Reputation
- Club Culture
- Rival Clubs

These values influence AI decisions.

---

# Reputation

Tracks the club's standing.

Examples:

- Domestic Reputation
- Continental Reputation
- Worldwide Reputation
- Fanbase Size
- Prestige

Reputation changes throughout gameplay.

---

# Facilities

Stores infrastructure quality.

Includes:

- Training Facilities
- Youth Academy
- Medical Facilities
- Scouting Network
- Stadium Quality

Facilities improve or decline over time.

---

# Financial Data

Stores current finances.

Examples:

- Balance
- Wage Budget
- Transfer Budget
- Annual Revenue
- Annual Expenses
- Sponsorship Income
- Debt

Managed primarily by the Economy Module.

---

# Squad Information

Stores references only.

Examples:

- Manager ID
- Captain ID
- Vice Captain ID

Players are referenced through Player IDs.

Squad lists are generated through relationships rather than duplicated.

DOMAIN-004 represents the squad list as a normalized, season-bound
Club–Player relationship containing IDs only. The Club Module owns that
relationship; Player identity and football state remain owned by the Player
Module. Squad membership is not a Contract.

---

# Competition Data

Stores season-bound participation references to competitions. The Club Module
may materialize these references into normalized relationship records; it
does not copy Competition or Season records.

Examples:

- Current League
- Domestic Cup
- Continental Competition

Historical participation is stored separately. Participation records contain
only Club ID, Competition ID, and Season ID.

---

# Relationships

References:

- Player IDs
- Staff IDs
- Nation ID
- Competition IDs
- Stadium ID (future)
- Owner ID (future)

Only IDs are stored.

---

# Validation Rules

Club ID cannot change.

Founded year cannot change.

Nation ID may only change through an explicit official relocation mechanic in
future phases. The referenced Nation must exist in the same world/save.

Financial values cannot become invalid through direct modification.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

Club Module

Full Access

Economy Module

Financial fields only

Competition Module

Competition references only

Transfer Module

Squad assignment references only

Staff Module

Manager and staff references only

Legacy Module

Historical records only

---

# Performance Notes

Frequently indexed fields include:

- Club ID
- Nation ID
- Current League
- Reputation

Historical competition records should remain separate from active club data.

---

# Future Expansion

Future versions may include:

- Stadium expansion
- Club ownership
- Board personalities
- Fan culture evolution
- Affiliate clubs
- Women's teams
- B teams
- Youth reserve structure

These additions should extend the existing structure without breaking compatibility.

---

# Locked Decisions

✓ Permanent Club ID

✓ One Record Per Club

✓ Historical Preservation

✓ Modular Ownership

✓ Data-Driven Identity

✓ Separate Historical Records

✓ Nation affiliation is a stable Nation ID reference

✓ Competition participation is a Season-bound ID relationship

✓ DOMAIN-008 squad roles are Club-scoped relationship state: `prospect`,
`rotation`, `regular`, or `key_player`. Roles do not duplicate Player ability
and a transferred Player receives an independent destination role.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |
| 1.1 | 2026-09-12 | Replaced Country ownership implication with Nation ID reference |

---

## DOMAIN-010 Squad Population

The initial population phase creates one season-bound senior squad of 25
Players per participating Big-5 Club. It reuses Player, Contract,
squad-membership, and Competition registration ownership; Clubs do not own
canonical Player records. Reserve, youth, and academy populations remain
deferred.

END OF DOCUMENT
