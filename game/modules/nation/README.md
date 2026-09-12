# Nation Module

The Nation Module owns the first persistent football-domain vertical slice.
It validates declarative Nation content, constructs immutable Nation records,
and materializes them through a NationRepository into the current save
database.

It does not own clubs, players, matches, competitions, or World state. Those
domains refer to Nation IDs through their own documented boundaries.
