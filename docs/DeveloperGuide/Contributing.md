# Contributing

Document ID: DG-006

Status: Active

Version: 1.0

---

# Purpose

This document defines how GOAL: Legacy should be developed and maintained.

Whether development is performed by the original developer, future collaborators, or AI-assisted tools, everyone should follow the same philosophy.

---

# Project Philosophy

GOAL: Legacy values:

- Documentation before implementation
- Stability before features
- Modular architecture
- Incremental development
- Long-term maintainability
- Realistic football simulation

Every contribution should reinforce these principles.

---

# Before Making Changes

Ask the following questions:

- Does this feature already exist?
- Does documentation already define this behavior?
- Does this violate the Architecture Freeze?
- Which module owns this responsibility?
- Does this belong in Phase 1?

If the answer is unclear, update the documentation before writing code.

---

# Development Workflow

Every contribution should follow:

Idea

↓

Architecture

↓

Documentation

↓

API (if needed)

↓

Implementation

↓

Testing

↓

Review

↓

Git Commit

---

# Documentation

Documentation is the project's source of truth.

If implementation requires a design change:

Update the documentation first.

Never allow the implementation to silently diverge from the documented architecture.

---

# Modular Development

Prefer improving one module at a time.

Avoid making unrelated changes in a single contribution.

Modules should remain:

- Independent
- Testable
- Replaceable

---

# AI-Assisted Development

AI is considered a development assistant.

AI may assist with:

- Brainstorming
- Architecture
- Documentation
- Code generation
- Refactoring
- Reviews

Final decisions remain the responsibility of the project maintainer.

---

# Coding Standards

All implementations should follow:

- Coding Standards
- Technical Bible
- Database Bible
- Engine Implementation Guides
- Architecture Freeze

---

# Testing

Every significant contribution should be tested appropriately before being committed.

Bug fixes should include regression testing whenever practical.

---

# Git

Prefer:

- Small commits
- Clear commit messages
- One logical change per commit

Avoid large commits containing unrelated work.

---

# Respect the Freeze

Architecture Freeze decisions are considered stable.

If a contribution requires changing a freeze decision:

1. Document the reason.
2. Review affected documents.
3. Update the Architecture Freeze Checklist.
4. Then proceed with implementation.

---

# Long-Term Vision

GOAL: Legacy is intended to evolve over many years.

Short-term convenience should never compromise long-term maintainability.

When in doubt:

Choose the solution that future developers will understand most easily.

---

End of Document
