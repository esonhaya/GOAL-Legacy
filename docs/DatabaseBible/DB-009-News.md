# GOAL: Legacy
# Database Bible

Document ID: DB-009
Title: News Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

The News table stores every article, report, interview, announcement, and major football story generated within GOAL: Legacy.

News serves as the official historical record of football events and provides context for the evolving world.

Unlike Pulse, News reports events rather than opinions.

---

# Ownership

Primary Module

News Module

Supporting Modules

- Match
- Transfer
- Club
- Player
- Staff
- Economy
- Legacy
- World

Only the News Module may create or modify news records.

---

# Design Principles

- One record per news story.
- Permanent News ID.
- Chronological history.
- Event-driven generation.
- Historical preservation.

---

# Lifecycle

News is generated automatically when significant events occur.

Examples include:

- Match Results
- Transfers
- Contract Renewals
- Manager Appointments
- Player Retirement
- Awards
- Injuries
- Competition Milestones
- Financial Events

Published news becomes part of football history.

---

# Primary Key

News ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

---

# News Identity

Fields include:

- News ID
- Publication Date
- Headline
- Summary
- Story Category

---

# Story Categories

Examples

- Match
- Transfer
- Club
- Player
- Staff
- Competition
- Injury
- Award
- Finance
- World Event

Future categories may be introduced without changing the architecture.

---

# Story Importance

Examples

- Minor
- Normal
- Major
- Breaking
- Historic

Importance influences visibility and Pulse activity.

---

# Related Entities

Stores references only.

Examples:

- Player IDs
- Club IDs
- Match ID
- Competition ID
- Staff IDs

Only IDs are stored.

---

# Visibility

Stores intended audience.

Examples

- Local
- National
- Continental
- Global

Visibility influences who receives the story.

---

# Generated Content

Stores references to generated text assets.

Examples

- Headline
- Short Summary
- Full Article

Different presentation formats may exist while referring to the same news record.

---

# Legacy References

Historic news contributes to:

- Career timelines
- Club history
- Competition history
- Hall of Fame

---

# Validation Rules

News ID cannot change.

Referenced entities must exist.

Publication dates cannot precede triggering events.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

News Module

Full Access

Match Module

Event references only

Transfer Module

Transfer references only

Legacy Module

Historical references only

---

# Performance Notes

Frequently indexed fields include:

- News ID
- Publication Date
- Story Category
- Story Importance

Historical articles remain permanently archived.

---

# Future Expansion

Future versions may include:

- Multiple media outlets
- Journalist personalities
- Editorial bias
- Exclusive interviews
- Press conferences
- Rumour reliability
- Regional newspapers

These additions extend the architecture without breaking compatibility.

---

# Locked Decisions

✓ Permanent News ID

✓ Event-Driven Generation

✓ Historical Preservation

✓ Modular Ownership

✓ Official Source of Record

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT
