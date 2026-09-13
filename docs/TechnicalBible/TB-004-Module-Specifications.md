# GOAL: Legacy
# Technical Bible

Document ID: TB-004
Title: Module Specifications

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

This document defines every gameplay module in GOAL: Legacy.

A module is an independent gameplay system responsible for a specific area of the simulation.

Every gameplay feature belongs to exactly one module.

Modules communicate through defined interfaces and events instead of directly modifying each other's internal data.

---

# Design Philosophy

Modules should be:

- Independent
- Predictable
- Replaceable
- Expandable
- Testable

A module should never become responsible for unrelated gameplay systems.

---

# Module Communication

Modules exchange information through:

• Public APIs
• Events
• Shared IDs
• Database Queries

Modules must NOT directly edit another module's internal state.

---

# Core Modules

## Player Module

Purpose

Represents every football player in the universe.

Owns

- Identity
- Attributes
- Nationality and eligibility references
- Physical football profile and primary position
- Potential and development profile
- Career-controlled Player references
- Personality
- Morale
- Confidence
- Fitness
- Reputation
- Career Statistics
- Hidden Traits

Receives

- Match Results
- Training Results
- Injury Events
- Relationship Events

Provides

- Player Data
- Development
- Career Status

DOMAIN-004 implements Player creation, persistent identity/attributes, and
career-player references. It does not implement contracts, transfers,
training, injuries, matches, or staff. The Club Module owns the normalized
season-bound squad relationship and validates Club/Player/Season references.

DOMAIN-009 extends the Player Module with bounded fatigue state, structured
Injury history, lazy SimulationDate recovery, and availability assessment. The
Match Module remains the owner of participation/minutes and supplies workload;
selection consumes availability without creating a second eligibility system.

Never Owns

- League Tables
- Club Finances
- Match Scheduling

---

## Club Module

Purpose

Represents every football club.

Owns

- Club Identity
- Reputation
- Facilities
- Budget
- Youth Academy
- Staff
- Squad Registration
- Season-bound Player squad membership
- Season-bound Competition participation references

DOMAIN-003 implements the Club identity/content/persistence foundation and
normalized Competition participation. Player, staff, finance, facilities,
and squad systems remain future domain slices unless separately implemented.

Receives

- Transfers
- Financial Updates
- Competition Results

Provides

- Club Information
- Squad Lists
- Financial Status

Never Owns

- Match Simulation
- Player Development

---

## Match Module

Purpose

Simulates football matches.

Owns

- Match Flow
- Events
- Statistics
- Ratings
- Injuries During Matches

DOMAIN-006 Phase 1 narrows this to scheduled-to-completed Match lifecycle,
deterministic league fixture generation, bounded simulation, minimal Player
stat lines, structured highlights, and Match persistence. DOMAIN-009 adds
idempotent post-completion workload/injury exposure through the Player
availability service; it does not add medical staff or a minute-by-minute
injury simulator. Interactive flow, tactics, and ratings beyond
participation remain deferred.

Receives

- Team Selection
- Tactical Instructions
- Player Conditions

Provides

- Final Result
- Statistics
- Player Ratings
- Match Events

Never Owns

- Contracts
- Transfers
- Long-term Player Growth

---

## Competition Module

Purpose

Manages competition definitions and persistent competition state. Fixture,
standings, promotion, relegation, and detailed rules engines remain future
phases built on this foundation.

Owns

- Competition identity and definition
- Competition lifecycle state
- Competition content validation and materialization
- Stable Nation and Season references

Provides

- Validated competition definitions
- Stable competition lookup
- Persistent competition state

Never Owns

- Nation records
- Club records or membership
- Match fixtures or results
- Standings, promotion, relegation, or transfers

The Competition Module also owns the normalized Player registration
relationship for a Competition, Club, and Season. It does not own Player,
Club, Contract, or Transfer records.

## Contract Module

Purpose

Owns Player employment Contract identity, terms, date lifecycle, and
persistence.

Never Owns

- Player or Club records
- Squad membership
- Competition registration
- Transfer transactions

## Transfer Module

Purpose

Coordinates and persists Player movement transactions. It validates and
executes coherent transitions across Contract, squad membership, and
Competition registration services.

Never Owns

- Player or Club records
- Contract canonical rows
- Squad membership canonical rows
- Competition registration canonical rows
- Club financial balances in DOMAIN-005

---

## Transfer Module

Purpose

Controls player movement.

Owns

- Offers
- Negotiations
- Loans
- Transfer Windows

Provides

- Completed Transfers
- Contract Changes

Never Owns

- Match Results
- Training

---

## Training Module

Purpose

Handles player development.

Owns

- Training Sessions
- Experience Gain
- Attribute Growth
- Fatigue
- Development Plans

Provides

- Growth Results

Never Owns

- Match Simulation

---

## Staff Module

Purpose

Represents non-player personnel.

Includes

- Coaches
- Managers
- Scouts
- Medical Staff

Owns

- Staff Quality
- Contracts
- Staff Reputation

Provides

- Coaching Bonuses
- Scouting Reports
- Tactical Knowledge

---

## World Module

Purpose

Controls the football universe.

Owns

- Calendar
- Seasons
- Global simulation state
- Nation references and global indexes
- Competition references and global indexes
- Rule Changes
- Universe Score

Provides

- World State

Never Owns

- Individual Careers
- Nation identity or Nation-specific state
- Competition domain records

---

## Nation Module

Purpose

Represents the persistent Nation domain described by DB-014.

Owns

- Nation identity
- Nation-specific state
- Nation content validation and loading
- Nation persistence through its repository

Provides

- Validated Nation records
- Stable Nation ID lookup
- Nation references for other modules

Never Owns

- World state or global indexes
- Clubs, players, matches, competitions, contracts, or transfers
- Executable content-package behavior

---

## News Module

Purpose

Generates football news.

Owns

- Headlines
- Articles
- Rumours
- Interviews

Provides

- World Information

Never Owns

- Simulation Logic

---

## Pulse Module

Purpose

Represents public opinion.

Owns

- Fan Reactions
- Memes
- Trolls
- Trends
- Community Discussions

Phase 1 uses a single social platform inspired by football discussion forums and microblogging services.

Additional social platforms are intentionally excluded.

Provides

- Public Sentiment

---

## Relationship Module

Purpose

Tracks relationships.

Owns

- Family
- Partners
- Friends
- Rivals
- Club Relationships
- Manager Relationships

Provides

- Relationship Events

---

## Economy Module

Purpose

Controls finances.

Owns

- Salaries
- Sponsorships
- Investments
- Lifestyle Expenses
- Wealth

Provides

- Financial State

---

## Legacy Module

Purpose

Preserves history.

Owns

- Hall of Fame
- Career Records
- Bloodlines
- Historical Comparisons
- Save Statistics

Provides

- Legacy Information

---

## Achievement Module

Purpose

Tracks player accomplishments.

Owns

- Achievements
- Unlockables
- Career Milestones

Provides

- Progress Tracking

---

## Developer Module

Purpose

Developer-only tools.

Owns

- Debug Commands
- Simulation Controls
- Profiling
- Testing Utilities

Excluded from release builds unless enabled.

---

# Phase 1 Modules

The following modules are mandatory for the first playable version.

✓ Player

✓ Club

✓ Match

✓ League

✓ Training

✓ Transfer

✓ Staff

✓ World

✓ Nation

✓ News

✓ Pulse

✓ Relationship

✓ Economy

✓ Legacy

✓ Achievement

✓ Developer

---

# Future Modules

Potential expansion modules include:

- International Football
- Dynamic Rule Changes
- Stadium Management
- Women's Football
- Multi-Career Mode
- Online Community Features

These are outside the scope of Phase 1.

---

# Locked Decisions

✓ Modular Architecture

✓ One Owner Per Gameplay System

✓ Event-Based Communication

✓ Single Responsibility Principle

✓ Data-Driven Expansion

✓ Phase-Based Feature Rollout

✓ The Player Module owns development state and progression history. Training
stimulus is explicit and deterministic, while Match completion supplies
idempotent experience stimulus through the existing Match path.

✓ The Club Module owns Club-scoped squad roles. The Match Module owns
selection decisions and Match participation evidence. Player career services
derive evaluation, expectations, and structured opportunities without owning
Club or Match records.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT
