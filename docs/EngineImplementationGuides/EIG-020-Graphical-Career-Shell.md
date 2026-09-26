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

## P3-011 Career Home information hierarchy

Career Home now leads with the controlled Player's identity, `NEXT UP`,
`CURRENT STATUS`, `NEEDS ATTENTION`, and `RECENT STORY` before the deeper
Season, Club, readiness, and Career context panels. `CareerPresentationService`
owns the bounded read projection and a deterministic presentation priority;
`WebApplication` renders it and keeps existing forms/routes as the action
boundary. A primary action is shown once, while quick links remain secondary.

The projection distinguishes available, limited, injured, suspended, free
agent, loaned, and retired states using existing canonical facts. It never
predicts selection, invents deadlines or manager sentiment, persists an action
queue, or exposes Developer/Sandbox controls in normal Career Home. At narrow
widths attention rows stack and the primary action remains a tappable button.
Scenario-backed presentation tests cover representative limited, injury, and
Contract states; the read path is checked with SQLite `total_changes()`.

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

## P3-012 decision flow

Pending Contract, transfer, loan, and retirement pages use the existing
CareerPresentationService to show current facts, known effects, and explicitly
non-guaranteed future effects. CareerMovementService and PlayerLifecycleService
remain the execution owners. After a successful POST, outcome copy is derived
from the resolved domain opportunity, and bounded decision history reuses that
opportunity record for Career History/Home. Decision GETs do not simulate or
mutate gameplay; stale checks and redirect-after-POST remain authoritative.

## P3-013 event variety and read safety

Between-Match event cards remain projections of canonical Career state. The
shell renders the existing event definition, context, and choices; it does
not select, reroll, or execute an event while rendering a GET. Event
resolution is still an explicit one-time POST and continues to redirect to
the existing Home projection, so Needs Attention, the primary action,
Contract/movement context, and bounded Recent Story are recalculated from
canonical state.

The development-only `career:variety` diagnostic is outside the graphical
runtime. It reuses the Goal scenario fixtures and production event selector
to compare state-specific eligibility and deterministic suppression.
Responsive event cards must preserve concise copy, readable choice labels,
keyboard/focus order, and a tappable action at narrow widths; variety must
not require a new UI state store or hidden gameplay score.

## P3-014 Matchday loop

The graphical Career loop is `Career Home → PRE-MATCH → canonical Match POST
→ completed Matchday/Post-Match → Career Home`. Pre-Match GET is built from
`CareerPresentationService::preMatch()` and shows fixture, competition stage,
home/away, availability/readiness, role, form, and existing fixture context.
Selection/deployed position are explicitly kickoff facts; no weights/preview
simulation.

`advance_match` is a fixture-scoped one-use POST with PRG. It checks current
next fixture and delegates existing `CareerContinueCommand`/`MatchService`
path. Completed Matchday GET reads canonical selection, minutes, deployment,
Match events, rating explanation, position-aware stats, bounded consequences.
Refresh is read-only; repeated POST is rejected.

Shell keeps one primary action, semantic status text, mobile stacking; no
tactics controls, second rating/form system, highlight RNG, or new history.
