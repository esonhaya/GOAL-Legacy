# GOAL: Legacy
# International Football Technical Contract

Document ID: TB-008
Title: International Football Technical Contract
Version: 1.0
Status: Active Phase 2 contract

## Services and flow

`WorldService` and `CoreServices` initialize the additive international schema
and expose `NationalTeamService` and `InternationalCompetitionService`.
`MatchService` generates and simulates international fixtures alongside League,
Cup, and European fixtures. `MatchSelectionService`, `MatchSimulationService`,
`PlayerCareerStatisticsService`, the presentation layer, and Matchday remain
canonical consumers. There is no parallel international Match, form,
development, or career engine.

International RNG is namespaced separately from Club RNG. Draws include world
seed, Season/cycle, competition, stage, and stable identity. Calendar dates
are allocated through the existing collision checks; selected Players' Club
dates are considered, so a Player cannot represent Club and country on one
date. Continue chooses the chronological next controlled fixture.

## Fidelity and storage

Controlled international fixtures use `SimulationFidelity::Player` and retain
the normal selection, action, rating, form, development, discipline, and
Career consequences. NPC-only fixtures use `SimulationFidelity::World` and
retain compact result/state facts only. The acceptance gate is zero detailed
NPC international Match rows unless a concrete consumer later requires one.

International Player statistics are stored separately as caps, starts,
minutes, goals, assists, cards, and ratings. They are never added to Club
League/Cup/Europe totals. Salary and finance remain calendar-driven.

## Format and lifecycle

The World Championship reuses group standings, deterministic tiebreaks,
single-leg knockout progression, AET, penalties, history, and rollover from
the existing competition architecture. A tournament is initialized on cycle
years (initially 2024/25, then every four Seasons), completes even when the
controlled country is absent or eliminated, and produces one winner and
runner-up. Selection windows and team membership are persisted, not rebuilt
on render.

## Future boundary

Do not infer national qualifying, continental national competitions, licensed
branding, or international finance from this contract. Any future expansion
must preserve canonical Match ownership, World fidelity, additive migration,
and the bounded storage/runtime budget.

END OF DOCUMENT
