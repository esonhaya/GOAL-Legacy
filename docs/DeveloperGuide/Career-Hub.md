# Career Hub

The Career Hub is the player-facing read model for a controlled career. It is
provided by `PlayerCareerProgressionQuery` and is consumed through the existing
career/application presentation surface; it does not introduce a second UI or
history store.

## Authoritative sources

- Player identity, attributes, OVR, potential, age, and career state come from
  the Player domain.
- Current Club and Contract come from the active Contract and Club squad
  membership. Competition and tier come from that Season's canonical Club
  membership.
- Season rows combine canonical squad membership with Match statistics and
  `PlayerSeasonPerformanceService` assessments. Statistics not produced by the
  Match path, such as assists or ratings, are omitted.
- Contract history comes from Contract records. Completed transfer history
  comes from Transfer records. Promotion/relegation entries are derived only
  when the same Club's historical Competition tier changes.
- Development history comes from PlayerDevelopment's existing history.
- Current role and role history come from the Club squad role owner; role is
  therefore Club/Season contextual rather than a Player-global rating.
- Pending decisions and their options come from `CareerOpportunity` records.
  Existing career actions remain the execution path.

## Read-model behavior

The current snapshot is resolved from the active Contract, so an out-of-
Contract Player is represented with no current Club, Competition, role, or
active Contract while retaining identity and historical rows. Season history
is ordered by Season and Club. Movement history is ordered by effective date
and reports actual completed transfers separately from Club tier changes.

The read model is reconstructed after save/reload; it is not denormalized into
the save. Open opportunities are filtered against the requested read date,
and available actions are exposed as references to existing career commands
(`request_transfer`, `withdraw_transfer_request`, and opportunity resolution).

No wages, transfer valuations, unsupported Match statistics, awards, or
narrative events are inferred. Free-agent periods remain valid career state,
and a tier change is not presented as a Player transfer.
