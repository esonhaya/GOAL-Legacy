# GOAL: Legacy
# International Football

Document ID: GDB-007
Title: Phase 2 International Football
Status: Active Phase 2 scope

## Player experience

Nationality now has a sporting consequence. A controlled Player may earn a
senior call-up, move between Club and country during an international window,
record caps and goals, and reach a generic World Championship. Club salary,
training, form, availability, and the domestic and European calendars remain
the existing systems.

## V1 world boundary

National teams are materialized only for the seven selected nations currently
supported by the world content: England, France, Germany, Italy, Scotland,
Spain, and Wales. A usable team requires at least 18 active Players with that
primary nationality; the normal squad is 23 Players with bounded goalkeeper,
defender, midfielder, and attacker coverage. No Players are fabricated to
represent unsupported nations. Dual nationality, naturalization, youth teams,
women's teams, and national-team management are out of scope.

Selection is a deterministic window decision using overall quality, recent
canonical form, Club role, position need, and availability. Selection is not
automatic for the controlled Player, and selected Players are not guaranteed
to start. A Player has one national team determined by nationality.

## World Championship

The V1 generic World Championship starts immediately in the 2024/25 world
cycle and repeats every four Seasons. The initial field is a deterministic
strength seed; later fields use completed domestic results and stable strength
fallbacks. With the current seven-team world, six national teams enter two
groups of three. Each group is a single round robin, the top two advance to
single-leg semi-finals, and the winners play a single-leg final: six group
Matches plus three knockout Matches per cycle. AET and penalties reuse the
domestic/European knockout resolver. No FIFA or federation branding is used.

## Fidelity and consequences

An international Match involving the controlled Player uses Player fidelity.
All other international Matches use World fidelity and do not create detailed
NPC selection, evaluation, or Match-player evidence. International statistics
are separate from Club League, Cup, and European statistics. Canonical form,
development, injury, and competition-scoped discipline consume applicable
Match facts; no international multiplier, wages, bonuses, or prize money is
added.

Call-up, debut, goal, tournament selection, advancement, elimination, final,
and championship moments may create selective News, Career Events, and Career
History entries. Routine NPC fixtures do not become narrative spam.

## Boundaries

This milestone does not add global qualifying, continental national
championships, Nations League-style play, international retirement, or new
football mechanics. Future expansion may reuse the group-to-knockout format,
canonical Match engine, and competition history without changing Club
competition ownership.

END OF DOCUMENT
