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

---

## Player-Facing Information Boundary

The graphical career lets a Player explore the football world through
competition standings, Clubs, squads, and public Player profiles. Profiles
show recognizable identity and canonical current-Season facts such as
appearances, minutes, goals, assists, discipline, and form where the world
retains those aggregates. They do not reveal potential, hidden simulation
weights, or other internal development information for NPCs. This is an
information view over existing world data, not a scouting system.

END OF DOCUMENT
