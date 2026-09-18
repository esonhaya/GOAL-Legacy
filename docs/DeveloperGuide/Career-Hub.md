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

## P2-011 coherence rules

The graphical Career Home is an action boundary as well as a read model. A
pending Contract/transfer decision or Career Event is the primary action and
must be resolved before Continue can advance time. When no blocking choice is
pending, the primary action is labelled `Continue Career` or `Continue to
next fixture` so the immediate progression is legible.

Youth Camp uses an atomic placement write, then calls the canonical controlled
Player social initializer. This prevents a new career from showing the lazy
default social state. Conversely, the controlled Player profile filters
historical squad memberships through the active Contract: free agency shows
Free Agent, no current role, and no current Club link while preserving old
Career and relationship history.

Availability is a first-class Career Home situation line. An active injury
shows its recovery date; limited availability is shown without inventing a
medical forecast. The read remains side-effect free.

Season-end presentation projects existing competition facts into a compact
summary: per-competition controlled Player evidence, domestic Cup/European
outcome, and current-season international totals. It does not snapshot
brackets or create duplicate history.
