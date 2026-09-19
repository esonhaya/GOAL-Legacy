# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-014
Title: Pulse Engine

Version: 1.0
Status: Approved Blueprint

## P2-015 naming boundary

This historical blueprint uses “Pulse Engine” for an internal trend/momentum
concept. The current player-facing brand is written `PULSE`, and its football
integration is implemented by `PulseService`. `EchoService` selects noteworthy
canonical facts before Pulse presentation. The old internal term is retained
here to avoid a broad architectural rename; new user-facing or integration
documentation must use PULSE for the platform and ECHO for routing.

P2-015 deliberately does not implement the world-wide trend managers,
individual AI participants, popularity history, or global archive described as
future blueprint concepts below. Goal: Legacy persists only controlled-player
Pulse sources/posts, one audience state, and curated response state. NPCs may
appear as contextual actor labels but do not receive Pulse accounts or social
careers.

Dependencies

- Core Engine
- Event System
- World Engine
- Player Engine
- Club Engine
- Competition Engine
- News Engine
- Relationship Engine

---

# Purpose

The Pulse Engine monitors long-term trends across the football world.

Rather than recording individual events, it identifies momentum, popularity, reputation shifts, emerging stories, and world narratives that develop over time.

The Pulse Engine owns football trends.

---

# Responsibilities

The Pulse Engine is responsible for:

- Trend detection
- Momentum tracking
- Public attention
- Club momentum
- League popularity
- Nation reputation
- Emerging narratives
- Trend decay

---

# Non-Responsibilities

The Pulse Engine does NOT:

- Generate news articles
- Simulate matches
- Calculate finances
- Create relationships
- Change player attributes

It observes the simulation and produces world-level insights.

---

# Public Interface

Primary operations include:

- DetectTrend()
- UpdateMomentum()
- CalculatePopularity()
- ArchiveTrend()
- ExpireTrend()
- GeneratePulseSnapshot()

Implementation details may evolve.

---

# Internal Components

## Trend Detector

Identifies significant emerging patterns.

Examples:

- Winning streaks
- Losing streaks
- Rising stars
- Falling giants

---

## Popularity Manager

Tracks popularity for:

- Players
- Clubs
- Competitions
- Nations

Popularity changes gradually.

---

## Momentum Manager

Maintains momentum values for:

- Clubs
- Managers
- Players

Momentum is temporary and naturally fades.

---

## Narrative Tracker

Recognizes stories such as:

- Underdog runs
- Golden generations
- Dynasty clubs
- Club rebuilds
- Historic rivalries

Narratives are built from many smaller events.

---

## Pulse Archive

Stores important world trends for historical review.

---

# Input Events

Examples

- MatchFinished
- TrophyWon
- TransferCompleted
- ArticlePublished
- PlayerDebut
- Retirement

---

# Output Events

Examples

- TrendStarted
- TrendEnded
- MomentumChanged
- NarrativeCreated
- PulseSnapshotGenerated

---

# Data Ownership

The Pulse Engine owns:

- Active trends
- Momentum values
- Narrative records
- Popularity history

Persistent storage belongs to the Pulse Database.

---

# Daily Update Flow

Daily tasks include:

- Scan recent events
- Update momentum
- Detect emerging trends

---

# Weekly Update Flow

Weekly tasks include:

- Refresh popularity
- Evaluate active narratives
- Remove expired trends

---

# Seasonal Update Flow

Examples:

- League popularity review
- Nation reputation review
- Historic narrative archive
- World Pulse summary

---

# Failure Handling

If Pulse processing fails:

- Preserve previous trend data.
- Log the error.
- Continue simulation.
- Notify the Core Engine.

---

# Testing Strategy

The Pulse Engine should be tested for:

- Trend detection
- Momentum progression
- Narrative creation
- Trend expiration
- Archive stability

---

# Performance Goals

- Efficient event scanning
- Stable long-term trends
- Deterministic calculations
- Minimal duplicate processing

---

# Future Expansion

Future versions may support:

- Fan culture
- Internet trends
- Club popularity by region
- Broadcast influence
- Sponsorship popularity
- Football fashion trends

These additions should extend the Pulse Engine without changing its core responsibilities.

---

# Locked Decisions

✓ Pulse tracks trends, not events.

✓ Momentum naturally decays.

✓ Narratives emerge from multiple events.

✓ Other engines consume Pulse data.

✓ Historical trends are preserved.

✓ Pulse remains observational.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
