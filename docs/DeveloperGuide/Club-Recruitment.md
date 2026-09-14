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
coverage. DOMAIN-016 makes this movement window-aware and need-driven:
structural vacancies are considered first. The world budget is derived from
unresolved vacancies (approximately one ninth enters the autonomous window)
and has a hard safety ceiling of 18; each Club may participate in at most
one contracted movement in a window. Full squads are not enlarged for a
quality upgrade; one-in/one-out upgrades remain deferred until a canonical
destination release transaction is added.
Candidate pressure uses role, depth, OVR/potential, age, and previous-Season
minutes/appearances when available. Recently moved Players are excluded.

The current transfer window is the pre-Season materialization checkpoint.
There is no daily market tick or mid-season window. NPC movement can cross
the installed Big-5 Competitions because existing TransferService and
registration paths support it. Player willingness is a deterministic role
and level-fit policy; source Clubs are protected from losing critical
coverage. There is no finance ledger, negotiation, scouting, agent, or
manager-personality system.

Unresolved vacancies continue to use the DOMAIN-014 newgen generator;
emergency replenishment remains the final invariant-repair fallback.
Recruitment identifiers include Season, Club, and Player context so a
checkpoint retry does not duplicate Contracts, memberships, registrations,
or transfers.

Unchanged Contract renewal/release policy runs before this window. Released
active Players therefore feed the same free-agent pool, while newgens
remain the generational fallback for unresolved vacancies. Recruitment
identifiers and the completed-transfer window guard make retries safe and
prevent a Player from moving twice in one window.

Recruitment reports expose free-agent signings, NPC transfers, dynamic
movement budget, candidate evaluations, Club activity, position needs met,
and newgens avoided. Use `recruitment:self-check` for the isolated
free-agent and production Match-selection path. The lifecycle audit reports
renewals, releases, movement distribution, and recruitment counts per
Season.
