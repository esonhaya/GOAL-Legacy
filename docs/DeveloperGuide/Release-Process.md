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

# Automated Verification

The repository workflow in `.github/workflows/ci.yml` provides three bounded
verification paths:

- **Fast PHP gate** runs syntax checks and the focused web hardening tests on
  pull requests and pushes to `main`.
- **Canonical PHP regression** runs the authoritative `composer test` command
  on `main` pushes and on manual `workflow_dispatch` runs.
- **Browser smoke** starts the real PHP application, prepares one isolated
  Career through the canonical Career-start workflow, and checks the normal
  creation form plus a small desktop and mobile Playwright surface set. It
  uses Chromium only and does not expose a test-only production route.

The browser fixture owns only saves named with the `p3019-browser-` prefix and
uses canonical services rather than hand-written SQLite state. Browser
failures retain the Playwright report, failure screenshots, and traces where
generated; successful release screens are a small diagnostic sample. Artifacts
are retained for seven days and must contain only synthetic CI state.

CI also prepares the locked Haya Doctor path dependency without changing the
Composer architecture. Haya Doctor is public, and the preparation script
fetches its published `goal-legacy-locked-10ed9d1` ref anonymously, then
verifies that it resolves to the exact SHA recorded in `composer.lock`.
There are no dependency credentials or production secrets involved.

Developers with Node and Chromium installed can run the browser suite with:

```sh
npm ci
npm run test:browser
```

The browser project is intentionally not a visual-golden test. Its mobile
assertions cover semantic access, primary actions, and page-level overflow;
GitHub Actions supplies the browser-capable environment. The local Termux
environment may therefore report browser execution as unavailable while PHP
verification remains runnable locally.

---

End of Document
