# GOAL: Legacy
# Technical Bible

Document ID: TB-003
Title: Database Architecture

Version: 1.1
Status: Draft
Author: Jaime Haya
Last Updated: 2026-09-12

---

# Purpose

This document defines the database architecture of GOAL: Legacy.

Its objective is to ensure that every gameplay system stores, retrieves, and updates information consistently.

The database is designed to support long-term saves spanning multiple generations while remaining lightweight enough for mobile devices.

---

# Design Philosophy

The database exists to represent a living football world.

Data should never exist without purpose.

Whenever possible:

- Store facts.
- Calculate values when needed.
- Avoid duplicated information.
- Preserve historical records instead of deleting them.

---

# Database Engine

Phase 1 uses SQLite.

Reasons:

- Offline by default.
- No server required.
- Excellent PHP support.
- Reliable save file portability.
- Fast enough for thousands of simulated players.

Future versions may support additional database engines through adapters.

---

# Architecture

The database is divided into logical domains.

Examples:

Player

Club

Competition

Career

World

Economy

Relationships

Media

Legacy

Developer

Each domain owns its own tables.

---

# Core Principles

## Single Source of Truth

Every piece of information has exactly one authoritative location.

Example:

Player Age

Stored once.

Never duplicated elsewhere.

---

## Historical Preservation

Records are never overwritten if historical value exists.

Instead:

Current Contract

↓

Contract History

↓

Career History

↓

Legacy Records

---

## Stable IDs

Every generated object receives a permanent unique ID.

Examples:

Player ID

Club ID

Match ID

Season ID

News ID

Universe ID

IDs never change.

## Persistence Ownership

Core provides database connections, transaction boundaries, serialization,
and save lifecycle primitives. Each domain repository owns persistence of its
own records and uses those Core contracts; Core does not define a generic
football repository framework.

Content packages provide declarative baseline data. A save database stores
mutable career/world state and any source package/version provenance required
for reconstruction. Runtime services, listeners, callbacks, and caches are
not canonical database records. TB-007 defines the lifecycle categories.

---

# Data Categories

## Permanent Data

Never changes after generation.

Examples:

Birth Date

Birth Nation

Generated DNA

Universe Seed

---

## Dynamic Data

Changes throughout gameplay.

Examples:

Fitness

Morale

Contract

Transfer Value

Club Reputation

Manager Reputation

---

## Historical Data

Preserved forever.

Examples:

League Winners

Award Winners

Transfers

Career Statistics

Retired Players

Season Records

---

# Database Domains

## Player Domain

Stores:

- Identity
- Attributes
- Personality
- Relationships
- Career
- Injuries
- Statistics

---

## Club Domain

Stores:

- Identity
- Finances
- Reputation
- Facilities
- Staff
- Squad

---

## Competition Domain

Stores:

- Fixtures
- Results
- Standings
- Awards

---

## World Domain

Stores:

- Nation IDs and global geography indexes
- Calendar
- Universe Score

The World domain owns global/root state and references. Nation identity and
Nation-specific state are owned by the Nation domain defined in DB-014; they
are not duplicated in World. Club, Player, and Competition domains likewise
reference Nation IDs rather than copying Nation records.

---

## Media Domain

Stores:

- News
- Pulse
- Headlines
- Social Feed

---

## Legacy Domain

Stores:

- Hall of Fame
- Career Records
- Bloodlines
- Historical Achievements

---

# Relationships

Tables should communicate through IDs.

Never duplicate complete objects.

Example:

Player

↓

Club ID

↓

Club Table

Not

Player

↓

Entire Club Information

---

# Save Files

One save equals one SQLite database.

Everything required for the career exists inside the save.

No online dependency.

Installed content is not a substitute for save state. Selected package data is
validated and materialized through its owning domain before mutable state is
persisted in the save database.

---

# Performance Rules

Large queries should be avoided during weekly simulation.

Frequently accessed values should be indexed.

Historical archives should be separated from active gameplay when appropriate.

Simulation should prioritize speed over unnecessary precision.

---

# Future Database Bible

This document defines architecture only.

Individual tables will be documented separately inside:

DatabaseBible/

Examples:

DB-001 Player Table

DB-002 Club Table

DB-014 Nation Table

DB-003 Match Table

DB-004 Contract Table

DB-005 News Table

Each table receives:

Purpose

Columns

Relationships

Indexes

Validation Rules

Developer Notes

---

# Locked Decisions

✓ SQLite for Phase 1

✓ One Save = One Database

✓ Stable Permanent IDs

✓ Historical Preservation

✓ Single Source of Truth

✓ Domain-Based Architecture

✓ Player development state and immutable progression history are Player-owned
Career records. Match Player stat lines remain Match-owned; season and career
statistics are rebuildable projections over those durable records.

✓ Player fatigue/load state and structured Injury history are Player-owned
Career records. Availability is a derived projection over Injury dates,
fatigue recovery, and existing administrative eligibility; it does not use a
second clock or a global daily update.

✓ Data-Driven Design

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |
| 1.1 | 2026-09-12 | Added Nation domain ownership and clarified World references |

---

## DOMAIN-010 Generated Save State

Generated Player populations are save-state rows created by a versioned
deterministic service. Static content supplies Nations, Clubs, and
Competitions; generated Players, Contracts, squad relationships, and
registrations remain normalized SQLite state with stable IDs and indexes.

END OF DOCUMENT
