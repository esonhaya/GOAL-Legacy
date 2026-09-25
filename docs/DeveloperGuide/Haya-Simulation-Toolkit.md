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

P3-010 adds Contract and movement scenarios to the same catalogue:
`CONTRACT_EXPIRING`, `CONTRACT_LONG_TERM`, `FREE_AGENT`,
`TRANSFER_REQUESTED`, `LOAN_ACTIVE`, `POST_LOAN_RETURN`, and
`PERMANENT_TRANSFER`. They compose with the existing Player, availability, and
squad setup. Contract edits use `ContractService`; movement and loan changes
use `TransferService`/`CareerMovementService`. No Contract or movement engine
exists in the toolkit.

For routine validation, use the public entry point that matches the question:
one Match for participation, `GoalMatchRunner::many()` for bounded accumulation,
the canonical Season runner for rollover, and the Career observatory only for
multi-Season pacing. Measure the operation once locally rather than rebuilding
state or writing a custom loop. Contract and movement checkpoint projections
are curated so a state diff shows Club, Contract, loan, and request changes.

Reference local P3-010 timings (Android Termux, deterministic
`HEALTHY_LOW_MINUTES`, seed 3010): scenario build about 28.5s, one Match about
3.8s, ten Matches about 25.5s, cheap integrity Doctor about 28ms, and an empty
curated checkpoint diff about 6ms. These are guidance, not performance
guarantees; use a fresh Bootstrap clock for separate isolated timing runs.

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
