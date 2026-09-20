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

DOMAIN-006 implements the deterministic foundation: scheduled Match records,
synthetic round-robin league fixtures, aggregate Club strength with optional
eligible Player OVR contribution, bounded score generation, minimal Player
stat lines, structured goal highlights, and standings rebuilt from completed
results. DOMAIN-011 adds a minimal broad-position Matchday XI, positional-cover
bench, and deterministic substitution records/minutes within the Competition
maximum of five (the current policy selects up to three) without
introducing formations, tactics, or a minute-by-minute engine. Interactive
matches, tactical instructions, discipline, in-match injury chains, weather,
and live controls remain deferred.

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

## P2-014 controlled Match story

`MatchStoryService` is a read-only presentation layer for controlled Matches.
It derives participation labels, position, minutes, score-aware timeline,
player moments, decisive contribution, Player of the Match, and a bounded
Career-impact summary from the persisted Match, selection, substitution,
highlight, stat, rating, development, and social rows. It does not simulate,
write, or consume random numbers. `PlayerMatchRatingService::explain()` uses
the same rating evidence and policy as the numeric rating, with position-aware
descriptions and no unsupported actions.

Commentary is deterministic template text (`match-commentary:v1`) generated
only from canonical highlights. Goal score-after context is derived while
reading the ordered highlights; shootout facts remain competition-resolution
facts and do not become regulation goals. The integrity check validates score,
goal/stat/assist, selection, substitution, and minutes relationships for the
inspected Match.

Selective fidelity remains a hard boundary: controlled Player Matches may use
the existing detailed stat/highlight rows, while ordinary World Matches gain
no timeline, commentary, rating explanation, or new detail rows. Matchday and
the existing stable `matchday?match=` route are read-only after simulation and
there is no re-simulation on browsing or reload.

---

# Locked Decisions

✓ Match Engine orchestrates specialised systems.

✓ Tactical logic is modular.

✓ Statistics are generated during play.

✓ Referee decisions are independent.

✓ Events drive post-match processing.

✓ Match state is deterministic.

✓ DOMAIN-008 reuses Match eligibility and persists deterministic selection
decisions before generating minimal actual participant stat lines. Career
evaluation is a downstream idempotent consumer of completed Match evidence;
it does not alter Match ownership.

✓ DOMAIN-009 consumes the Player availability assessment during selection.
Completed Match Player minutes remain the workload source; bounded fatigue and
deterministic Injury exposure are applied once per Match/Player source key by
the Player availability service. The Match Engine does not own Injury state or
run a minute-by-minute medical simulation.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

## P2-021 Foot-Sensitive Controlled Actions

The shared Match engine remains the owner of outcomes and Player statistics.
Only controlled-Player fidelity uses `PlayerFootService` to choose a stable
action foot for meaningful goal, assist, and shot-selection context. The
bounded weak-foot modifier is deliberately small and Shooting/Passing remain
the primary evidence. Ordinary World Matches do not create foot action rows;
the optional foot metadata is carried in existing controlled highlight data.
Ratings do not receive a direct preferred-foot bonus, and the action namespace
is isolated from Match outcome RNG.

END OF DOCUMENT
