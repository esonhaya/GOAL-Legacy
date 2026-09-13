# GOAL: Legacy
# Database Bible

Document ID: DB-004
Title: Competition Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

The Competition table defines every organized football competition in GOAL: Legacy.

A competition represents the rules, structure, scheduling, and historical identity of leagues, domestic cups, continental tournaments, and future international competitions.

The Competition table acts as the central authority for competition configuration.

---

# Ownership

Primary Module

Competition Module

Supporting Modules

- Match
- Club
- World
- Legacy
- News
- Pulse

Only the Competition Module may directly modify competition-specific
information unless explicitly authorized. Season-bound Club participation is
stored as a normalized relationship and does not duplicate Club records.

---

# Design Principles

- One record per competition.
- Permanent Competition ID.
- Data-driven rules.
- Historical continuity.
- Modular ownership.

---

# Lifecycle

Competition records are generated during world creation.

Official competitions are never deleted.

Inactive or discontinued competitions remain archived for historical purposes.

Future competitions may be introduced through gameplay events or future phases.

---

# Primary Key

Competition ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

All systems reference competitions using Competition ID.

---

# Identity

Stores permanent information.

Fields include:

- Competition ID
- Official Name
- Short Name
- Competition Type
- Nation ID reference
- Confederation
- Tier Level
- Founded Year

Identity values rarely change.

---

# Competition Types

Supported competition categories include:

- Domestic League
- Domestic Cup
- Continental Competition
- Super Cup
- Youth Competition
- Friendly Tournament

Future phases may introduce additional competition types.

---

# Structure

Defines how the competition operates.

Examples:

- Number of Teams
- Number of Matchdays
- Home and Away Format
- Group Stage
- Knockout Stage
- Final Format

These values are data-driven rather than hardcoded.

---

# Rules

Competition rules include:

- Points Per Win
- Points Per Draw
- Tie-breakers
- Away Goal Rules (if applicable)
- Extra Time
- Penalty Shootouts
- Squad Registration Limits

Rules are configurable.

The Phase-1 modern league default permits up to five substitutions per team.
The Match simulator may use a smaller deterministic number in an individual
Match; this is a simulation policy, not a lower Competition rule maximum.

---

# Qualification

Defines progression.

Examples:

- Promotion
- Relegation
- Continental Qualification
- Cup Qualification

Qualification references other Competition IDs rather than names.

---

# Scheduling

Stores scheduling information.

Examples:

- Season Start
- Season End
- Matchday Frequency
- International Breaks
- Winter Break
- Registration Windows

DOMAIN-006 adds deterministic domestic-league Match scheduling through the
Match Module. Match records remain Match-owned. Completed Match results are
the canonical input for the Competition standings projection, which is
rebuildable and is not duplicated mutable Club state.

The World Module coordinates the overall calendar and stores only stable
Competition IDs as global references. It does not own Competition records.

---

# Reputation

Stores the prestige of the competition.

Examples:

- Domestic Prestige
- Continental Prestige
- Global Prestige
- Prize Reputation

Reputation influences player decisions, media attention, and club ambitions.

---

# Financial Information

Stores competition-wide financial data.

Examples:

- Prize Money
- Participation Fees
- Broadcasting Revenue
- Solidarity Payments

Managed jointly by the League and Economy Modules.

---

# Historical Data

Stores references to historical records.

Examples:

- Champions
- Runners-up
- Top Scorers
- Record Points
- Biggest Wins

Detailed history is stored in separate historical tables.

---

# Relationships

References:

- Club IDs
- Match IDs
- Season IDs
- Confederation IDs (future)

Only IDs are stored.

---

# Validation Rules

Competition ID cannot change.

Competition Type cannot change after creation.

Structural changes occur only between seasons.

Qualification paths must reference valid competitions.

Competition registration is a normalized seasonal relationship containing
Season ID, Competition ID, Club ID, and Player ID. The Competition Module
owns registration persistence and validates Competition participation,
Player squad membership, and required Contract state. It does not duplicate
Player or Club records; Transfer execution only coordinates registration
changes.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

Competition Module

Full Access

World Module

Calendar and season references only

Economy Module

Financial fields only

Legacy Module

Historical records only

---

# Performance Notes

Frequently indexed fields include:

- Competition ID
- Country
- Tier Level
- Competition Type

Historical records should remain separate from active competition data.

---

# Future Expansion

Future versions may include:

- Dynamic rule changes
- League restructuring
- New continental competitions
- Financial Fair Play systems
- Custom competitions
- Fictional football worlds

These additions should extend the existing architecture without breaking compatibility.

---

# Locked Decisions

✓ Permanent Competition ID

✓ One Record Per Competition

✓ Data-Driven Rules

✓ Historical Preservation

✓ Modular Ownership

✓ Configurable Competition Structures

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

## DOMAIN-010 Registration Boundary

Generated senior-squad Players are registered through the existing
Season-bound Competition registration relationship. Population does not add
a second registration table or alter Competition ownership of Club and
Player records.

END OF DOCUMENT
