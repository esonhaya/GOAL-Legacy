# GOAL: Legacy
# Technical Bible

Document ID: TB-002
Title: Project Structure

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

This document defines the official directory structure, module organization, and file ownership for GOAL: Legacy.

Its purpose is to ensure the project remains scalable, maintainable, and easy to navigate throughout every phase of development.

This document is considered the authoritative reference for where new files should be created.

---

# Design Philosophy

The project follows a modular architecture.

Every system has one owner.

Every file has one responsibility.

Every directory has one purpose.

If a file does not clearly belong somewhere, a new module should not be created until its responsibilities are understood.

---

# Root Directory

```
GOAL-Legacy/
```

The root contains project-wide files.

## Standard Files

```
README.md
```

Project overview.

---

```
MANIFESTO.md
```

Project vision and long-term philosophy.

---

```
CHANGELOG.md
```

Version history.

---

```
TODO.md
```

Development roadmap and pending tasks.

---

```
.gitignore
```

Git exclusions.

---

# Documentation

```
docs/
```

Contains every design and technical document.

Nothing inside this directory is executable.

Documentation is divided into independent collections.

```
ProjectIdentity/
```

Project vision and planning.

Contains:

- Vision
- Constitution
- Roadmap
- Executive Overview
- Glossary

---

```
GameDesignBible/
```

Gameplay specifications.

Contains:

- Career Systems
- Football Systems
- World Systems
- Economy
- Relationships
- Legacy

---

```
TechnicalBible/
```

Software architecture.

Contains:

- Architecture
- Database
- Module Specifications
- Save System
- Developer Tools

---

```
DatabaseBible/
```

Database table specifications.

Every table receives its own document.

---

```
ContentBible/
```

Contains reusable game content.

Examples:

- News Templates
- Pulse Templates
- Event Templates
- Achievement Definitions

---

```
DeveloperGuide/
```

Development documentation.

Examples:

- Installation
- Debugging
- Coding Standards
- Release Process

---

# Game Directory

```
game/
```

Contains every executable component.

---

## public/

Browser entry point.

Contains:

- index.php

Future CSS and JavaScript entry files.

No game logic belongs here.

---

## core/

Contains global engine components.

Examples:

- Bootstrap
- Pulse Engine
- Save Manager
- Event Dispatcher

Core files coordinate systems.

They do not implement gameplay.

---

## modules/

Contains independent gameplay systems.

Every gameplay feature belongs to exactly one module.

Modules communicate through public interfaces.

Modules should never directly manipulate another module's internal data.

---

## Standard Module Layout

Every module follows the same structure.

Example:

```
modules/player/

README.md

PlayerModule.php

PlayerEvents.php

PlayerActions.php

PlayerRepository.php

config/

data/

tests/
```

Every module must include a README describing:

- Purpose
- Responsibilities
- Dependencies
- Database Tables
- Developer Notes

---

## data/

Static data.

Examples:

- Countries
- Cities
- Club Names
- First Names
- Surnames
- Formations
- Tactical Styles

Game balancing should prefer data files over hardcoded values.

---

## config/

Configuration values.

Examples:

- Injury Rates
- Growth Curves
- Difficulty Settings
- Financial Multipliers

Gameplay tuning belongs here.

---

## assets/

Visual assets.

Examples:

- Images
- Icons
- Fonts
- CSS

No gameplay logic belongs inside assets.

---

## saves/

Generated save files.

Ignored by Git.

---

## cache/

Temporary generated data.

May be deleted safely.

Ignored by Git.

---

## logs/

Runtime logs.

Ignored by Git.

---

## devtools/

Developer Console.

Contains debugging utilities.

Never included in release builds unless explicitly enabled.

---

# Tools

```
tools/
```

Development utilities.

Examples:

- Content Generators
- Migration Scripts
- Database Repair Tools
- Balancing Utilities

These are not part of the game itself.

---

# Tests

```
tests/
```

Automated testing.

Future unit tests and integration tests belong here.

---

# Module Ownership

Each gameplay concept has exactly one owner.

Example:

Player Module

Owns:

- Attributes
- Morale
- Fitness
- Confidence
- Personality

Does NOT own:

- League Table
- Transfers
- News

---

Transfer Module

Owns:

- Negotiations
- Offers
- Contracts
- Loan Deals

Does NOT own:

- Match Simulation
- Player Growth

---

League Module

Owns:

- Fixtures
- Standings
- Promotion
- Relegation

Does NOT own:

- Individual Player Attributes

---

# Naming Standards

Directories

Lowercase.

Example:

```
player
career
match
league
```

PHP Classes

PascalCase.

Example:

```
PlayerModule.php
MatchEngine.php
SaveManager.php
```

Configuration

snake_case.

Example:

```
injury_rates.php
difficulty_settings.php
```

Documentation

Prefix with document IDs.

Example:

```
TB-001-Technical-Architecture.md
GDB-002-Simulation-Bible.md
```

---

# Future Expansion

New systems should be added as modules.

Existing modules should not be modified unless necessary.

Expansion must preserve backward compatibility whenever possible.

---

# Locked Decisions

The following decisions are considered permanent.

✓ Modular Architecture

✓ One Responsibility Per File

✓ One Owner Per Gameplay System

✓ Data Driven Content

✓ Mobile First Development

✓ Independent Documentation

✓ Standard Module Layout

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT
