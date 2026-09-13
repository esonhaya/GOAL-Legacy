# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-009
Title: Match Engine

Version: 1.0
Status: Approved Blueprint

Dependencies

- Core Engine
- Event System
- World Engine
- Player Engine
- Staff Engine
- Competition Engine
- Database: Match

---

# Purpose

The Match Engine simulates football matches.

It coordinates every stage of a match, from kickoff to the final whistle, while delegating specialized calculations to internal systems.

The Match Engine owns match behaviour.

DOMAIN-006 implements only the deterministic foundation: scheduled Match
records, synthetic round-robin league fixtures, aggregate Club strength with
optional eligible Player OVR contribution, bounded score generation, minimal
Player stat lines, structured goal highlights, and standings rebuilt from
completed results. Interactive matches, tactics, substitutions, discipline,
injuries, weather, and minute-by-minute systems are deferred.

---

# Responsibilities

The Match Engine is responsible for:

- Match initialization
- Team setup
- Match progression
- Tactical execution
- Event generation
- Statistics collection
- Match completion
- Publishing match results

---

# Non-Responsibilities

The Match Engine does NOT:

- Train players
- Develop attributes
- Handle transfers
- Manage contracts
- Generate news
- Update club finances

---

# Public Interface

Primary operations include:

- InitializeMatch()
- SimulateMatch()
- PauseMatch()
- ResumeMatch()
- FinalizeMatch()
- GenerateStatistics()
- PublishResult()

Implementation details may evolve.

---

# Internal Components

## Match Setup

Responsible for:

- Competition rules
- Venue
- Officials
- Weather (future)
- Starting lineups

---

## Tactical Processor

Evaluates:

- Formation
- Instructions
- Mentality
- Pressing
- Tempo
- Width

Manager decisions influence tactical execution.

---

## Possession Engine

Determines:

- Possession changes
- Build-up play
- Territory
- Attacking momentum

---

## Decision Engine

Responsible for player decisions.

Examples:

- Pass
- Shoot
- Cross
- Tackle
- Dribble
- Clear
- Hold Position

---

## Event Generator

Produces gameplay events.

Examples:

- Goal
- Shot
- Corner
- Foul
- Yellow Card
- Red Card
- Injury
- Substitution

---

## Referee System

Responsible for:

- Fouls
- Discipline
- Added time
- Match control

Future versions may support referee personalities.

---

## Statistics Collector

Tracks:

- Possession
- Shots
- Expected Goals (future)
- Passes
- Tackles
- Saves
- Fouls
- Cards
- Distance Covered

---

## Match Finalizer

Responsible for:

- Final score
- Competition updates
- Player statistics
- Event publication

---

# Input Events

Examples

- MatchScheduled
- Kickoff
- TacticalChange
- SubstitutionRequested

---

# Output Events

Examples

- GoalScored
- MatchFinished
- PlayerInjured
- RedCardIssued
- CompetitionUpdated
- PlayerStatisticsUpdated

---

# Data Ownership

The Match Engine owns:

- Live match state
- Match events
- Match statistics
- Final result

Persistent storage belongs to the Match Database.

---

# Match Lifecycle

1. Match initialized.
2. Teams prepared.
3. Kickoff.
4. Simulation loop.
5. Event generation.
6. Halftime.
7. Second half.
8. Full time.
9. Final statistics.
10. Publish events.

---

# Failure Handling

If simulation fails:

- Preserve current match state.
- Log the error.
- Stop the affected match safely.
- Notify the Core Engine.

---

# Testing Strategy

The Match Engine should be tested for:

- Match completion
- Tactical execution
- Event generation
- Statistics accuracy
- Competition integration
- Long-term simulation stability

---

# Performance Goals

- Deterministic simulation
- Efficient event generation
- Modular calculations
- Stable long-term execution

---

# Future Expansion

Future versions may support:

- Dynamic weather
- Referee personalities
- VAR
- Crowd influence
- Ball physics improvements
- Commentary hooks
- 2D match viewer
- Full replay system

These additions should extend the Match Engine without changing its core responsibilities.

---

# Locked Decisions

✓ Match Engine orchestrates specialised systems.

✓ Tactical logic is modular.

✓ Statistics are generated during play.

✓ Referee decisions are independent.

✓ Events drive post-match processing.

✓ Match state is deterministic.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
