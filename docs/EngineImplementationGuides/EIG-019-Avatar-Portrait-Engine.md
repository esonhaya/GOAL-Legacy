# GOAL: Legacy
# Engine Implementation Guide

Document ID: EIG-019
Title: Avatar and Portrait Engine
Version: 1.0
Status: Phase 2 implementation guide

## Ownership

`PlayerAppearance` is the canonical cosmetic specification. The Player
module's `PlayerAppearanceRepository` stores one compact JSON specification
per Player in the career save. `PlayerAppearanceGenerator` owns deterministic
NPC and fallback generation. `PortraitRenderer` owns composition and cache
output. No renderer code changes Player attributes or football state.

## Catalog

Installed content lives under `game/assets/avatar/v1`. `assets.json`,
`palettes.json`, `presets.json`, and `compatibility.json` are immutable
content metadata. SVG paths are resolved through metadata, so behavior never
depends on filenames. `tools/generate_avatar_assets.py` regenerates the
functional first-pass library deterministically. `avatar:validate` checks
references and SVG safety.

## Determinism and migration

NPC generation uses independent SHA-256 namespaces for each appearance
category. This is isolated from Match, development, transfer, and career
event randomness. Missing legacy appearance rows generate a compatible V1
default on first profile render; old Player rows remain valid. Deprecated
asset IDs remain renderable and use category fallbacks only when the installed
file is unavailable. The appearance schema version is persisted beside the
JSON specification.

## Rendering and cache

Portrait cache paths are `storage/cache/portraits/<size>/` and are safe to
delete. Cache keys include the appearance schema, renderer version, complete
appearance specification, kit, expression, background, and size. SVG output
is generated at 512 viewBox scale and resized by its width/height attributes;
no image binary is stored in SQLite. `career:portrait` exposes the cache path
for CLI and future graphical/profile consumers.

`PortraitContext` derives age band and body presentation from canonical Player
birth date, height, weight, and current date. Club colors and a deterministic
generic kit template are render context. These derived values are never saved
as a second identity model.

## Creator and QA tooling

`career:new --appearance-preset=<id>` stores a complete controlled-player
specification. `career:appearance` supports preset, all-random, and category
randomization. Existing careers use deterministic fallback generation.
`avatar:contact-sheet <save-id> [directory]` produces inspectable SVG sheets
for faces, eyes, noses, mouths, hairstyles, facial hair, presets, and 100 NPC
portraits without writing into career saves.

## Revision History

| Version | Date | Notes |
|---|---|---|
| 1.0 | 2026-09-17 | Added Avatar V1 ownership, persistence, rendering, and QA guidance. |
