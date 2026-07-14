# GOAL: Legacy
# Technical Bible

Version: Phase 1 Prototype
Status: Draft
Document ID: TB-01

---

# Chapter 1 — Technical Architecture

---

## 1. Purpose

This document defines the technical architecture of GOAL: Legacy.

Unlike the Game Design Bible, which explains what the game should do, the Technical Bible defines how the software is organized, how systems communicate, and how new features are integrated without breaking existing functionality.

This document serves as the primary engineering reference for development.

---

## 2. Engineering Philosophy

GOAL: Legacy is designed as a modular simulation engine rather than a monolithic football game.

Every major feature exists as an independent module with clearly defined responsibilities.

Modules communicate through shared interfaces instead of directly modifying one another whenever possible.

This architecture allows new gameplay systems to be added during later phases without requiring large-scale rewrites.

---

## 3. Core Engineering Principles

### Principle 1 — Modular First

Every gameplay feature belongs to exactly one module.

Example:

- Match Engine
- Training Module
- Transfer Module
- Pulse Module

A feature must never exist in multiple places.

---

### Principle 2 — Single Responsibility

Every PHP file should perform one clear job.

Good:

MatchEngine.php

Bad:

MatchEngineAndTransferSystem.php

---

### Principle 3 — Data Driven

Game content should exist as data whenever possible.

Examples:

- Countries
- Clubs
- Awards
- Events
- News Templates
- Social Posts
- Achievements

should all be stored as data rather than hardcoded into PHP.

---

### Principle 4 — Deterministic Simulation

The simulation should produce believable results using the same rules for every club.

The player does not receive hidden gameplay advantages.

AI clubs follow identical football rules.

---

### Principle 5 — Progressive Complexity

Systems should be expandable.

Example:

Phase 1:

Agent

↓

Offers contracts.

Phase 2:

Negotiates contracts.

↓

Phase 3:

Influences media.

↓

Phase 4:

Creates sponsorship opportunities.

No existing code should require rewriting.

---

### Principle 6 — Mobile First

The game targets browser play on mobile devices.

Every system must consider:

- RAM usage
- Save size
- CPU usage
- Loading time

The game should remain responsive on mid-range Android devices running Termux.

---

## 4. Project Architecture

The game consists of independent systems.

Player

↓

Career

↓

Football World

↓

Simulation

↓

Content

↓

Developer Tools

Each system communicates through shared interfaces coordinated by the Pulse Engine.

The Pulse Engine is responsible for determining execution order.

---

## 5. Simulation Philosophy

The world exists independently from the player.

Every week:

- Clubs continue operating.
- Managers make decisions.
- Players develop.
- Injuries occur.
- Transfers happen.
- News is generated.

Whether or not the user interacts with these events.

The player experiences one life inside a living football universe.

---

## 6. Phase-Based Development

Development is divided into independent phases.

Each phase must produce a fully playable game.

Future phases extend existing systems.

They never replace completed systems.

This guarantees long-term save compatibility.

---

## 7. Coding Standards

Development follows these rules.

• One class per PHP file.

• One responsibility per module.

• Use descriptive variable names.

• Avoid duplicated logic.

• Never hardcode gameplay values.

• Prefer configuration files for balancing.

• Preserve backward compatibility whenever possible.

---

## 8. Performance Philosophy

GOAL: Legacy prioritizes simulation quality over graphical complexity.

Performance improvements should first target:

• Simulation scheduling

• Memory usage

• Database queries

• Content generation

Visual presentation should never reduce simulation accuracy.

---

## 9. Future Compatibility

Every system must expose extension points for future phases.

Examples include:

- International football
- Historical eras
- Family systems
- Bloodlines
- Expanded social simulation

Adding future content should require extending modules rather than rewriting them.

---

## 10. Locked Decisions

The following decisions are permanent.

✓ Modular architecture

✓ Mobile-first development

✓ Browser-based gameplay

✓ Independent simulation engines

✓ Phase-based expansion

✓ Data-driven content

✓ Deterministic football simulation

✓ Backward save compatibility

These principles define all future technical decisions.

---
END OF TB-01
