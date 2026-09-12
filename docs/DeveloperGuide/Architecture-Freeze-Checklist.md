# GOAL: Legacy
# Architecture Freeze Checklist

Document ID: AFC-001

Version: 1.0

Status: Active

---

# Purpose

This document tracks every architectural decision locked during the Architecture Freeze.

Its purpose is to:

- Prevent duplicate discussions
- Keep documentation synchronized
- Record affected files
- Ensure implementation follows approved architecture

No implementation should contradict a locked freeze decision without first updating this document.

---

# Status Legend

- ☐ Not Started
- 🔄 In Progress
- ✅ Locked
- 📝 Integrated
- ✔ Complete

---

# AF-001 — Club Identity Model

Status: ✅ Locked

Decision:

- Three identity layers
    - Core Philosophy
    - Football Identity
    - Current Style

Affected Documents:

- DB-002
- CB-004
- TB-003
- TB-004
- EIG-017

---

# AF-002 — Nation Architecture

Status: ✔ Complete

Decision:

Create DB-014 Nation.

Move nation-specific responsibilities from World.

Affected Documents:

- DB-013
- DB-014 (new)
- TB-003
- EIG-004
- CB-002
- CB-003

---

# AF-003 — Single Source of Truth

Status: ✅ Locked

Decision:

Every persistent data item has exactly one owner.

Affected Documents:

- TB-003
- TB-004
- All DB files

---

# AF-004 — Phase Scope Lock

Status: ✅ Locked

Decision:

Phase 1 only includes foundational systems.

Future ideas move to roadmap.

Affected Documents:

- Roadmap
- README

---

# AF-005 — Dynamic Simulation Levels

Status: ✅ Locked

Decision:

Simulation detail depends on relevance.

Affected Documents:

- EIG-004
- TB-004

---

# AF-006 — Update Frequency

Status: ✅ Locked

Decision:

Systems update only when necessary.

Affected Documents:

- EIGs
- TB-004

---

# AF-007 — Sleeping Engines

Status: ✅ Locked

Decision:

Idle systems consume minimal resources.

Affected Documents:

- TB-004

---

# AF-008 — Event Priority System

Status: ✅ Locked

Decision:

Events have priority levels.

Engines subscribe rather than poll.

Affected Documents:

- TB-004
- API Specs

---

# AF-009 — Core Engine Neutrality

Status: ✅ Locked

Decision:

Core never contains football logic.

Affected Documents:

- TB-001
- TB-002

---

# AF-010 — Data Lifetime

Status: ✅ Locked

Decision:

Every object has a defined lifecycle.

Affected Documents:

- TB-007 (new)

---

# AF-011 — Ownership vs Responsibility

Status: ✅ Locked

Decision:

One engine decides.

Other engines react.

Affected Documents:

- API Specs
- TB-004

---

# AF-012 — Engine Definition

Status: ✅ Locked

Decision:

Engines own simulation domains.

Modules contain engines.

Affected Documents:

- TB-004
- README

---

# AF-013 — Module Independence

Status: ✅ Locked

Decision:

Modules should be installable, removable, and testable.

Affected Documents:

- TB-002
- TB-004

---

# AF-014 — Version Compatibility

Status: ✅ Locked

Decision:

Support save migration whenever practical.

Affected Documents:

- TB-005

---

# AF-015 — Incremental Development

Status: ✅ Locked

Decision:

Develop one module at a time.

Avoid unnecessary regeneration.

Affected Documents:

- Developer Guide

---

# AF-016 — Developer Experience

Status: ✅ Locked

Decision:

Folders and files have clear responsibilities.

Affected Documents:

- TB-002

---

# AF-017 — Testing Philosophy

Status: ✅ Locked

Decision:

Every module is independently testable.

Affected Documents:

- Testing Guide (future)

---

# AF-018 — Logging Philosophy

Status: ✅ Locked

Decision:

Major events should produce traceable logs.

Affected Documents:

- TB-006

---

# AF-019 — Extensibility First

Status: ✅ Locked

Decision:

Stable interfaces enable future expansion.

Affected Documents:

- API Specs

---

# AF-020 — Feature Flags

Status: ✅ Locked

Decision:

Features can be enabled or disabled without code changes.

Affected Documents:

- Config Guide
- TB-006

---

# AF-021 — Graceful Failure

Status: ✅ Locked

Decision:

Optional modules fail safely.

Critical modules stop execution.

Affected Documents:

- TB-006

---

# AF-022 — Documentation Before Code

Status: ✅ Locked

Decision:

Design precedes implementation.

Affected Documents:

- Developer Guide

---

# AF-023 — Framework vs Game

Status: ✅ Locked

Decision:

Separate reusable framework concepts from football-specific modules.

Affected Documents:

- TB-001

---

# AF-024 — Performance Budget

Status: ✅ Locked

Decision:

Engines receive defined execution priorities.

Affected Documents:

- TB-004

---

# AF-025 — Release Philosophy

Status: ✅ Locked

Decision:

New ideas follow roadmap priorities.

Affected Documents:

- Roadmap

---

# AF-026 — Backward Compatibility

Status: ✅ Locked

Decision:

Protect long-term saves whenever practical.

Affected Documents:

- TB-005

---

# AF-027 — Documentation Synchronization

Status: ✅ Locked

Decision:

Documentation updates before implementation.

Affected Documents:

- Developer Guide

---

# AF-028 — Deprecation Policy

Status: ✅ Locked

Decision:

Deprecate before removing.

Affected Documents:

- Developer Guide

---

# AF-029 — Code Generation Philosophy

Status: ✅ Locked

Decision:

Generate modules incrementally.

Affected Documents:

- Developer Guide

---

# AF-030 — AI Collaboration Workflow

Status: ✅ Locked

Decision:

Architecture → Documentation → API → Code.

Affected Documents:

- Developer Guide

---

# AF-031 — Stability Before Features

Status: ✅ Locked

Decision:

Reliability has priority over feature count.

Affected Documents:

- Roadmap

---

# Remaining Integration Tasks

✔ Create DB-014 Nation

☐ Create TB-007 Data Lifecycle

☐ Create Football Reference

☐ Create Glossary

☐ Create Project Roadmap

☐ Integration Pass

☐ API Specifications

---

END OF DOCUMENT
