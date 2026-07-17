# Coding Standards

Document ID: DG-003

Status: Active

Version: 1.0

---

# Purpose

This document defines the coding standards for GOAL: Legacy.

The objective is to produce clean, maintainable, modular, and well-documented code.

---

# Core Principles

Every implementation should prioritize:

- Readability
- Simplicity
- Modularity
- Maintainability
- Performance
- Consistency

Code is written for future developers as much as for the computer.

---

# Single Responsibility Principle

Every class, file, and function should have one clear responsibility.

Avoid "God Classes" or files that handle multiple unrelated concerns.

---

# Modular Design

Each module should remain self-contained.

Modules communicate through events and public APIs rather than direct dependencies whenever possible.

---

# Naming Conventions

Names should be descriptive.

Examples:

Good:

- TransferEngine
- ContractRepository
- MatchScheduler

Avoid:

- Temp
- Helper2
- Stuff
- Misc

---

# File Organization

Files should remain focused.

If a file becomes difficult to understand because of size or multiple responsibilities, consider splitting it into smaller components.

---

# Documentation

Public classes and methods should include concise documentation describing:

- Purpose
- Parameters
- Return values
- Side effects (if any)

Complex logic should explain *why*, not *what*.

---

# Error Handling

Errors should be handled gracefully.

Unexpected failures should produce useful logs while avoiding unnecessary crashes.

---

# Performance

Optimize only after correctness.

Prefer clear, correct code before micro-optimizations.

Major optimizations should follow the Performance Budget defined in the Technical Bible.

---

# Testing

New functionality should be accompanied by appropriate tests whenever practical.

Bug fixes should include regression tests where possible.

---

# Refactoring

Improve existing code rather than rewriting it unnecessarily.

Large refactors should preserve external behavior unless an architectural decision requires change.

---

# Principles

- Documentation before code
- Stability before features
- Single Source of Truth
- Event-driven communication
- Incremental development
- Clean Git history

---

End of Document
