# GOAL: Legacy
# Database Bible

Document ID: DB-003
Title: Staff Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

The Staff table stores every non-player football professional in GOAL: Legacy.

Staff members shape clubs, influence player development, determine tactical identity, and contribute to the evolution of the football world.

Unlike players, staff members primarily influence the simulation indirectly through decisions and bonuses.

---

# Ownership

Primary Module

Staff Module

Supporting Modules

- Club
- Match
- Training
- Transfer
- World
- Legacy

Only the Staff Module may directly modify staff-specific information unless explicitly authorized.

---

# Design Principles

- One record per staff member.
- Permanent Staff ID.
- Independent career progression.
- AI-driven decision making.
- Historical preservation.

---

# Lifecycle

A staff record is created when:

- A new universe is generated.
- A retired player becomes staff.
- A new staff member is procedurally generated.

Staff members are never deleted.

Retired staff remain part of football history.

---

# Primary Key

Staff ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

---

# Identity

Stores permanent information.

Fields include:

- Staff ID
- First Name
- Last Name
- Nationality
- Birth Date
- Birth Nation

---

# Staff Role

Each staff member has one primary role.

Examples:

- Head Coach
- Assistant Coach
- Goalkeeping Coach
- Fitness Coach
- Medical Staff
- Scout
- Sporting Director
- Manager

Future versions may support multiple simultaneous roles.

---

# Coaching Profile

Stores football knowledge.

Examples:

- Tactical Knowledge
- Formation Familiarity
- Player Development
- Motivation
- Discipline
- Adaptability

These values influence player and club performance.

---

# Hidden Personality

Stores hidden behavioural traits.

Examples:

- Ambition
- Loyalty
- Patience
- Risk Taking
- Professionalism
- Leadership

These values affect AI decisions.

---

# Reputation

Tracks career reputation.

Examples:

- Domestic Reputation
- International Reputation
- Potential Reputation

Reputation changes throughout a career.

---

# Current Employment

Stores references only.

Examples:

- Club ID
- Contract ID

Historical employment is stored separately.

---

# Relationships

References:

- Club ID
- Player IDs
- Staff IDs

Relationships influence morale, development, and decision making.

---

# Validation Rules

Staff ID cannot change.

Role changes occur only through approved gameplay events.

Contracts are managed by the Staff Module.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

Staff Module

Full Access

Club Module

Employment references only

Legacy Module

Historical records only

---

# Performance Notes

Frequently indexed fields include:

- Staff ID
- Current Club
- Role
- Reputation

Historical careers remain separated from active employment.

---

# Future Expansion

Future versions may include:

- Coaching Licences
- Tactical Philosophies
- Media Reputation
- Staff Mentorship
- International Careers
- Dynamic Staff Specializations

---

# Locked Decisions

✓ Permanent Staff ID

✓ One Record Per Staff Member

✓ AI-Driven Careers

✓ Historical Preservation

✓ Modular Ownership

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT
