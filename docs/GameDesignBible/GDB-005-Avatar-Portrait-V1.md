# GOAL: Legacy
# Avatar and Portrait V1

Document ID: GDB-005
Title: Avatar and Portrait V1
Version: 1.0
Status: Phase 2 foundation

## Purpose

The Avatar V1 system gives each Player a stable, readable cosmetic identity
for profile and card presentation. It is a presentation layer for the
football career, not a new football simulation system.

## Appearance ownership

`PlayerAppearance` owns cosmetic component IDs only. Skin, face, jaw, ears,
eyes, eye color, brows, nose, mouth, hair, hair color, facial hair, skin
details, scars, and accessories are stored as stable asset IDs. Age band,
body presentation, Club kit, expression, and background are derived render
context.

Appearance never affects attributes, OVR, potential, development, selection,
Match simulation, transfers, Contracts, or career opportunities. Stable face
identity is separate from mutable hair, grooming, and accessory choices so a
future grooming feature can change presentation without changing identity.

## Generation and customization

NPC appearances are deterministic from Player identity and creation seed.
Each component has its own `appearance:v1:<category>` namespace, so adding a
future component does not reroll existing features. Nationality does not
select facial anatomy. Age only supplies modest hair and facial-hair bounds.
The controlled Player receives a generated or selected creator preset and may
randomize the complete appearance or one category.

## Rendering boundary

Installed SVG components are composed by `PortraitRenderer` at 32, 64, 128,
256, and 512 pixels. The renderer supports Club kit, five expressions, five
age bands, four body presentations, and six background contexts. Rendered
SVG files are disposable cache artifacts. Career SQLite saves retain only the
small appearance specification and never contain PNG, WebP, base64, or SVG
portrait data.

## Phase 2 boundary

V1 does not add a cosmetic economy, shops, licensed kits, 3D models,
animation, Match sprites, social popularity, or appearance effects on
football outcomes. The functional first-pass art library is intentionally
simple and is designed for later visual expansion without changing saved
IDs.

## Revision History

| Version | Date | Notes |
|---|---|---|
| 1.0 | 2026-09-17 | Added the Phase 2 Avatar V1 foundation. |
