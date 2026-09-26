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

## P2-012 Career Legacy

The Career Legacy page is a read-only synthesis of canonical football facts.
It exposes Club and international totals, represented Clubs, participation-
based honours, completed-Season awards, personal bests, and idempotent numeric
milestones. It does not create a second statistics owner, an achievement
currency, or a synthetic Legacy score.

Awards are resolved once at the completed-Season boundary, before compaction.
Domestic-League awards use retained controlled-Player evidence or compact
World-fidelity competition aggregates. NPC winners are valid; NPC detailed
Match evidence is never generated. A controlled Player receives a League,
Cup, European, or World Championship honour only with the documented
registration/participation evidence, including at least one appearance/cap.

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

## P3-011 Career Home consolidation

`CareerPresentationService::careerHome()` projects the player-facing Home
context from the existing snapshot. It provides the header, deterministic
`NEXT UP` priority, availability and playing-status explanation, bounded
`NEEDS ATTENTION` items, contract/loan context, quick links, and at most five
recent story items. The web controller owns only HTML composition; it does not
create a second decision queue.

Priority is presentation-only: required decisions first, then recovery,
then the next scheduled fixture, with informational continuation as the
fallback. Contract, movement, training, retirement, and Season Review links
still resolve through their existing owners and stale-action validation.
Retired Careers receive a read-only Career Complete Home with Legacy, Trophy
Room, and Season Review navigation; free agents receive a transfer-market next
step; loaned Players show the playing Club separately from the parent Contract.
Recent story is bounded and sourced from the existing Career/news projection.
Home, History, Season Review, Trophy Room, and Profile remain observational;
the projection performs no gameplay DML, World scan, or full-history rebuild.

Season-end presentation projects existing competition facts into a compact
summary: per-competition controlled Player evidence, domestic Cup/European
outcome, and current-season international totals. It does not snapshot
brackets or create duplicate history.

## P3-012 decision context and outcomes

Pending Contract, transfer, loan, and retirement choices are projected by
`CareerPresentationService::decisionChoiceContext()`. The decision page shows
the current Club/competition/role and Contract facts, then separates known
effects from possible later effects. A proposed role or destination is never
presented as a guarantee of selection or playing time. Loan context keeps the
playing Club distinct from the parent Contract Club.

Execution remains with `CareerMovementService` and `PlayerLifecycleService`.
The web action only turns their resolved `CareerOpportunity` into factual
outcome copy after the canonical mutation succeeds. One-time POST tokens,
stale opportunity checks, and redirect-after-POST remain authoritative.

Significant resolved opportunities are reused as bounded `decision_history`
read data. Resolution date metadata is stored in the existing opportunity
context by the domain owner; no second history ledger is created. Career
History shows these choices, while Career Home may surface only the most
useful outcome in its bounded Recent Story. Routine clicks and God Mode audit
entries remain outside this player-facing history.

## P3-013 Career variety boundary

Career event variety follows the same canonical snapshot already consumed by
Career Home: role, availability, participation, form, Contract, movement,
competition context, Club context, finance, and lifecycle phase. A later-phase
event is eligible only when the existing lifecycle owner reports `veteran` or
`decline`; it does not assume that a veteran is declining or promise a future
role. Loan, free-agent, injury, transfer, Contract, and playing-time moments
remain state-specific and do not create a second decision queue.

`CareerExperienceService::auditEligibility()` is a development-only,
read-only sampler. It reports eligible definitions, rejection reasons, recent
suppression, the bounded selection pool, and the deterministic selected item
by calling the production eligibility/ranking path. `CareerEventCatalog::validate()`
checks authored IDs, families, copy, choices, canonical focus/priority
references, requirement keys, and semantic duplicates. Neither diagnostic is
called by normal Career Home rendering. Resolved choices continue to use the
existing Career event record, canonical training/priority/finance/social
owners, and bounded Recent Story/Career History projections; routine event
cards are not additional history.

## P3-014 Matchday continuity

Career Home Continue now stops at the existing `matchday` route for the next
scheduled controlled fixture. `CareerPresentationService::preMatch()` projects
fixture, competition/stage, home/away, canonical availability, readiness,
role, recent form, and existing rivalry/Season-stakes facts. It does not select
a squad, consume Match randomness, or promise Starting XI, bench, deployed
position, minutes, or performance; those facts are confirmed only by canonical
Match execution.

The pre-Match POST is a one-use fixture-scoped action. It validates that the
fixture is still the next controlled scheduled Match, then delegates to
`CareerContinueCommand` and `MatchService::simulateDue()`. Completed Match
selection, substitutions, deployment, statistics, rating, availability,
discipline, development, form, milestones, and competition consequences keep
existing owners. Stale/replayed POST cannot simulate twice.

Completed Matchday reads use `MatchStoryService` and persisted facts. The web
projection prioritises controlled-player moments and filters headline stats by
actual deployed position; unused, not-selected, injured, and suspended states
never receive fabricated ratings. GET/render of pre-Match, Matchday, post-Match,
and Career Home remains observational and does not write gameplay rows.

## P3-015 progression feedback

`CareerPresentationService::progressionContext()` is the bounded projection
for current player development feedback. It reuses canonical Player
attributes/OVR, `PlayerDevelopmentRepository` history, training focus,
playing-time/performance evidence, role history, position history, lifecycle
phase, and Career Outlook. Career Home, the controlled Player Profile, and
Training consume this projection; Season Review remains the Season-scoped
review owner.

Development history retains exact OVR before/after values and attribute
deltas. The presentation shows only recorded non-zero changes and never
invents historical attribute from-values, XP, progress bars, or a hidden
progression score. Career stage is a derived lifecycle description separate
from Career Outlook, and normal Career UI does not expose potential.

Role and development execution remain owned by existing domain services.
Profile, Training, Home, and Season Review reads are observational and do not
create gameplay rows or snapshots. Significant role/position changes may be
surfaced through retained history; routine attribute deltas remain transient
development evidence.

## P3-016 early Career journey gate

The production entry path is `new` identity → body → appearance → football
profile → review → Youth Camp → `select_club`. `WebCareerStartWorkflow`
creates the isolated save/world preview and `YouthCareerStartService::accept()`
owns the atomic Career start: one controlled Player reference, Club squad
membership, registration, active Contract, initial role, finance state, and
Career/social initialization. The web layer only validates the form and
redirects after the canonical write.

Creation inputs use the repository's position and archetype identifiers and
are rejected before a save can be created when invalid. The normal review
surface shows starting OVR and factual identity, but keeps potential out of
the player-facing boundary. Archetype copy describes tendencies without
promising selection, role, development, or future outcomes. One-use form
tokens protect the creation steps, Youth Camp entry, and the early Training,
position, priority, and on-pitch-role actions; replayed or tampered POSTs do
not create duplicate saves or Players.

The first Home is a projection of the initialized save and may legitimately
show empty Career History, Recent Story, movement, Trophy Room, and retained
development sections. It does not invent a Match, appearance, milestone, or
progress claim. Training, Matchday, post-Match, Profile, and History continue
to read the existing canonical owners and are safe to refresh; POST actions
redirect and subsequent Home reads recalculate current Club, Contract, role,
availability, Season facts, progression, and next fixture.

`tests/Support/CareerJourneyRunner` is a bounded test harness for the actual
`WebApplication` routes. It extracts rendered one-use tokens, invokes normal
production POSTs, and leaves creation, Match, training, persistence, and
development semantics to their canonical services. `P3016CareerJourneyTest`
uses fixed seed `31601`, a short CM/Regular journey, reload checkpoints, one
web Match, and two `GoalMatchRunner` fixtures; it is an integration gate, not
a second gameplay loop or a long-term balance simulation.
