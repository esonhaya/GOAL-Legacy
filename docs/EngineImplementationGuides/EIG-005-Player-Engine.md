# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-005
Title: Player Engine

Version: 1.0
Status: Approved Blueprint

Dependencies

- Core Engine
- Event System
- World Engine
- Database: Player

---

# Purpose

The Player Engine manages the complete lifecycle of every football player.

It is responsible for player development, aging, morale, fitness, recovery, injuries, reputation, retirement, and every gameplay system that directly affects an individual player.

The Player Engine owns player behaviour.

---

# Responsibilities

The Player Engine is responsible for:

- Player creation
- Player aging
- Attribute growth
- Potential development
- Morale updates
- Fitness management
- Fatigue
- Injury recovery
- Suspensions
- Retirement
- Reputation updates
- Position familiarity
- Personality progression

---

# Non-Responsibilities

The Player Engine does NOT:

- Simulate matches
- Handle transfers
- Negotiate contracts
- Manage club finances
- Generate news
- Calculate league standings

---

# Public Interface

Primary operations include:

- CreatePlayer()
- UpdatePlayer()
- TrainPlayer()
- RecoverPlayer()
- ApplyInjury()
- RetirePlayer()
- CalculateGrowth()
- CalculateMorale()
- CalculateFitness()

Implementation may evolve during development.

---

# Internal Components

## Growth System

Calculates attribute progression.

Supports:

- Late Bloomers
- Regular Developers
- Prodigies

Growth curves remain configurable.

---

## Potential System

Stores long-term development ceilings.

Potential influences future growth rather than immediate ability.

---

## Morale System

Tracks:

- Happiness
- Playing Time
- Team Success
- Contract Satisfaction
- Relationships

---

## Fitness System

Tracks:

- Energy
- Match Fitness
- Fatigue
- Recovery

---

## Injury System

Handles:

- Injury occurrence
- Recovery progression
- Return-to-play status

---

## Personality System

Maintains long-term personality traits.

Examples:

- Professionalism
- Leadership
- Ambition
- Loyalty
- Temperament

Traits evolve gradually through gameplay.

---

# Input Events

Examples

- MatchFinished
- TrainingCompleted
- DayEnded
- WeekEnded
- ContractSigned
- TransferCompleted
- InjuryOccurred

---

# Output Events

Examples

- PlayerImproved
- PlayerDeclined
- PlayerInjured
- PlayerRecovered
- PlayerRetired
- MoraleChanged
- ReputationChanged

---

# Data Ownership

The Player Engine owns:

- Attribute calculations
- Growth progression
- Morale state
- Fitness state
- Injury state
- Retirement state

Persistent storage belongs to the Player Database.

---

# Daily Update Flow

Daily tasks include:

- Recovery
- Injury healing
- Morale adjustments
- Fatigue updates

---

# Weekly Update Flow

Weekly tasks include:

- Growth calculations
- Training adaptation
- Position familiarity
- Personality progression

---

# Seasonal Update Flow

Examples:

- Aging
- Potential review
- Seasonal statistics
- Reputation adjustments
- Retirement evaluation

---

# Failure Handling

If player calculations fail:

- Preserve previous valid state.
- Log the error.
- Continue processing remaining players.
- Notify the Core Engine.

---

# Testing Strategy

The Player Engine should be tested for:

- Growth consistency
- Aging progression
- Injury recovery
- Retirement logic
- Morale calculations
- Fitness calculations
- Large player databases

---

# Performance Goals

- Efficient batch updates
- Deterministic growth
- Stable long-term careers
- Minimal redundant calculations

---

# Future Expansion

Future versions may support:

- Dynamic personalities
- Hidden traits
- Mental health
- Lifestyle effects
- Media influence
- Family events
- Mentorship systems

These additions should extend the Player Engine without changing its core responsibilities.

---

# Locked Decisions

✓ Player Engine owns player behaviour.

✓ Growth is data-driven.

✓ Personality evolves gradually.

✓ Morale affects gameplay.

✓ Fitness and injuries remain independent systems.

✓ Retirement is managed centrally.

✓ DOMAIN-007 Player development applies deterministic training and Match
experience through one authoritative service.

✓ Player age is derived from birth date and SimulationDate; development state
and immutable progression history are Career data.

✓ Season and career statistics are derived from Match Player stat lines rather
than duplicated mutable counters.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
