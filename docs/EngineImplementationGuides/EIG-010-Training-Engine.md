# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-010
Title: Training Engine

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

The Training Engine manages every organized training activity within GOAL: Legacy.

It schedules training, determines workload, assigns coaches, evaluates participation, and produces development modifiers that are consumed by the Player Engine.

The Training Engine owns training behaviour.

---

# Responsibilities

The Training Engine is responsible for:

- Training schedules
- Session generation
- Training intensity
- Coach assignment
- Attendance
- Recovery planning
- Training reports
- Development modifiers
- Individual training plans

---

# Non-Responsibilities

The Training Engine does NOT:

- Increase player attributes directly
- Calculate player potential
- Simulate matches
- Handle injuries
- Manage contracts

Player development remains the responsibility of the Player Engine.

---

# Public Interface

Primary operations include:

- GenerateTrainingSchedule()
- StartTrainingSession()
- CompleteTrainingSession()
- AssignCoach()
- CalculateTrainingLoad()
- GenerateTrainingModifiers()
- UpdateTrainingPlans()

Implementation details may evolve.

---

# Internal Components

## Schedule Manager

Responsible for:

- Weekly schedules
- Rest days
- Match preparation
- Recovery sessions

---

## Session Manager

Maintains:

- Technical sessions
- Tactical sessions
- Physical sessions
- Recovery sessions
- Goalkeeper sessions

Future versions may introduce custom training programs.

---

## Coach Assignment

Assigns appropriate staff members to sessions.

Coach quality influences training effectiveness.

---

## Workload Manager

Tracks:

- Session intensity
- Fatigue
- Recovery requirements
- Overtraining risk

---

## Individual Training

Supports:

- Position training
- Weak foot development
- Trait training
- Physical focus
- Technical focus

Growth calculations remain outside this engine.

---

## Training Report Generator

Produces summaries for:

- Players
- Managers
- AI clubs

Reports may influence future decisions.

---

# Input Events

Examples

- WeekStarted
- MatchFinished
- CoachAssigned
- PlayerRecovered
- TrainingRequested

---

# Output Events

Examples

- TrainingCompleted
- TrainingModifiersGenerated
- OvertrainingDetected
- RecoveryScheduled
- TrainingReportGenerated

---

# Data Ownership

The Training Engine owns:

- Training schedules
- Session data
- Training modifiers
- Attendance
- Workload

Persistent player progression belongs to the Player Database.

---

# Daily Update Flow

Daily tasks include:

1. Check training calendar.
2. Run scheduled sessions.
3. Calculate workload.
4. Publish training modifiers.

---

# Weekly Update Flow

Weekly tasks include:

- Create next week's schedule
- Evaluate player workload
- Update training plans
- Generate staff reports

---

# Seasonal Update Flow

Examples:

- Pre-season planning
- Training philosophy review
- Staff effectiveness review
- Annual workload statistics

---

# Failure Handling

If a session fails:

- Preserve previous schedule.
- Log the failure.
- Skip only the affected session.
- Notify the Core Engine.

---

# Testing Strategy

The Training Engine should be tested for:

- Schedule generation
- Session completion
- Workload calculations
- Coach assignments
- Modifier generation
- Long-term workload stability

---

# Performance Goals

- Efficient batch processing
- Stable scheduling
- Deterministic modifier generation
- Minimal redundant calculations

---

# Future Expansion

Future versions may support:

- Team chemistry sessions
- Mentoring groups
- Specialized rehabilitation
- Academy training pathways
- Dynamic training philosophies
- Custom weekly schedules
- AI-designed training plans

These additions should extend the Training Engine without changing its core responsibilities.

---

# Locked Decisions

✓ Training Engine owns training.

✓ Player Engine owns development.

✓ Coaches influence training quality.

✓ Workload is calculated centrally.

✓ Training produces modifiers, not attributes.

✓ Schedules are data-driven.

✓ DOMAIN-007 Phase 1 exposes explicit deterministic training blocks with a
small focus vocabulary. The Player development service consumes the resulting
stimulus and remains the only component that mutates Player attributes.

✓ Coach, workload, fatigue, and recurring daily schedules remain deferred;
Phase 1 does not create per-Player scheduler callbacks.

✓ DOMAIN-009 permits an explicit training block to add bounded Player fatigue
through the Player availability service. An unavailable Player receives no
normal development stimulus or training load, and a stable block source key
prevents retry duplication. Recovery remains lazy and SimulationDate-based;
there is no rehabilitation or medical-staff subsystem.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

## P2-017 Controlled Readiness Integration

The current Phase-2 implementation intentionally remains a bounded
between-Match block, not a daily schedule or training minigame. Existing
Career priority derives a `TrainingIntensity`: Recovery/Lifestyle are light,
Professional/Balanced are normal, and Development is intense. The Training
Service passes that policy to both the existing Player Development Service and
Player availability workload boundary in one idempotent source transaction.

Light training trades development stimulus for recovery pressure; intense
training supplies only a bounded extra development opportunity and modest
deterministic Injury exposure when workload is already high. An unavailable
Player receives no normal development stimulus or training load. Readiness is
read-only projection from fatigue and active Injury state, and ordinary NPCs
do not receive individual training plans or detailed fitness history.

## P2-019 Positional Focus

Positional development is a medium-term controlled-Career focus layered onto
the existing training block, not a second training or XP engine. A target
must be adjacent to the current primary and pass the bounded attribute-fit
rule. Light, normal, and intense canonical blocks advance familiarity at
different bounded rates after `PlayerDevelopmentService` accepts the block;
double-submitted blocks do not advance it twice. Completion establishes a
secondary capability, and a separate explicit action changes the primary
position. Position focus does not restore readiness, create routine injury,
or guarantee selection.

END OF DOCUMENT
