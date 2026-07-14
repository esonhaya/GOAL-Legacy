# GOAL: Legacy
# Database Bible

Document ID: DB-002
Title: Club Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

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
- League
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
- Country
- Region
- City
- Year Founded
- Home Stadium
- Club Colors
- Club Badge ID

Identity rarely changes.

---

# Football Profile

Stores football identity.

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

---

# Competition Data

Stores references to competitions.

Examples:

- Current League
- Domestic Cup
- Continental Competition

Historical participation is stored separately.

---

# Relationships

References:

- Player IDs
- Staff IDs
- Competition IDs
- Stadium ID (future)
- Owner ID (future)

Only IDs are stored.

---

# Validation Rules

Club ID cannot change.

Founded year cannot change.

Country may only change through official relocation mechanics (future phases).

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

League Module

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
- Country
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

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT
