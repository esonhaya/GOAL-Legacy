# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-003
Title: Save Manager

Version: 1.0
Status: Approved Blueprint

Dependencies:
- EIG-001 Core Engine
- EIG-002 Event System

---

# Purpose

The Save Manager is responsible for preserving and restoring the complete state of a GOAL: Legacy universe.

It coordinates save creation, loading, autosaves, version compatibility, validation, and recovery.

The Save Manager never determines gameplay outcomes. Its sole responsibility is ensuring the integrity and persistence of world data.

---

# Responsibilities

The Save Manager is responsible for:

- Creating new saves
- Loading existing saves
- Manual saves
- Autosaves
- Save validation
- Save version management
- Recovery from interrupted saves
- Coordinating serialization with engine modules

---

# Non-Responsibilities

The Save Manager does NOT:

- Simulate gameplay
- Modify football logic
- Calculate player data
- Resolve events

Gameplay modules own their own data.

---

# Public Interface

Primary operations include:

- CreateSave()
- LoadSave()
- SaveGame()
- AutoSave()
- DeleteSave()
- ValidateSave()
- UpgradeSaveVersion()

Implementation details may evolve.

---

# Internal Components

## Save Registry

Maintains metadata for every save.

Examples:

- Save Name
- World ID
- Creation Date
- Last Played
- Engine Version

---

## Serializer

Coordinates saving and loading module data.

Each module serializes only the data it owns.

---

## Validator

Verifies save integrity before loading.

Checks include:

- Missing files
- Corrupted data
- Version compatibility
- Required modules

---

## Recovery Manager

Handles interrupted save operations.

Goals:

- Prevent corrupted saves
- Restore last valid state
- Report recovery actions

---

# Save Lifecycle

1. Save requested.
2. Core Engine pauses simulation.
3. Modules serialize owned data.
4. Validator checks generated data.
5. Save written to storage.
6. Simulation resumes.

---

# Load Lifecycle

1. User selects save.
2. Validator checks integrity.
3. Save version verified.
4. Modules restore owned data.
5. Event System restored.
6. World resumed.

---

# Data Ownership

The Save Manager owns:

- Save metadata
- Save version
- Save manifest
- Validation reports

Gameplay modules own gameplay data.

---

# Autosave Strategy

Autosaves may occur:

- Before season transitions
- Before transfer windows
- After major milestones
- At configurable intervals

Autosaves should never interrupt gameplay noticeably.

---

# Version Compatibility

Every save stores:

- Engine Version
- Database Version
- Blueprint Version

Future upgrades may migrate older saves automatically.

---

# Failure Handling

If saving fails:

- Keep the previous valid save.
- Report the failure.
- Never overwrite a working save with an incomplete save.

If loading fails:

- Stop loading.
- Report the reason.
- Preserve existing save data.

---

# Testing Strategy

The Save Manager should be tested for:

- Save creation
- Save loading
- Autosaves
- Corrupted save detection
- Version upgrades
- Recovery after interruption
- Large save performance

---

# Performance Goals

- Fast save operations
- Reliable autosaves
- Minimal memory overhead
- Deterministic restoration
- Zero silent corruption

---

# Future Expansion

Future versions may support:

- Multiple save slots
- Cloud synchronization
- Save compression
- Incremental saves
- Replay snapshots
- Debug save inspection
- Cross-platform migration

These additions should extend the Save Manager without changing its core responsibilities.

---

# Locked Decisions

✓ Modules serialize only their own data

✓ Central Save Manager coordination

✓ Save validation before loading

✓ Version-aware save files

✓ Recovery before overwrite

✓ Autosave support

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
