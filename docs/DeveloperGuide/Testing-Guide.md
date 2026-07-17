# Testing Guide

Document ID: DG-004

Status: Active

Version: 1.0

---

# Purpose

This document defines the testing philosophy for GOAL: Legacy.

Testing ensures that new features remain reliable while preventing regressions as the project grows.

---

# Testing Philosophy

Testing is part of development.

A feature is not considered complete until it has been appropriately tested.

Testing should be incremental, repeatable, and focused.

---

# Testing Levels

## Unit Testing

Tests a single class or function.

Examples:

- Transfer fee calculation
- Contract expiry logic
- Reputation calculation

---

## Module Testing

Tests one module in isolation.

Examples:

- Transfer Module
- Economy Module
- Match Module

Modules should be testable without loading unrelated modules whenever practical.

---

## Integration Testing

Tests how multiple modules interact.

Examples:

Transfer

↓

Contract

↓

Club

↓

News

↓

Legacy

Confirm that events flow correctly across modules.

---

## Regression Testing

Whenever a bug is fixed, verify that:

- The original bug no longer occurs.
- Existing functionality still behaves correctly.

---

# Testing Principles

- Test behaviour, not implementation details.
- Prefer many small tests over a few large ones.
- Keep tests deterministic and repeatable.
- Tests should be easy to understand.

---

# Event Testing

For event-driven systems, verify:

- Correct event published.
- Correct subscribers notified.
- No duplicate events.
- No missing events.

---

# Performance Testing

Periodically verify:

- Daily simulation speed
- Save and load performance
- Memory usage
- Event queue efficiency

Optimization should never reduce correctness.

---

# Manual Testing

Some gameplay systems require manual verification.

Examples:

- User Interface
- Match flow
- Career progression
- Long-term simulation

---

# Definition of Done

A feature is considered complete when:

- Documentation is updated
- Implementation is complete
- Tests pass
- No known critical issues remain
- Changes are committed to Git

---

# Principles

- Stability before features
- Incremental testing
- Independent modules
- Repeatable results

---

End of Document
