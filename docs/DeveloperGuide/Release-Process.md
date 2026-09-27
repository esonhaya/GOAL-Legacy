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

# P3-021 Phase 3 Release Candidate Gate

P3-021 is the Phase 3 integration and release-candidate gate. It composes
existing canonical tests and deterministic tooling; it does not add gameplay
rules or repeat the full P3-020 performance matrix.

## Release baseline

- Baseline HEAD: `4217b2a75aa9d75b15cfb2ae0c0b9eef301d94ef`
- Protected untracked documents: the three DeveloperGuide documents present at
  the gate (`Architecture-Freeze-Integration-Roadmap.md`,
  `File-Replacement-Policy.md`, and `Major-Document-Generation-Policy.md`).
- Composer lock and vendor Haya Doctor reference:
  `10ed9d1192f33c75bcfff2d4cf0539f8a2843b86`.
- No production implementation change was required by the gate.

## Deterministic journey matrix

| Journey | Canonical evidence | Result |
| --- | --- | --- |
| A — first football loop | `P3016CareerJourneyTest`, `P3014MatchdayPresentationTest` | PASS |
| B — adversity and decisions | `P3009SimulationLabTest`, `P3010CareerLabTest`, `P2026InjuryRecoveryTest`, `P3017SaveReliabilityTest` | PASS |
| C — Season and movement | `career:multi-season-audit --lifecycle-only --seasons=1`, `P2038CareerLifecycleIntegrationTest`, `CareerPacingObservatoryTest` | PASS |
| D — mature Career | `LATE_CAREER` scenario, `P3013CareerVarietyTest`, P3-020 AGE3/AGE5 evidence | PASS |
| E — Career closure | `P2016CareerLifecycleTest`, `CareerPresentationTest`, `CareerAchievementSummaryTest` | PASS |

Journey A uses the production `WebApplication` creation flow and real Match
POST. Scenario setup uses `GoalScenarioBuilder`, `GoalScenarioCatalog`,
`GoalMatchRunner`, and canonical lifecycle services. No test reimplements
selection, development, transfer, Contract, rollover, or retirement rules.

## Local gate evidence

- PHP syntax: PASS across `game/` and `tests/`.
- Focused local runs: 65 test executions, 506 assertions, zero failures. The
  batches intentionally overlap a small amount of reusable regression
  coverage.
- Haya Doctor, core self-check, and Career self-check: PASS.
- Bounded lifecycle audit: `reload_failures=none`, `unclubbed_active=0`,
  `squad_min=25`, `squad_max=25`.
- Representative SQLite read-only probe: `integrity_check=ok`, FK rows `0`.
- Normal GET/read projections: no gameplay DML; action tokens and ownership
  remain save-scoped and one-use.

P3-020 watch items are carried forward without implementation work: AGE5
Profile approximately 5.4 seconds / 4,139 queries, AGE5 save approximately
102 MB / 272,049 rows, and save growth approximately 17.8 MB per Season.
They remain release-acceptable while usable, reloadable, and integrity-clean;
P3-021 does not classify them as blockers.

Local Composer and Chromium/Playwright are unavailable in this Termux image.
Remote GitHub Actions is authoritative for locked dependency installation,
Fast PHP, canonical PHP, the real PHP server, and desktop/mobile Chromium
artifacts. The final P3-021 CI run and release status are recorded here after
the release commit is observed remotely.

---

End of Document
