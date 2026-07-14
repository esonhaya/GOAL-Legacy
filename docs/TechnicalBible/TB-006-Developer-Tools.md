# GOAL: Legacy
# Technical Bible

Document ID: TB-006
Title: Developer Tools

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

This document defines the internal tools used to develop, debug, test, and balance GOAL: Legacy.

Developer tools are not gameplay features.

They exist solely to improve development speed, testing, debugging, and long-term maintainability.

---

# Design Philosophy

Developer tools should:

• Save development time

• Reduce repetitive testing

• Expose simulation information

• Never affect release builds unless explicitly enabled

---

# Developer Mode

Developer Mode unlocks debugging features unavailable during normal gameplay.

Examples:

- Simulation controls
- Hidden information
- Debug overlays
- World inspection
- Testing commands

Developer Mode is disabled by default.

---

# Developer Console

The Developer Console provides direct access to internal commands.

Examples:

generate_player

generate_club

simulate_week

simulate_month

simulate_season

retire_player

injure_player

heal_player

change_reputation

change_budget

reset_world

These commands exist only for testing.

---

# Simulation Controls

Developers may accelerate time.

Supported speeds:

Pause

1x

5x

10x

50x

100x

Simulation acceleration should not alter gameplay results.

Only execution speed changes.

---

# World Inspector

Displays internal simulation data.

Examples:

- Hidden Traits
- Personality Values
- AI Goals
- Club Strategies
- Financial Information
- Universe Statistics

Useful for debugging AI behaviour.

---

# Database Inspector

Allows developers to inspect database records.

Functions:

- Search tables
- View relationships
- Detect invalid references
- Monitor database growth

Read-only by default.

---

# Event Viewer

Displays recently executed events.

Examples:

Transfer Completed

Contract Signed

Manager Sacked

Youth Generated

Player Retired

News Published

Useful for diagnosing simulation issues.

---

# Performance Monitor

Displays:

Simulation Time

Memory Usage

Database Queries

Weekly Processing Time

Generated Objects

Helps identify bottlenecks.

---

# AI Inspector

Displays AI decision making.

Examples:

Why a club rejected a transfer.

Why a player requested a transfer.

Why a manager selected a formation.

Why a board dismissed a coach.

This tool explains AI decisions for debugging.

---

# Economy Inspector

Displays:

Club Finances

Player Wealth

Transfer Inflation

Salary Distribution

Economic Trends

Used for balancing long careers.

---

# Legacy Inspector

Displays:

Historical Records

Hall of Fame

Career Rankings

Universe Score

Bloodline Data (future phases)

Useful for verifying historical persistence.

---

# Content Generator

Developer-only utilities may automatically generate:

Players

Clubs

Staff

News

Competitions

Entire Test Worlds

This greatly reduces manual testing time.

---

# Error Logging

Every critical system should log:

Timestamp

Module

Error Type

Description

Suggested Cause

Logs should assist debugging without exposing sensitive implementation details.

---

# Release Builds

Release builds exclude:

Developer Console

Cheat Commands

Debug Overlays

Database Inspector

Performance Metrics

Internal Testing Utilities

These remain available only in developer builds.

---

# Future Expansion

Future tools may include:

Visual Database Editor

Simulation Replay Viewer

Match Replay Debugger

AI Heatmaps

Balancing Dashboard

Scenario Builder

Automated Regression Testing

These are outside the scope of Phase 1.

---

# Locked Decisions

✓ Developer Mode

✓ Internal Console

✓ Simulation Speed Controls

✓ AI Inspection Tools

✓ Database Inspection

✓ Event Viewer

✓ Performance Monitoring

✓ Release Build Separation

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT
