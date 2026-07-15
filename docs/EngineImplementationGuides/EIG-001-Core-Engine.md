# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-001
Title: Core Engine

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-15

---

# Purpose

The Core Engine is the foundation of GOAL: Legacy.

It is responsible for coordinating every game module without containing football-specific logic.

The Core Engine does not simulate football.

It manages the systems that allow football simulation to occur.

---

# Responsibilities

The Core Engine is responsible for:

- Bootstrapping the game.
- Registering modules.
- Managing the simulation loop.
- Dispatching events.
- Coordinating module communication.
- Managing update order.
- Loading saves.
- Saving world state.
- Gracefully shutting down the simulation.

---

# Non-Responsibilities

The Core Engine does NOT:

- Simulate matches.
- Calculate player growth.
- Generate news.
- Handle transfers.
- Manage finances.
- Store gameplay logic.

Those responsibilities belong to their respective modules.

---

# Design Principles

- Modular architecture.
- Loose coupling.
- Event-driven communication.
- Single responsibility.
- Deterministic simulation.
- Predictable update order.

---

# Startup Flow

When a save is loaded:

1. Initialize Core Engine.
2. Load configuration.
3. Register modules.
4. Initialize database connections.
5. Load world state.
6. Restore scheduled events.
7. Notify modules that the world is ready.
8. Begin simulation.

---

# Shutdown Flow

When exiting:

1. Pause simulation.
2. Flush pending events.
3. Save world state.
4. Save module state.
5. Close resources.
6. Shutdown cleanly.

---

# Simulation Loop

The Core Engine controls the simulation timeline.

Example:

Game Running
↓

Daily Tick

↓

Weekly Tick

↓

Monthly Tick

↓

Season Tick

↓

Repeat

Modules respond to these events.

The Core Engine does not process football logic itself.

---

# Module Registration

Every gameplay system registers with the Core Engine.

Examples:

- World Engine
- Player Engine
- Club Engine
- Match Engine
- Economy Engine
- News Engine
- Pulse Engine

The Core Engine maintains the module registry.

---

# Event System

Modules communicate through events.

Examples:

MatchFinished

↓

Player Engine updates fatigue

↓

Legacy Engine updates records

↓

News Engine creates article

↓

Pulse Engine creates discussion

The Core Engine dispatches events but does not interpret them.

---

# Dependency Rules

Modules must not directly control one another.

Instead:

Module A

↓

Event

↓

Core Engine

↓

Interested Modules

This minimizes coupling.

---

# Update Order

Example execution order:

1. World Engine
2. Competition Engine
3. Club Engine
4. Staff Engine
5. Player Engine
6. Match Engine
7. Economy Engine
8. Transfer Engine
9. Relationship Engine
10. News Engine
11. Pulse Engine
12. Legacy Engine

The order is configurable.

---

# Error Handling

The Core Engine:

- Detects module failures.
- Logs errors.
- Prevents cascading failures.
- Allows safe shutdown when necessary.

---

# Save Integration

The Core Engine coordinates:

- Save requests.
- Autosaves.
- Manual saves.
- Save validation.
- Version compatibility.

Actual serialization belongs to the Save Manager.

---

# Performance Goals

- Stable frame-independent simulation.
- Predictable execution.
- Efficient event dispatching.
- Minimal module coupling.
- Fast save/load operations.

---

# Future Expansion

Future versions may support:

- Parallel simulation.
- Background processing.
- Multiplayer synchronization.
- Plugin modules.
- Mod support.

These features should extend the Core Engine without changing its responsibilities.

---

# Locked Decisions

✓ Core Engine contains no football logic.

✓ All communication is event-driven.

✓ Modules remain loosely coupled.

✓ Core Engine owns the simulation loop.

✓ Core Engine owns module registration.

✓ Save operations are coordinated centrally.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
