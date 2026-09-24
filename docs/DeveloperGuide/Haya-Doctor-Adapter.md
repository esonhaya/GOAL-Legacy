# Haya Doctor Adapter Contract

`HayaSimulationCheck` is the thin Doctor frontend for the generic
`GameSimulationAdapter` contract. It performs cheap capability discovery and
does not launch a long Career audit on every Doctor run.

An adapter should provide:

1. a stable game identifier;
2. capability descriptors with read/write mode, scope, permission, schema, and
   cost/availability metadata;
3. curated read-only inspection;
4. bounded scenario execution and typed checkpoints;
5. structured diagnostics with PASS/WARN/FAIL/SKIPPED-style status;
6. optional safe mutations through canonical game owners.

GOAL-specific diagnostics currently include controlled-player playing-time
evidence, deployable-position competition, movement/Contract state, and
controlled-player integrity. They explain canonical outcomes and do not
reimplement selection or movement formulas.

The shared contract is intentionally suitable for a later Nation: Legacy
adapter: it can describe month periods, nation state, policy, and economy
without importing football concepts.
