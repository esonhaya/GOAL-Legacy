# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-007
Title: Staff Engine

Version: 1.0
Status: Approved Blueprint

Dependencies

- Core Engine
- Event System
- World Engine
- Club Engine
- Database: Staff

---

# Purpose

The Staff Engine manages every non-player football professional within GOAL: Legacy.

It is responsible for creating, developing, assigning, and retiring staff members while influencing club performance through their expertise and decision-making.

The Staff Engine owns staff behaviour.

---

# Responsibilities

The Staff Engine is responsible for:

- Staff creation
- Career progression
- Staff assignments
- Manager behaviour
- Coach development
- Scout management
- Medical staff effectiveness
- Contract status (staff perspective)
- Retirement
- Reputation

---

# Non-Responsibilities

The Staff Engine does NOT:

- Simulate matches
- Train players directly
- Negotiate contracts
- Execute transfers
- Manage club finances
- Generate news

---

# Public Interface

Primary operations include:

- CreateStaff()
- AssignClub()
- PromoteStaff()
- RetireStaff()
- UpdateReputation()
- EvaluatePerformance()
- SearchCandidates()

Implementation details may evolve.

---

# Internal Components

## Career System

Tracks:

- Experience
- Promotions
- Career milestones
- Reputation
- Retirement

---

## Manager System

Responsible for:

- Tactical preferences
- Squad selection recommendations
- Rotation philosophy
- Youth willingness
- Media personality

Actual match tactics remain the responsibility of the Match Engine.

---

## Coach System

Influences:

- Training quality
- Player development efficiency
- Recovery support

---

## Scout System

Influences:

- Player discovery
- Scouting accuracy
- Report quality
- Recruitment knowledge

---

## Medical System

Influences:

- Injury diagnosis
- Recovery speed
- Injury prevention

Medical outcomes are applied through the Player Engine.

---

# Input Events

Examples

- SeasonStarted
- MatchFinished
- ContractSigned
- StaffHired
- StaffReleased
- TrainingCompleted

---

# Output Events

Examples

- StaffRetired
- ReputationChanged
- ManagerChanged
- CoachingLevelChanged
- ScoutReportGenerated

---

# Data Ownership

The Staff Engine owns:

- Career progression
- Reputation
- Staff roles
- Staff specializations

Persistent storage belongs to the Staff Database.

---

# Daily Update Flow

Daily tasks include:

- Assignment validation
- Recovery monitoring
- Staff availability

---

# Weekly Update Flow

Weekly tasks include:

- Performance evaluation
- Reputation adjustments
- Experience gain

---

# Seasonal Update Flow

Examples:

- Contract review
- Retirement evaluation
- Career progression
- Staff awards

---

# Failure Handling

If staff processing fails:

- Preserve previous valid state.
- Log the error.
- Continue processing remaining staff.
- Notify the Core Engine.

---

# Testing Strategy

The Staff Engine should be tested for:

- Career progression
- Manager assignment
- Coach effectiveness
- Scout behaviour
- Retirement logic
- Reputation updates

---

# Performance Goals

- Efficient updates
- Stable long-term careers
- Predictable AI behaviour
- Minimal redundant calculations

---

# Future Expansion

Future versions may support:

- Assistant manager responsibilities
- Specialized goalkeeper coaches
- Sports psychologists
- Data analysts
- Director of football roles
- Staff mentoring
- Coaching licenses

These additions should extend the Staff Engine without changing its core responsibilities.

---

# Locked Decisions

✓ Staff are independent entities.

✓ Managers are not clubs.

✓ Coaches influence development.

✓ Scouts influence recruitment.

✓ Medical staff influence recovery.

✓ Career progression is data-driven.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
