# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-016
Title: Legacy Engine

Version: 1.0
Status: Approved Blueprint

Dependencies

- Core Engine
- Event System
- World Engine
- Player Engine
- Club Engine
- Competition Engine
- News Engine
- Pulse Engine

---

# Purpose

The Legacy Engine preserves the long-term historical identity of the football world.

Rather than tracking every event, it identifies and archives the people, clubs, competitions, achievements, and moments that deserve to be remembered across generations.

The Legacy Engine owns historical significance.

---

# Responsibilities

The Legacy Engine is responsible for:

- Historical archiving
- Legacy scoring
- Hall of Fame
- Club legends
- Competition legends
- Historical milestones
- Record books
- Timeline preservation

---

# Non-Responsibilities

The Legacy Engine does NOT:

- Simulate football
- Generate news
- Detect trends
- Calculate finances
- Manage relationships

Other engines create history.

The Legacy Engine decides what history should be preserved.

---

# Public Interface

Primary operations include:

- EvaluateLegacy()
- ArchiveMilestone()
- UpdateRecordBook()
- PromoteLegend()
- CreateHallOfFameEntry()
- GenerateTimeline()

Implementation details may evolve.

---

# Internal Components

## Legacy Evaluator

Calculates long-term significance using information from multiple engines.

Factors may include:

- Career achievements
- Individual awards
- Club success
- International success
- Longevity
- Influence

---

## Record Book

Maintains records such as:

- Most goals
- Most assists
- Most appearances
- Most trophies
- Longest unbeaten run
- Highest transfer fee

Records remain data-driven.

---

## Hall of Fame

Stores football icons.

Possible categories:

- Players
- Managers
- Clubs

Admission requirements are determined by Legacy Score.

---

## Club Legends

Tracks individuals remembered by specific clubs.

Examples:

- Greatest captain
- Record goalscorer
- Legendary manager

A person may become a legend at multiple clubs.

---

## Competition History

Preserves:

- Champions
- Finals
- Historic upsets
- Dominant eras
- Famous rivalries

---

## Timeline Archive

Maintains an accessible chronological history of the simulated football world.

---

# Input Events

Examples

- PlayerRetired
- TrophyWon
- RecordBroken
- SeasonEnded
- HallOfFameCandidate
- ClubLegendCandidate

---

# Output Events

Examples

- RecordUpdated
- LegendCreated
- HallOfFameInducted
- TimelineUpdated
- LegacyMilestoneArchived

---

# Data Ownership

The Legacy Engine owns:

- Historical archives
- Legacy scores
- Hall of Fame records
- Record books
- Timelines

Persistent storage belongs to the Legacy Database.

---

# Daily Update Flow

Daily tasks include:

- Monitor milestone events
- Queue historical evaluations

---

# Weekly Update Flow

Weekly tasks include:

- Update record books
- Evaluate emerging legends

---

# Seasonal Update Flow

Examples:

- Archive completed seasons
- Hall of Fame review
- Legacy score recalculation
- Timeline generation

---

# Failure Handling

If legacy processing fails:

- Preserve existing archives.
- Log the failure.
- Retry pending evaluations.
- Notify the Core Engine.

---

# Testing Strategy

The Legacy Engine should be tested for:

- Record tracking
- Hall of Fame induction
- Legacy score calculations
- Historical archiving
- Long-term save stability

---

# Performance Goals

- Efficient archival processing
- Stable long-term storage
- Deterministic legacy evaluation
- Minimal duplicate history

---

# Future Expansion

Future versions may support:

- Club museums
- Player biographies
- Documentary timelines
- Anniversary celebrations
- Dynamic greatest-of-all-time debates
- Interactive football history browser

These additions should extend the Legacy Engine without changing its core responsibilities.

---

# Locked Decisions

✓ Legacy preserves significance.

✓ Other engines create history.

✓ Hall of Fame is data-driven.

✓ Record books are centralized.

✓ Historical archives are permanent.

✓ Legacy Score determines historical importance.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
