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

It records achievements, records, milestones, awards, historical rankings, and career accomplishments that define the football world's collective memory. The first implemented Player-facing slice is descriptive: it does not create an achievement currency or synthetic Legacy score.

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

# P2-012 Player Career Legacy

P2-012 persists four compact fact collections in the Career save:

- `career_awards`: one immutable source key per completed Season, League scope,
  award type, and winner.
- `career_honours`: one immutable source key per Player participation in a
  winning League, domestic Cup, European competition, or World Championship.
- `career_legacy_records`: one current row per controlled Player personal-best
  metric, with the Season and evidence value that set it.
- `career_legacy_milestones`: one immutable source key per Player, metric, and
  threshold.

The controlled Player must have at least one canonical competition appearance
for a Club honour. International honours require at least one national-team
cap in the winning World Championship. A mid-Season transfer therefore keeps
an honour only when the Player's recorded participation is for the winning
Club/team; Club membership alone is insufficient.

The initial annual awards are Player of the Season, Young Player of the
Season, Top Scorer, and Top Assist Provider for domestic Leagues. They use
compact World-fidelity competition aggregates or retained controlled-Player
Match evidence. Player of the Season is rating-led with minutes, appearances,
and contribution tie-breaks, so position is not reduced to goals. Young Player
eligibility is age 21 or younger at Season end. All ties are deterministic and
resolve by documented secondary evidence, then stable Player ID.

Awards resolve before Season compaction. Compaction retains the compact
competition aggregates, while old saves without sufficient evidence receive no
fabricated historical awards. NPCs may win from available aggregates, but no
NPC Match-detail or legacy simulation is generated.

Career Legacy is reconstructed read-only from these rows plus canonical
Player, Club, Match, and international statistics. It exposes Career span,
Clubs, totals, honours, awards, personal bests, and milestones; no Legacy
Points or default Legacy score is persisted.

# P2-025 Career Memory

Career Memory deepens this existing Legacy boundary without adding another
ledger. \`CareerLegacyService\` classifies the durable milestone, honour, award,
and record rows together with retained Match/Event history into a bounded
Career Timeline, Career Landmarks, Personal Bests, and Defining Seasons.
Exact firsts are shown only when completed Match or international Event
evidence preserves their chronology. Compact Season aggregates can support a
timeless fact, but never justify an invented date, Club, opponent, or role.

Numeric landmark thresholds are intentionally sparse: Club appearances 50,
100, 200, 300, 500; Club goals 25, 50, 100, 200, 300; Club assists 25, 50,
100, 200; international caps 10, 25, 50, 100; and international goals 1,
10, 25, 50. Existing source keys remain the idempotency boundary. Reading a
Profile, Home, Legacy, or Matchday page does not insert milestone or timeline
rows. NPCs do not receive milestone rows, ticks, or world-wide timeline builds.

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
