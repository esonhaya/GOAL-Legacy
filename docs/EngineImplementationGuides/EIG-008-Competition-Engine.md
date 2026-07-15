# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-008
Title: Competition Engine

Version: 1.0
Status: Approved Blueprint

Dependencies

- Core Engine
- Event System
- World Engine
- Club Engine
- Database: Competition

---

# Purpose

The Competition Engine manages every football competition within GOAL: Legacy.

It governs league and cup structures, scheduling, standings, progression, qualification, promotion, relegation, and season completion.

The Competition Engine owns competition behaviour.

---

# Responsibilities

The Competition Engine is responsible for:

- Competition creation
- Season initialization
- Fixture generation
- League standings
- Cup progression
- Qualification
- Promotion
- Relegation
- Competition completion
- Historical competition statistics

---

# Non-Responsibilities

The Competition Engine does NOT:

- Simulate matches
- Update player attributes
- Handle transfers
- Generate finances
- Generate news

It organizes competitions, but the Match Engine determines match results.

---

# Public Interface

Primary operations include:

- CreateCompetition()
- GenerateFixtures()
- StartSeason()
- EndSeason()
- UpdateStandings()
- AdvanceRound()
- DeterminePromotion()
- DetermineRelegation()
- AwardChampionship()

Implementation details may evolve.

---

# Internal Components

## Competition Registry

Maintains:

- Active competitions
- Competition types
- Participating clubs
- Historical records

---

## Fixture Generator

Responsible for:

- League schedules
- Cup draws
- Knockout brackets
- Match calendar allocation

Scheduling must avoid conflicts where possible.

---

## Standings Manager

Calculates:

- Points
- Goal Difference
- Goals Scored
- Wins
- Draws
- Losses

Tie-breakers follow competition rules.

---

## Qualification Manager

Determines qualification for:

- Continental competitions
- Domestic cups
- Playoffs

Rules are competition-specific.

---

## Promotion & Relegation Manager

Responsible for:

- Promotion
- Relegation
- Promotion playoffs
- League restructuring (future)

---

## Awards Manager

Coordinates:

- Champions
- Golden Boot references
- Best Player references
- Fair Play references

Actual award calculations may involve other engines.

---

# Input Events

Examples

- SeasonStarted
- MatchFinished
- MatchPostponed
- CompetitionCreated
- WorldTick
- SeasonEnded

---

# Output Events

Examples

- FixtureGenerated
- RoundStarted
- RoundCompleted
- CompetitionFinished
- ChampionDeclared
- ClubPromoted
- ClubRelegated
- QualificationConfirmed

---

# Data Ownership

The Competition Engine owns:

- Fixtures
- Standings
- Tournament progression
- Competition phases
- Qualification outcomes

Persistent storage belongs to the Competition Database.

---

# Daily Update Flow

Daily tasks include:

- Check scheduled fixtures
- Validate competition calendar
- Monitor postponed matches

---

# Weekly Update Flow

Weekly tasks include:

- Update standings
- Advance completed rounds
- Verify qualification status

---

# Seasonal Update Flow

Examples:

- Generate new fixtures
- Reset standings
- Assign promoted clubs
- Assign relegated clubs
- Archive completed season
- Notify Legacy Engine

---

# Failure Handling

If competition processing fails:

- Preserve previous standings.
- Log the failure.
- Prevent invalid competition progression.
- Notify the Core Engine.

---

# Testing Strategy

The Competition Engine should be tested for:

- Fixture generation
- League standings
- Tie-breakers
- Knockout progression
- Promotion and relegation
- Qualification rules
- Long-term seasonal stability

---

# Performance Goals

- Efficient fixture scheduling
- Accurate standings
- Stable competition progression
- Deterministic season simulation

---

# Future Expansion

Future versions may support:

- Dynamic competition formats
- Rule changes
- Multi-stage tournaments
- Regional qualification systems
- Club licensing
- International competitions
- Historical competition formats

These additions should extend the Competition Engine without changing its core responsibilities.

---

# Locked Decisions

✓ Competition Engine owns competition structure.

✓ Match Engine determines match outcomes.

✓ Competition rules are data-driven.

✓ Promotion and relegation belong here.

✓ Qualification belongs here.

✓ Historical records are preserved.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
