# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-004
Title: World Engine

Version: 1.0
Status: Approved Blueprint

Dependencies:

- EIG-001 Core Engine
- EIG-002 Event System
- EIG-003 Save Manager

---

# Purpose

The World Engine controls the progression of time and the global simulation state.

It acts as the heartbeat of GOAL: Legacy, coordinating every engine through the passage of time rather than directly executing football logic.

Every other gameplay engine reacts to the World Engine.

The World Engine reacts to nothing except player input and the Core Engine.

---

# Responsibilities

The World Engine is responsible for:

- Advancing simulation time
- Managing the calendar
- Controlling season progression
- Triggering scheduled world events
- Opening and closing transfer windows
- Beginning and ending competitions
- Coordinating daily, weekly, monthly and seasonal ticks

---

# Non-Responsibilities

The World Engine does NOT:

- Simulate football matches
- Generate player growth
- Handle finances
- Negotiate transfers
- Generate news
- Update relationships

It only advances the world and publishes time events.

---

# Public Interface

Primary operations include:

- AdvanceDay()
- AdvanceWeek()
- AdvanceMonth()
- AdvanceSeason()
- PauseSimulation()
- ResumeSimulation()
- GetCurrentDate()
- GetCurrentSeason()

Implementation details may evolve.

---

# Internal Components

## Calendar

Maintains:

- Day
- Week
- Month
- Year
- Season

---

## Time Controller

Advances simulation safely.

Guarantees deterministic progression.

---

## Scheduler

Stores future world events.

Examples:

- Fixtures
- Youth Intake
- Award Ceremonies
- Contract Expirations
- Transfer Window Opening
- Transfer Window Closing

---

## Tick Manager

Generates simulation ticks.

Supported ticks:

- Daily
- Weekly
- Monthly
- Seasonal

---

# Input Events

Examples:

- New Game Created
- Save Loaded
- Resume Simulation
- Pause Simulation

---

# Output Events

Examples:

- DayStarted
- DayEnded
- WeekEnded
- MonthEnded
- SeasonStarted
- SeasonEnded
- TransferWindowOpened
- TransferWindowClosed

Other engines subscribe to these events.

---

# Data Ownership

The World Engine owns:

- Current Date
- Current Season
- Calendar State
- Scheduled World Events
- Simulation Speed
- Pause State

---

# Daily Update Flow

Typical sequence:

1. Advance calendar.
2. Publish DayStarted.
3. Process scheduled world events.
4. Publish DayEnded.

Gameplay engines respond independently.

---

# Weekly Update Flow

Weekly events may include:

- Training
- Recovery
- Youth Development
- Staff Meetings
- Financial Reports

The World Engine publishes the event only.

---

# Monthly Update Flow

Examples:

- Budget updates
- Awards
- Sponsorship payments
- Financial summaries

---

# Seasonal Update Flow

Examples:

- Promotion
- Relegation
- Contract expiration
- Competition reset
- Legacy updates
- World archive snapshot

The current implementation evaluates and completes the active Season when
SimulationDate crosses its end boundary. It does not yet provide the full
follow-on orchestration implied by this conceptual flow: creating the next
Season record, activating it, materializing Competition/Club memberships,
registering eligible Players, and generating the next fixture set. Those
operations remain a future World lifecycle milestone and must not be faked by
long-horizon audit tooling.

---

# Failure Handling

If a scheduled event fails:

- Log the error.
- Continue unrelated processing.
- Preserve simulation integrity.
- Notify the Core Engine.

---

# Testing Strategy

The World Engine should be tested for:

- Calendar accuracy
- Leap year handling (if supported)
- Tick generation
- Season transitions
- Event scheduling
- Pause and resume behaviour
- Save/load restoration

---

# Performance Goals

- Predictable simulation
- Deterministic time progression
- Efficient scheduling
- Minimal overhead
- Stable long-term saves

---

# Future Expansion

Future versions may support:

- Historical start dates
- Dynamic competition calendars
- Configurable season lengths
- Regional calendars
- Weather systems
- Global football events
- International tournaments

These additions should extend the engine without changing its responsibilities.

---

# Locked Decisions

✓ World Engine owns time.

✓ Time drives the simulation.

✓ Other engines react to world events.

✓ World Engine contains no football logic.

✓ Tick-based architecture.

✓ Deterministic progression.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

## DOMAIN-013 Season Rollover

The World service delegates the recurring football-season boundary to one
`SeasonRolloverService`. Once the active Season's scheduled Matches are
complete, the service creates one deterministic upcoming successor, applies
Club-scoped Contract renewal/release, and preserves historical rows. At the
successor start it materializes the next seasonal Competition projection,
continues Club membership, replenishes only missing senior-squad places,
registers Players with active Contracts, and generates fixtures through the
Competition and Match services. This coordination is bounded by the World
competition scope and is retry-safe through existing repository identities.

The service does not own Match simulation, finance, promotion/relegation, or
autonomous recruitment. At the same boundary it invokes the Player lifecycle
owner for once-per-Season age/decline and retirement, then invokes the existing
population owner for deterministic newgen vacancies. Retired historical
Players are preserved; newgens are normal Player entities rather than a
parallel type.

END OF DOCUMENT
