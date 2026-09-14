# Club Recruitment and Free-Agent Continuity

DOMAIN-015 adds bounded Club-controlled recruitment at Season
materialization. `ClubRecruitmentService` is the single owner of squad
maintenance decisions; Season rollover invokes it after Contract
renewal/release and before newgen fallback.

Recruitment is checkpoint-driven, deterministic, and need-driven. It does
not run after Matches or on a daily clock. A Club first evaluates broad
position coverage (goalkeeper, defense, midfield, attack), then senior
squad headcount toward the 25-player target. Candidate ranking uses
position fit, OVR, potential, age, and Club reputation.

An active free Player is an ordinary active Player with no active Contract
and no current-Season squad membership. Retired Players and controlled
career Players are excluded. Suitable free Players are signed before any
newgen is generated. Signing reuses Contract, squad membership, role, and
Competition registration services and preserves the Player ID and history.

If a free Player cannot satisfy a need, a small deterministic contracted
NPC candidate may move between Clubs through the existing TransferService.
The source Club must retain playable headcount and broad positional
coverage. Transfers are sparse and capped per checkpoint. There is no
finance ledger, negotiation, scouting, agent, or global market tick.

Unresolved vacancies continue to use the DOMAIN-014 newgen generator;
emergency replenishment remains the final invariant-repair fallback.
Recruitment identifiers include Season, Club, and Player context so a
checkpoint retry does not duplicate Contracts, memberships, registrations,
or transfers.

Recruitment reports expose free-agent signings, NPC transfers, position
needs met, and newgens avoided. Use `recruitment:self-check` for the
isolated free-agent and production Match-selection path. The lifecycle
audit reports recruitment counts per Season.
