# GOAL: Legacy
# Engine Implementation Guide

Document ID: EIG-020
Title: Graphical Career Shell
Version: 1.0
Status: Phase 2 implementation guide

## Launch

The player-facing application is a small server-rendered PHP shell. From the
repository root, run:

```sh
php -S 127.0.0.1:8080 -t game/public
```

Then open `http://127.0.0.1:8080/` in a local browser. The CLI remains
developer tooling and is not required after the graphical application starts.

## Ownership boundary

`WebApplication` owns HTTP routing, session draft state, human-facing forms,
redirects, and page composition. It delegates career creation, Youth Camp,
Continue, Match simulation, ratings, form, standings, events, decisions,
training, priorities, and persistence to existing canonical services.
`WebCareerStartWorkflow` is an adapter for the existing Youth Camp start
sequence; it does not implement a second career-start rule set.

`WebView` owns shared HTML escaping, layout, navigation, form helpers, cards,
and portrait references. `app.css` owns responsive presentation for phone,
tablet, and desktop widths. The shell uses no SPA framework or network
dependency.

## Navigation and action safety

The main menu exposes New Career and saved careers. A career shell exposes
Career Home, Career, Squad, World, News, Training, and Continue. Pending
canonical events and career decisions have dedicated pages. POST actions use
one-time session tokens for Continue, event resolution, decision resolution,
and Club selection. The browser disables a submitted action while the
request is running; a replayed token is rejected without mutating the save.
Save & Exit clears only transient web draft/token state. Career persistence
remains owned by the transactional save system.

## Avatar integration

The creator edits the canonical `PlayerAppearance` fields, applies catalog
presets, and randomizes all or one category. Its live preview calls the same
`PortraitRenderer` used by profile pages. Saved player portraits use the
disposable portrait cache; draft previews are never written into SQLite.
The same portrait URL is reused by Career Home, Squad, Player Profile,
Matchday, and saved-career cards.

## Runtime behavior

The built-in PHP server is configured with an unlimited request time because
canonical world initialization and Continue can legitimately take longer
than PHP's short default web timeout. The UI displays a truthful `Working...`
state and disables the submitted button. It does not invent progress values.
Errors return a readable page or a flash message and preserve the current
career state.

## Responsive and visual QA

The shell targets 360px, 768px, and 1280px widths. Navigation scrolls on
narrow screens, tables use bounded horizontal scrolling, cards stack below
the tablet breakpoint, and portraits retain a square aspect ratio. Validate
the creator, Career Home, Squad, Matchday, and pending choice pages at those
widths. Use the existing avatar contact-sheet tooling for category, preset,
NPC, and squad review; generated sheets and cache files remain disposable.

## Revision History

| Version | Date | Notes |
|---|---|---|
| 1.0 | 2026-09-17 | Added the first graphical career shell and launch contract. |

## P2-004 Player and World Browsing

The graphical shell exposes the canonical read path `World → Competition →
Club → Squad → Player`. `CareerPresentationService` owns these composed read
models; templates do not calculate standings, statistics, form, or roles.
Squad cards use one canonical squad query and compact
`player_season_statistics` aggregates for world-simulated Players.

NPC profiles expose public identity, current Club, role, OVR, and factual
current-Season aggregates. Potential and other development or simulation
internals remain outside the Player information boundary. All browsing routes
are read-only with respect to career facts. Generated saves and preview
databases belong under `game/saves/`, which is ignored by Git; temporary
benchmark databases are removed individually after use.

## P2-011 action and state coherence

Career Home renders exactly one primary progression action. Blocking
decisions and pending Career Events link directly to their resolver; the
Continue form is not rendered while either is pending. Otherwise its label
describes whether the Player is continuing generally or toward the next
fixture. Secondary links remain read-only navigation.

The situation panel includes availability and active injury recovery context.
The controlled profile uses the active Contract/current-career read model for
Club identity, so a former Club cannot leak into a free-agent presentation.
These are presentation fixes over canonical state, not a new UI state store.

## P2-024 On-Pitch Role Presentation

The controlled Profile presents Position, Squad Role, On-Pitch Role, and
Playing Style as distinct facts. The role panel lists only roles compatible
with P2-019 capability, gives a short football-readable description and
descriptive suitability, and posts one preference to the existing Career
reference. It exposes no formula, score, mastery, or recommendation. Career
Home shows the current role compactly; the role panel is not a permanent
tactics-management screen.

Matchday/Post-Match use the role stored in the existing controlled Match
position snapshot, so an actual fallback/default is shown when needed. NPC
Profiles may show a deterministic on-demand role; no NPC role state is written.
All role presentation is read-safe and uses no page-render mutation.
