# GOAL Developer Console and Sandbox

The web console uses the same `SimulationToolkit` and `GoalSimulationAdapter`
as the CLI and tests.

- `?page=developer&save=<id>` is Developer-only and shows curated state,
  capability metadata, and cheap GOAL diagnostics.
- `?page=sandbox&save=<id>` is available to Premium Sandbox or Developer
  sessions and exposes bounded Player attribute, potential, role, balance, and
  time controls.
- Every state change is POST-only, token-protected, server-authorized,
  ownership-checked, range-validated, save-scoped, and written to a bounded
  mutation audit table.
- A save can be cloned before experimentation. A single/few bounded snapshot
  is maintained for restore. The source save is not overwritten by cloning.

The local web shell reads `haya_role`, `haya_premium_sandbox`, and
`account_id` from the server-side session. Production authentication and
entitlement services can populate those values later; payment logic is not
part of the simulation toolkit. Query parameters and posted capability names
never grant access.

No raw SQL, PHP, shell, filesystem, arbitrary repository, or source-file
operation is available from the web console. Long multi-Season runs remain a
CLI/developer operation and are not executed synchronously by normal web
requests.
