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

Phase 1 does not use Club facilities, coaches, fatigue, decline, or a daily
per-Player tick. Development is processed at explicit training boundaries and
for real Player appearances in completed Matches.

---

END OF DOCUMENT
