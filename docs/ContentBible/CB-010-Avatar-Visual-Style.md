# GOAL: Legacy
# Content Bible

Document ID: CB-010
Title: Avatar V1 Visual Style and Asset Authoring
Version: 1.0
Status: Phase 2 content guidance

## Art direction

Avatar V1 uses stylized 2D football portraits with clean cartoon
proportions. Components should remain recognizable at 64 pixels, align to the
shared 512 by 512 anchors, and read well on a Player card. The style is
friendly and clear without caricaturing nationality, class, or age.

Use broad human variation. Nationality is never a shortcut for facial
anatomy. Skin and hair palettes provide variation without encoding a
stereotype. Hair coverage includes bald and shaved styles, straight, wavy,
curly and coily textures, afro, braids, cornrows, locs, bun, and ponytail.

## Asset rules

- Master components are static transparent SVG with `viewBox="0 0 512 512"`.
- Components use shared anchors and palette placeholders rather than embedded
  raster images, fonts, scripts, or external URLs.
- IDs use `avatar.<category>.<family>.<variant>` and palettes use
  `palette.<category>.<variant>`.
- Catalog metadata owns labels, layers, tint mode, creator/NPC availability,
  weights, compatibility, and deprecation. Filenames are implementation
  paths, never saved identity.
- Deprecated assets remain renderable for old saves and provide a replacement
  ID for future content. They are excluded from new generation.

## Composition and tone

The renderer layers background, body and kit, hair, ears, face, details,
eyes, brows, nose, mouth, facial hair, age, expression, and accessories.
Kits and backgrounds are context, not identity. Expressions are neutral,
happy, focused, disappointed, and celebrating. Keep event, News, and Player
card portraits factual and restrained.

## Presets and QA

Creator presets are starting points; the complete component specification is
always saved. The contact-sheet tool renders every high-value component
category, all presets, and 100 deterministic NPC samples. Catalog validation
checks IDs, references, SVG structure, palettes, presets, and compatibility.

The graphical creator presents human-readable catalog labels and a live
portrait preview. Visual QA should inspect category sheets, preset sheets,
100 deterministic NPC portraits, complete squad samples, and 64-pixel output.
Review for alignment, clipping, contrast, and perceptual clones. Keep the
first-pass style simple enough to remain readable on a phone and reusable in
Career Home, Squad, Player Profile, and Matchday.

## Revision History

| Version | Date | Notes |
|---|---|---|
| 1.0 | 2026-09-17 | Defined Avatar V1 visual and authoring rules. |
| 1.1 | 2026-09-17 | Added graphical creator and production visual-QA guidance. |
