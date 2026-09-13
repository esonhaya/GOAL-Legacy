
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
