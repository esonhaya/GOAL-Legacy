# Haya Simulation Toolkit

P3-008 establishes one game-neutral control boundary for tests, the developer
CLI, Haya Doctor, and the web console. `GameSimulationAdapter` exposes
capability descriptors, curated inspection, bounded scenarios, checkpoints,
diagnostics, and validated mutations. `SimulationToolkit` performs the common
capability checks and delegates gameplay to the adapter.

GOAL implements `GoalSimulationAdapter`; the shared layer does not know about
Players, Clubs, Matches, Contracts, or football formulas. The adapter delegates
to canonical GOAL services. A future Haya game can register a different adapter
with periods, entities, and state projections appropriate to that game.

Useful CLI commands:

```text
php game/devtools/console.php haya:simulation capabilities
php game/devtools/console.php haya:simulation inspect --save=<id>
php game/devtools/console.php haya:simulation diagnostics --save=<id>
php game/devtools/console.php haya:simulation scenarios
php game/devtools/console.php haya:simulation match --save=<id> --count=1
php game/devtools/console.php haya:simulation scenario --scenario=HEALTHY_LOW_MINUTES --seed=3009
php game/devtools/console.php haya:simulation run --seasons=1 --seed=13008 --archetype=regular
```

The GOAL run delegates to the existing bounded Career observatory. Checkpoints
are derived output and are not persisted in ordinary saves. `GoalScenarioCatalog`
and `GoalScenarioBuilder` provide canonical, isolated fixtures such as
`HIGH_OVR_STRONG_COMPETITION`, `INJURY_LOW_MINUTES`, and `PRODIGY_CONTROL`.
`GoalMatchRunner` provides one-to-ten Match execution through WorldService and
MatchService; it is not a second Match loop. `SimulationStateDiff` supplies a
bounded before/after comparison for tests, Doctor, and the Lab.

Standing workflow: search the scenario library before manually creating test
state; use the canonical runner before writing a Match or Season loop; use
checkpoint/diagnostic APIs before querying state manually; use the isolated
SaveStore fixture before creating a temporary save. Use the lowest validation
tier that proves a change: micro adapter tests, bounded integration scenarios,
then the slower longitudinal observatory.

Permission tiers are `PLAYER`, `PREMIUM_SANDBOX`, `DEVELOPER`, and
`SYSTEM_TEST`. Premium mutations are save-scoped, ownership-checked, audited,
and mark the save `SANDBOX`; a bounded pre-mutation snapshot is retained.
Developer-only diagnostics are not exposed by Premium access.
