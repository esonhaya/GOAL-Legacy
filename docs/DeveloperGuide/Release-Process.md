# Release Process

Document ID: DG-005

Status: Active

Version: 1.0

---

# Purpose

This document defines the official release workflow for GOAL: Legacy.

Every feature should pass through the same quality process before becoming part of the project.

---

# Development Pipeline

Idea

↓

Architecture

↓

Documentation

↓

Architecture Freeze (if required)

↓

API Specification

↓

Implementation Skeleton

↓

Implementation

↓

Testing

↓

Review

↓

Git Commit

↓

Version Update

↓

Release

---

# Release Checklist

Before a feature is considered complete:

☐ Documentation updated

☐ Architecture still respected

☐ Database changes documented

☐ APIs updated

☐ Tests completed

☐ No known critical bugs

☐ Git commit created

☐ Changelog updated

☐ Version reviewed

---

# Release Types

## Documentation Release

Documentation only.

Examples:

- Bible updates
- Freeze decisions
- README improvements

---

## Development Release

Internal milestone.

Examples:

- Module skeleton
- New APIs
- Core framework

---

## Testing Release

Feature-complete but undergoing testing.

---

## Stable Release

Suitable for normal gameplay.

Tagged in Git.

---

# Versioning

Recommended format:

Major.Minor.Patch

Examples:

v0.1.0

Architecture Complete

v0.2.0

Core Framework

v0.3.0

First Playable

v0.5.0

Phase 1 Feature Complete

v1.0.0

Official Release

---

# Changelog

Every release should update:

CHANGELOG.md

Describe:

- New features
- Improvements
- Fixes
- Known issues

---

# Git Tags

Stable releases should receive Git tags.

Examples:

v0.1.0

v0.2.0

v1.0.0

---

# Release Principles

- Stability before features
- Documentation synchronized
- Modular releases
- Incremental progress
- Clean Git history

---

End of Document

