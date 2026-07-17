# Git Workflow

Document ID: DG-002

Status: Active

Version: 1.0

---

# Purpose

This document defines the official Git workflow for GOAL: Legacy.

The objective is to maintain a clean project history while supporting incremental, modular development.

---

# Development Philosophy

Commit often.

Commit logically.

Never commit unfinished or broken features to the main branch.

Every commit should represent meaningful progress.

---

# Recommended Workflow

Start Development

↓

Implement Feature

↓

Review

↓

Run Tests

↓

Commit

↓

Push

↓

Continue

---

# Commit Philosophy

Each commit should focus on a single purpose.

Good examples:

- Add Transfer Engine skeleton
- Implement Contract API
- Update Database Bible
- Refactor Match Engine events
- Fix transfer negotiation bug

Avoid combining unrelated changes.

---

# Commit Message Format

Recommended format:

Category: Description

Examples:

docs: add Architecture Freeze checklist

feat: implement Contract Engine skeleton

fix: correct transfer fee calculation

refactor: simplify Match Engine events

test: add Economy module tests

perf: optimize daily scheduler

---

# Documentation Commits

Major documentation milestones should be committed separately.

Examples:

- Database Bible updates
- Technical Bible revisions
- Architecture Freeze decisions

---

# Module Commits

Each module should be committed independently whenever practical.

Example:

Transfer Module

↓

Commit

↓

Contract Module

↓

Commit

↓

Economy Module

↓

Commit

This simplifies debugging and future revisions.

---

# Before Every Commit

Confirm:

- Documentation updated (if required)
- Code compiles
- Tests pass
- No unnecessary files included
- Commit message is descriptive

---

# Branch Strategy

Phase 1 may use a simple workflow:

main

↓

Feature Development

↓

Commit

↓

Push

Future versions may introduce dedicated feature branches if team development expands.

---

# Releases

Stable milestones should be tagged.

Example:

v0.1.0

Architecture Complete

v0.2.0

Core Framework

v0.3.0

First Playable Prototype

v1.0.0

Official Release

---

# Principles

- Small commits
- Clear history
- Stable main branch
- Incremental progress
- Documentation synchronized with implementation

---

End of Document
