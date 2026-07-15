# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-012
Title: Relationship Engine

Version: 1.0
Status: Approved Blueprint

Dependencies

- Core Engine
- Event System
- World Engine
- Player Engine
- Staff Engine
- Club Engine

---

# Purpose

The Relationship Engine manages the social connections between every significant individual in GOAL: Legacy.

It records, updates, and evaluates relationships that develop through shared experiences, allowing long-term stories and emergent narratives to form naturally.

The Relationship Engine owns relationship behaviour.

---

# Responsibilities

The Relationship Engine is responsible for:

- Relationship creation
- Relationship progression
- Rivalries
- Friendships
- Mentorships
- Professional respect
- Social chemistry
- Historical interactions
- Relationship decay

---

# Non-Responsibilities

The Relationship Engine does NOT:

- Change player attributes
- Negotiate transfers
- Generate news
- Simulate matches
- Determine morale directly

Other engines may use relationship data when making decisions.

---

# Public Interface

Primary operations include:

- CreateRelationship()
- UpdateRelationship()
- RecordInteraction()
- EvaluateChemistry()
- EvaluateRivalry()
- ArchiveRelationship()

Implementation details may evolve.

---

# Internal Components

## Relationship Registry

Stores active relationships between:

- Player ↔ Player
- Player ↔ Staff
- Staff ↔ Staff
- Player ↔ Club
- Staff ↔ Club

Future versions may expand relationship types.

---

## Friendship System

Tracks:

- Friendship strength
- Shared history
- Positive interactions
- Long-term loyalty

---

## Rivalry System

Tracks:

- Competitive rivalry
- Personal conflict
- Historic encounters
- Derby influence

---

## Mentorship System

Supports:

- Veteran mentoring
- Youth guidance
- Staff mentoring

Mentorship effects are consumed by other engines.

---

## Relationship History

Maintains a timeline of important moments.

Examples:

- Academy teammates
- First professional match together
- Shared championships
- Transfers
- Public conflicts
- International tournaments

---

## Relationship Decay

Relationships evolve naturally over time.

Without interaction they may:

- Strengthen slowly through history
- Fade
- Become neutral
- Become nostalgic

---

# Input Events

Examples

- MatchFinished
- TrainingCompleted
- TransferCompleted
- ContractSigned
- TrophyWon
- InjuryOccurred
- Retirement

---

# Output Events

Examples

- FriendshipStrengthened
- RivalryCreated
- MentorshipStarted
- RelationshipChanged
- HistoricMilestoneRecorded

---

# Data Ownership

The Relationship Engine owns:

- Relationship values
- Relationship history
- Interaction history
- Mentorship records

Persistent storage belongs to the Relationship Database.

---

# Daily Update Flow

Daily tasks include:

- Record interactions
- Evaluate recent events
- Update relationship progression

---

# Weekly Update Flow

Weekly tasks include:

- Relationship decay
- Friendship updates
- Mentorship evaluation

---

# Seasonal Update Flow

Examples:

- Archive historic milestones
- Evaluate long-term relationships
- Generate legacy relationship summaries

---

# Failure Handling

If relationship processing fails:

- Preserve previous relationship data.
- Log the failure.
- Continue processing remaining relationships.
- Notify the Core Engine.

---

# Testing Strategy

The Relationship Engine should be tested for:

- Friendship creation
- Rivalry progression
- Mentorship tracking
- Long-term history
- Relationship decay
- Large database performance

---

# Performance Goals

- Efficient updates
- Stable long-term history
- Deterministic progression
- Minimal redundant calculations

---

# Future Expansion

Future versions may support:

- Family relationships
- Romantic relationships (optional)
- Agent relationships
- Media relationships
- Fan favourites
- Manager-player trust
- Club legends

These additions should extend the Relationship Engine without changing its core responsibilities.

---

# Locked Decisions

✓ Relationships are historical.

✓ Shared experiences matter.

✓ Relationships evolve naturally.

✓ Other engines consume relationship data.

✓ History is preserved.

✓ Relationship calculations remain centralized.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
