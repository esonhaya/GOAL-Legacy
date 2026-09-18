# GOAL: Legacy
# Domestic Cup Engine

Document ID: EIG-023
Title: Domestic Cup Engine
Status: Phase 2 implementation guide

## Boundary

`DomesticCupService` owns participant selection, deterministic draws, round
state, advancement, elimination, shootout resolution, and compact Cup
history. `MatchService` remains the shared football and persistence boundary.
There is no Cup Match engine.

League fixtures are generated first so Cup date allocation can avoid a Club's
existing League fixtures. Career Continue queries the Club's nearest scheduled
Match without a Cup-specific clock. A Cup Match involving the controlled
Player uses `SimulationFidelity::Player`; an NPC-only Cup Match uses
`SimulationFidelity::World`.

## Determinism and replay safety

Draws use the stable namespace `cup-draw:v1`; home selection uses
`cup-home:v1`; extra-time and penalty resolution use
`cup-extra-time:v1`, `cup-penalty:v1`, and
`cup-sudden-death:v1`. Inputs include world seed, Cup, Season, round, Club or
Match identity. Cup draws therefore do not consume the Match RNG stream and do
not perturb League outcomes.

Completed-Match winner state is written conditionally. The next round is
generated only after all current-round Match state rows are resolved. A
completed Season cannot roll over while an active Cup Match remains.

## Fidelity and storage

The shared Match model still determines football outcomes, goals, decisive
events, discipline, standings-independent Season signals, and controlled
Player evidence. NPC Cup Matches write the same compact world facts as other
World-fidelity Matches. Detailed NPC Cup Player Match rows are not a hidden
requirement of the bracket.

## Current rules and future reuse

V1 is single elimination with preliminary rounds/byes and regulation followed
by deterministic extra time and penalties when still level. Seeded draws,
League cups, continental cups, and international tournaments remain future
rule modules.

---

END OF DOCUMENT
