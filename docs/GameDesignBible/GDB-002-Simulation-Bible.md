
# GOAL: Legacy
# Simulation Bible

## DOMAIN-006 Match Simulation Boundary

Core SimulationTime and the World SimulationDate remain the only timeline
sources. The Match Module maps deterministic Season dates to scheduled Match
records and explicitly processes due Matches; reading or advancing a date does
not invoke hidden wall-clock behavior.

Phase 1 simulation uses stable Match identity, Club reputation, and the
average OVR of eligible registered Players when available. Clubs without
persisted rosters use reputation as an aggregate baseline and receive no
synthetic Player records. A hash-derived deterministic seed produces bounded
Poisson-like scores, structured goal highlights, and minimal real-Player stat
lines. Repeating the same Match context produces the same result.

Completed Match results are the source of truth for the seasonal standings
projection. DOMAIN-011 uses broad position groups to build a minimal
structural XI and seven-Player bench, then applies a deterministic one-to-three
substitution policy between minutes 55 and 89 within the modern Competition
maximum of five substitutions per team. Substitutes receive real Match
minutes and therefore use the existing fatigue, development, form, and
expectation paths; no formation or tactical role engine is implied.
Interactive controls, tactics, discipline, in-match injury chains, weather,
and minute-by-minute simulation remain deferred.

## DOMAIN-008 Career Pressure Boundary

Before simulation, the Match Module reuses the existing eligibility path and
deterministically ranks real eligible squad Players using OVR, Club-scoped
squad role, recent derived form, and stable Match/Player tie-breaking. Up to
eleven become starters, the next seven are bench selections, and remaining
eligible Players are not selected. DOMAIN-011 satisfies available goalkeeper,
defensive, midfield, and attacking quotas before filling shortages by ranking
the remaining eligible Players. Clubs without persisted rosters continue to
use aggregate Club strength and do not receive synthetic Players. An available
Player who is unused or not selected creates no performance evaluation;
expectations are based on actual Match appearances.

Completed Match evidence produces a bounded Player evaluation. Club
expectations and role transitions are evaluated after Match persistence, using
two-match evidence before a normal role change. Role opportunities are durable
read/action records; transfer-interest signals, when generated later, never
execute a Transfer automatically.

## DOMAIN-009 Availability Boundary

Match minutes and explicit training blocks add bounded fatigue to real
Players. Fatigue recovers lazily from SimulationDate at a fixed deterministic
rate, so ordinary weekly play is sustainable while congested dates reduce
selection readiness. Availability augments, but does not replace, Contract,
registration, and squad eligibility and resolves to available, limited, or
unavailable.

Match workload can produce a bounded deterministic Injury exposure whose
severity supplies an expected recovery date. An active Injury blocks selection
until that date; historical Injuries remain structured Player records. Normal
training supplies no development stimulus while unavailable and no medical
staff, rehabilitation system, or wall-clock update is introduced.

The Match Module remains responsible for participation/minutes. Selection
consumes the Player availability assessment, and load/injury application is
idempotent by stable Match/Player source key. Clubs without persisted rosters
continue to use aggregate strength and receive no synthetic availability
records.

## DOMAIN-010 Population Boundary

New viable Big-5 career saves explicitly populate each participating Club
with a deterministic 25-Player senior squad. Generated Players use the same
identity, attributes, potential, development, Contract, registration,
availability, selection, and Match systems as the controlled Player. The
generator uses the World seed, Club ID, ordinal, and version; it does not
create static real-world Player content or a second NPC entity model.

The population phase supplies broad positional coverage and bounded Club
reputation-based ability variation. Reserves, youth academies, retirement,
transfer-market AI, and exact licensed rosters remain deferred. Sparse legacy
saves retain the aggregate Club-strength fallback until explicitly populated.

## PHASE 2 Player-Centric Simulation Fidelity

The controlled Player's experienced career path receives the highest Match
simulation and persistence fidelity. NPC-only world Matches remain real,
deterministic football driven by the same Club strength, eligible squad,
home-advantage, score, scorer, assist, discipline, and competition-result
rules, but retain only the evidence that has a gameplay or historical
consumer.

Controlled Matches keep complete Matchday selections, substitutions, Player
stat lines, highlights, ratings, form inputs, development idempotency, and
career evaluation evidence. NPC-only Matches keep authoritative results and
decisive events, current availability/development consequences, discipline
facts, and compact Season Player aggregates. Detailed NPC action evidence is
not a player-facing requirement and is not persisted when no downstream
consumer needs it.

This is a detail and persistence boundary inside one Match architecture, not
a second NPC engine. Stable deterministic namespaces ensure omitting unused
world evidence cannot perturb controlled-player outcomes.

The permanent rules are:

- No computation without a gameplay consumer.
- No persistence without a historical or future consumer.

These rules still permit cheap transient computation where it is needed to
produce a believable canonical world result.
