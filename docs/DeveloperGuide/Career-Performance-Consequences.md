# Career Performance Consequences

DOMAIN-022 derives one deterministic assessment for a completed Season from
the existing Match path. `match_player_stats` is authoritative for
appearances, starts, minutes, goals, and (from DOMAIN-027) assists; ratings,
clean sheets, saves, and position-specific contribution metrics are not
stored and are therefore not inferred.

The assessment normalizes participation against completed Matches played by
the Player's Club. Its classifications are `breakout`, `strong`, `steady`,
`limited`, `stagnant`, and `insufficient_evidence`. Minutes and starts carry
the main weight, with goals as a bounded universal contribution signal. OVR,
age, and position provide context elsewhere but cannot create a breakout
without authoritative participation.

The Player domain owns `PlayerSeasonPerformanceService`. It derives the
assessment from immutable Match/stat history, so it is stable after save and
reload and does not create a second statistics store. The career summary
exposes the selected Season assessment and evidence.

Performance is a bounded input to existing decisions:

- strong/breakout evidence can strengthen the existing Contract renewal
  policy and is included in controlled Contract decision context;
- strong/breakout evidence modestly improves existing controlled transfer
  interest ranking when Club fit, capacity, and source safety already pass.

No result directly scripts an OVR increase, starter assignment, renewal,
transfer, or offer. NPC lifecycle policy remains owned by Season rollover;
controlled Players retain DOMAIN-019/020 choice semantics. Historical Match
statistics remain attached to their original Season and Club context after a
transfer.

DOMAIN-027 extends the same Match-stat owner with deterministic scorer and
single-assist attribution. Goal attribution uses active selected Players at
the goal minute, with bounded position/attribute weights; an assist is
optional, belongs to a different active teammate, and is persisted as zero
for historical rows without the column. The new assist aggregate is factual
data only in this phase: the performance classification above deliberately
continues to use its existing participation/goals inputs, and Career Hub
presentation may omit assists until its read model is expanded.

At the Season boundary, the existing `ClubExpectationService` and
`ClubSquadRepository` apply one bounded role consequence to the next-season
squad membership. Breakout/strong performance may advance one existing role
tier when same-position Club competition permits it; limited/stagnant
performance may demote one tier. Steady and insufficient evidence preserve
the role. `MatchSelectionService` continues to combine the role weight with
OVR, position, form, and availability, so a role change changes opportunity
without guaranteeing selection. Promotion, relegation, and transfer use the
destination/current Club context and do not copy a global Player role.

Role changes are persisted through the existing season squad membership and
role-history records. No second role/depth system is introduced.

The existing `PlayerDevelopmentService` remains the sole development and
attribute owner. Match participation already accumulates development progress
through that service; at the same Season boundary, the completed assessment
adds a small balanced progress stimulus to the same buffer. The policy is
`breakout` > `strong` > `steady`; limited, stagnant, and insufficient evidence
are neutral. The stimulus is bounded, scaled by the existing age curve and
potential gap, and consumed only by the existing potential-safe attribute
point application. It does not assign OVR, attributes, potential, or a role
directly, and it does not create a second development pass.

Season lifecycle development remains idempotent under the existing
`season_lifecycle` source key. The assessment is reconstructed from completed
Season Match statistics before the lifecycle pass, while the resulting
attribute deltas and OVR history remain in the existing development history.
Rollover retry therefore cannot apply the same performance context twice;
save/reload preserves the result. Newgens, retirement, and NPC Players use the
same rule because the change is in the canonical Player development owner.
Training, XP, dynamic potential, coaches, facilities, attribute selection,
and development plans remain deferred.

Training choices, injuries, fatigue, morale, wages, awards, position-specific
metrics, dynamic Club reputation, and richer narrative performance history
remain deferred.
