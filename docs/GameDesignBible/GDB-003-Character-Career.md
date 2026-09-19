# GOAL: Legacy
# Game Design Bible

Document ID: GDB-003
Title: Character and Career

---

# DOMAIN-004 Phase 1

A career controls one persistent Player record through a stable Career Player
reference. The reference stores Career ID, Player ID, and the deterministic
career start date. It never embeds a second copy of the Player.

Player creation is explicit and deterministic. The creation request supplies
identity, birth date, Nation references, physical profile, primary position,
potential, development profile, and either headline attributes or an
explicit seed for the compact deterministic generator.

Phase 1 supports the development profiles `late_bloomer`, `regular`, and
`prodigy` through the Player development service. Training is represented by
an explicit deterministic focus block; Match appearances provide a separate
experience stimulus. Age is derived from Player birth date and SimulationDate,
and progression slows near the Player-owned potential ceiling. The profiles
shift the age curve: late bloomers develop later, regular Players develop
steadily, and prodigies develop earlier without a guaranteed ceiling outcome.
Development state and immutable history are Career data. Weekly scheduling,
morale, injuries, contracts, transfers, and international selection remain
outside this milestone.

A starting Club assignment is an explicit season-bound squad relationship.
The relationship is not a Contract and does not imply financial or transfer
state. The youth-camp experience remains deferred because no canonical
initialization flow is defined yet.

Player is the Phase 1 human football entity. A generic Person abstraction is
deferred until Staff or another domain requires a shared canonical record.

When the controlled Player is season-registered and in a Club squad, Match
simulation uses the same eligibility path as every other Player. Participation
and Match stat lines reference the existing Player ID; career summaries do not
duplicate Player state.

Phase 1 creates career Players through the PlayerCreationService rather than
loading a real-world Player content package. Declarative player content can
be added later without changing the persistent Player ownership boundary.

## DOMAIN-007 Career Progression

Player attributes, potential, and development profile remain canonical Player
state. Training blocks and Match completion feed one authoritative
PlayerDevelopmentService. A stable source key makes training and Match
stimulus idempotent across retries and reloads. Match Player statistics remain
owned by Match; season and career appearances, starts, minutes, and goals are
derived read models over those records. Transfer does not reset development.

Phase 1 does not use Club facilities, coaches, or a daily per-Player tick.
DOMAIN-009 adds bounded fatigue and Injury availability, while DOMAIN-014
adds a once-per-Season age lifecycle source to the same development history.
Young/profile timing remains bounded by potential; prime Players stabilize and
older Players receive deterministic attribute decline. Development still runs
at explicit training, Match, and Season-boundary lifecycle checkpoints.

## DOMAIN-008 Selection Pressure

Squad role belongs to the season-bound Club–Player relationship and is one of
`prospect`, `rotation`, `regular`, or `key_player`. Selection uses the same
contract, registration, and squad eligibility rules as Match simulation. A
Player can be a starter, bench selection, or not selected; role is evidence
for selection, not a guarantee.

Performance evaluation uses only existing Match evidence. Recent form is a
rebuildable projection over actual appearances. Club expectations compare the
role's expected performance band with those evaluations. Two-match evidence is
required for ordinary role promotion or demotion, preventing one Match from
flapping a role.

Career opportunities are durable structured records referencing Player and
Club IDs. Phase 1 supports role and playing-time opportunities plus a bounded
transfer-offer path for the controlled career Player. Interest is evaluated at
an explicit monthly checkpoint across the Player's current competition, and
only a small deterministic shortlist is persisted as actionable offers.
Accepting an offer hands the agreed transaction to the existing Transfer
Service; declining or expiring one leaves the current Club state unchanged.
The controlled career Player receives no selection, ability, or market bonus.

## DOMAIN-009 Availability and Recovery

The controlled Player uses the same bounded availability path as every other
persisted Player. Match minutes and explicit training blocks create fatigue;
fatigue recovers from SimulationDate without a daily Player tick. Active
Injuries block selection until their deterministic recovery date and remain
visible as structured career history. Injury absence produces no synthetic
poor-performance evaluation or direct attribute penalty; its development
effect is indirect through missed training or Match minutes.

## DOMAIN-010 Generated Squad Boundary

The controlled career Player coexists with deterministic generated senior
Players in the same Club squad and uses the same selection, availability,
development, and Match paths. Generated Players are mutable save state, not
static licensed content, and the controlled Player receives no population
or selection bonus. The initial population phase does not include reserves,
youth academies, retirement, or autonomous NPC market behavior. Phase-1
career movement does not implement scouting, negotiation, Club budgets, loans,
or NPC-to-NPC market simulation; transfer fees and proposed wages are
structured offer metadata while Contract and Transfer records remain owned by
their existing modules.

## DOMAIN-013 Multi-Season Career Boundary

The World now completes the active Season only after its scheduled Matches
are complete, prepares one deterministic successor, and activates it at the
next Season start. Club-specific Contract renewal/release and senior-squad
replenishment happen at that boundary; the next Season receives independent
Competition memberships, registrations, and fixtures while prior history
remains queryable. A released Player is preserved as durable history but is
not silently registered without an active Contract.

This is continuity infrastructure rather than full Club management. The
Phase-1 world has stable league membership and bounded deterministic
renewal/replenishment. DOMAIN-014 adds Player-owned active/retired state,
season-boundary retirement, and true deterministic newgens to replace
retirements and restore senior-squad viability. Newgens enter as ordinary
young senior Players with existing positions, profiles, potential, Contracts,
roles, and registrations; this is not a youth academy. Promotion/relegation,
finance, and autonomous NPC transfer recruitment remain future systems. A
career transfer remains continuous because Player identity, development,
availability, and historical Matches survive while the new Club-scoped role
and seasonal registration are rebuilt.

## P2-016 Playing-Career Completion

The controlled Player's playing Career has a derived phase—youth,
development, prime, experienced, veteran, or decline—based on birth date and
the current SimulationDate. Phase is descriptive; it is not a second rating
and does not force a role or an age cliff. The existing PlayerDevelopmentService
remains the sole owner of attribute growth and bounded age pressure.

At a Season boundary, age and football context may open one controlled
retirement decision. A Player chooses Continue Playing or Retire when the
Career is genuinely eligible; expiring/free-agent Contract decisions and
other open Career decisions retain precedence so the boundary cannot present
contradictory choices. A forced maximum playing age is a safety boundary,
not the normal retirement rule. Retirement closes playing eligibility,
terminates the active playing Contract, preserves assets and history, and
does not create a manager or post-playing Career.

Retirement is resolved after completed-Season Legacy work and before evidence
needed for the summary is compacted. The controlled Player receives one
compact retirement record, one Career History landmark, and one factual
Echo/Pulse source. NPCs retain the existing bounded retirement/newgen path;
they receive no retirement summaries, ceremonies, follower state, or
post-Career simulation.

## P2-005 Controlled Player Finance and Lifestyle

Phase 1 deliberately deferred personal earnings and spending. Phase 2
P2-005 activates that layer for the controlled career Player only. Contract
wage remains a Contract-owned weekly term; `PlayerFinanceService` consumes
that term as elapsed-calendar payroll and records every balance change in a
small controlled-Player ledger. The accounting display uses normalized `GC`
units, with a modest deterministic opening balance for new Careers.

Legacy Careers initialize at feature activation without historical wage
backfill. Payroll is keyed by Contract and weekly period, so repeated
Continue, reload, and refresh operations cannot pay the same period twice.
Transfers, renewals, expiry, free agency, and new signings continue to be
owned by the Contract and Transfer modules; finance consumes their canonical
date-bounded state.

P2-005 adds a bounded generic lifestyle catalog and persistent controlled-
Player ownership. Purchases are optional, atomic, server-priced, and recorded
as ledger transactions. Their declarative effects provide small contextual
signals for career presentation and events; they never directly change
attributes, OVR, potential, or Match outcomes. NPC Players have no personal
finance rows, payroll, transactions, or lifestyle ownership.

---

## Player-Facing Information Boundary

The graphical career lets a Player explore the football world through
competition standings, Clubs, squads, and public Player profiles. Profiles
show recognizable identity and canonical current-Season facts such as
appearances, minutes, goals, assists, discipline, and form where the world
retains those aggregates. They do not reveal potential, hidden simulation
weights, or other internal development information for NPCs. This is an
information view over existing world data, not a scouting system.

## P2-006 Financial Progression

The controlled Player's financial context develops with the football career.
The opening career is deliberately modest; future catalog prices use the
existing wage model so early choices matter while established and elite
Players can reach comfort and luxury through football success. The derived
states are Starting out, Stable, Comfortable, Wealthy, and Elite. They are
context for presentation and event selection, not a hidden attribute or a
second progression engine.

Permanent lifestyle categories have one active choice per category while
older purchases remain historical ownership. Experiences are recorded as
completed moments and are not active equipment. Upgrades never change
attributes, OVR, potential, Match outcomes, or development ownership.
Routine living costs are intentionally deferred: one predictable periodic
expense would add bookkeeping without a stronger football decision in the
current game.

P2-006 adds asset-aware, transfer-aware, free-agent-aware, and Contract-
boundary event context. Monetary event effects still route through the
controlled Player finance ledger, use stable source identities, and are
replay-safe. Contract wages remain the canonical source of income.

## P2-011 Career Coherence

Phase 2 features are presented as one controlled-player Career. Youth Camp
initializes the same social context used after later Club starts, so the first
Career Home has a real Club and manager context before a Match occurs. The
active Contract remains authoritative for current Club presentation; former
Club squad rows are historical and do not make a free agent appear employed.

Career Home has one primary action. Pending decisions and Career Events must
be resolved before time advances; otherwise the action is an explicitly
labelled continuation toward the next fixture or Career checkpoint. The
current availability state, including an active injury recovery date, is
shown beside training, priority, Contract, outlook, and finance context.

Season summaries remain concise but include existing domestic Cup, European,
and international outcomes/statistics when those records exist. No new
simulation or persistence subsystem is introduced: Match, development,
competition, finance, social, and history owners remain unchanged.

## P2-013 Transfer Market Stature

Transfer interest is derived football context, not a second OVR or an
achievement currency. OVR, age, bounded youth potential, canonical minutes,
form, role, public recognition, honours, and international aggregates inform
human-readable market stature. Buying Clubs use existing reputation,
competition tier, European membership, capacity, and positional need. Up to
three deterministic offers present credible upward, sideways, or opportunity
moves with bounded wage, duration, and projected role. NPCs receive no market
stature rows, detailed scouting, finance simulation, or offer-history
expansion.

## P2-017 Training and Player Readiness

The controlled Player's readiness is a projection of the existing bounded
fatigue/load state and active Injury records. There is no second fitness,
energy, or match-sharpness meter. Match minutes and between-Match training
blocks add workload; SimulationDate-based recovery removes it lazily, so
opening a page never advances recovery.

The existing Career priority supplies a small training-intensity policy:
Development uses an intense block, Recovery and Lifestyle use a light block,
and Professional/Balanced use a normal block. Intensity changes workload and
bounded development stimulus through the existing Player Development Service;
it does not create attribute farming or replace training focus. Active Injury
remains a hard selection gate, and selection continues to consume
PlayerAvailabilityService rather than a second lineup system.

Intense training has a modest deterministic Injury exposure when workload is
already high. Match and training sources are keyed for idempotency, injuries
carry a bounded expected recovery date, and recovery/return events use the
existing social/Pulse path. NPCs receive no detailed readiness history or
training-choice simulation.

## P2-018 Manager Trust and Squad Competition

The controlled Player's football standing with the manager is a derived
selection context, not a second OVR, form score, role, reputation, or
guaranteed-selection meter. The existing Club-scoped manager relationship
remains the interpersonal owner. A read-only football-trust label combines
that relationship with bounded recent form, canonical minutes, role,
availability, discipline, and positional competition.

Squad competition is derived from the current Club's same-position Players;
only a deterministic top one-to-three competitor summary is exposed. Role
expectations are simple guidance: Prospect limited developmental minutes,
Rotation regular rotation opportunities, Regular frequent meaningful
minutes, and Key Player a strong starting expectation. Selection remains
owned by MatchSelectionService; trust can only provide a small controlled-
Player tie-break influence after OVR, role, form, position, and readiness.

Sustained below-expectation minutes can produce one existing Career Event
conversation per Season. Its choices route through FootballSocialService and
Career priority/development owners; they cannot change attributes, OVR,
potential, Match outcomes, or guarantee selection. No weekly trust snapshots,
NPC trust rows, manager personalities, or social-manager simulation exist.

END OF DOCUMENT
