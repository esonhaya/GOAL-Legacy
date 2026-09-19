# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-002
Title: Event System

Version: 1.0
Status: Approved Blueprint

Dependencies:
- EIG-001 Core Engine

---

# Purpose

The Event System enables communication between independent modules without creating direct dependencies.

It acts as the central message bus of GOAL: Legacy.

Modules never call one another directly when reporting gameplay events. Instead, they publish events that interested modules may subscribe to.

This architecture keeps the engine modular, scalable, and maintainable.

---

# Responsibilities

The Event System is responsible for:

- Publishing events
- Dispatching events
- Managing event subscriptions
- Processing event queues
- Preventing unnecessary module coupling
- Logging event activity (development mode)

---

# Non-Responsibilities

The Event System does NOT:

- Execute gameplay logic
- Modify database records
- Decide event outcomes
- Interpret football rules

Its only responsibility is reliable event delivery.

---

# Public Interface

Primary operations include:

- RegisterListener()
- RemoveListener()
- PublishEvent()
- QueueEvent()
- DispatchEvents()
- ClearQueue()

Exact implementation may evolve during development.

---

# Internal Components

## Event Bus

Maintains registered listeners.

---

## Event Queue

Stores pending events awaiting dispatch.

Supports immediate and deferred processing.

---

## Event Dispatcher

Routes events to subscribed modules.

Ensures deterministic processing order.

---

## Event Logger

Available in development builds.

Records:

- Event Name
- Timestamp
- Publisher
- Receivers
- Processing Duration

---

# Event Lifecycle

Typical flow:

1. Module creates an event.
2. Event enters the Event Queue.
3. Dispatcher processes the queue.
4. Registered listeners receive the event.
5. Each listener performs its own logic.
6. Event is marked complete.

---

# Input Events

The Event System accepts events from any registered module.

Examples:

- MatchFinished
- ContractSigned
- PlayerRetired
- TransferCompleted
- SeasonEnded
- AwardGranted

---

# Output Events

The Event System does not create gameplay events.

Its only output is successful delivery to subscribed modules.

---

# Data Ownership

The Event System owns:

- Event Queue
- Listener Registry
- Dispatch State

Gameplay data remains owned by individual modules.

---

# Update Flow

Example:

Match Engine

↓

Publish MatchFinished

↓

Event Queue

↓

Dispatcher

↓

Player Engine

↓

Legacy Engine

↓

News Engine

↓

Pulse Engine

↓

Queue Cleared

---

# Event Priority

The historical Pulse Engine labels in this event-flow blueprint mean the old
internal narrative/orchestration step. In the current architecture, canonical
domain owners emit facts, ECHO selects public significance, News reports facts,
and PULSE presents bounded controlled-career reaction. The player-facing
PULSE service does not determine execution order.

Events may be classified as:

- Critical
- High
- Normal
- Low

Higher-priority events are processed first.

Priority exists to support future scalability and should not alter gameplay outcomes.

---

# Failure Handling

If a listener fails:

- Log the failure.
- Prevent the failure from stopping unrelated listeners.
- Continue dispatching remaining listeners.
- Report the failure to the Core Engine.

One faulty module must never halt the entire simulation.

---

# Testing Strategy

The Event System should be tested for:

- Correct listener registration
- Proper dispatch order
- Queue integrity
- Duplicate event prevention
- Error isolation
- High-volume event processing

---

# Future Expansion

Potential future features:

- Asynchronous event processing
- Delayed events
- Scheduled events
- Network event synchronization
- Plugin-defined events
- Event replay tools

These additions should extend the Event System without changing its core responsibilities.

---

# Locked Decisions

✓ Event-driven architecture

✓ Loose module coupling

✓ Central Event Queue

✓ Listener Registry

✓ Deterministic dispatch order

✓ Error isolation

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
