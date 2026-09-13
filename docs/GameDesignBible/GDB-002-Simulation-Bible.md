
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
projection. Interactive controls, tactics, substitutions, injuries, weather,
and minute-by-minute simulation remain deferred.

## DOMAIN-008 Career Pressure Boundary

Before simulation, the Match Module reuses the existing eligibility path and
deterministically ranks real eligible squad Players using OVR, Club-scoped
squad role, recent derived form, and stable Match/Player tie-breaking. Up to
eleven become starters, the next seven are bench selections, and remaining
eligible Players are not selected. Clubs without persisted rosters continue to
use aggregate Club strength and do not receive synthetic Players.

Completed Match evidence produces a bounded Player evaluation. Club
expectations and role transitions are evaluated after Match persistence, using
two-match evidence before a normal role change. Role opportunities are durable
read/action records; transfer-interest signals, when generated later, never
execute a Transfer automatically.
