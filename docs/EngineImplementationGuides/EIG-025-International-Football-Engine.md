# GOAL: Legacy
# International Football Engine

Document ID: EIG-025
Title: National Team and World Championship Engine
Status: Phase 2 implementation guide

## National teams

`NationalTeamService` materializes supported Nation identities and selects one
23-Player squad per Season from the real generated Player pool. The minimum
pool is 18. The deterministic score combines OVR, aggregate recent Match
form, Club role, and position coverage, then excludes unavailable Players and
uses a stable hash tie-break. Quotas target 2 goalkeepers, 7 defenders, 8
midfielders, and 6 attackers. Selection is persisted and therefore stable
through reload; it is not a controlled-Player privilege.

The selected squad supplies canonical lineup evidence and bounded national
team strength. The shared Match engine owns football outcomes. A selected
Player may be a starter, substitute, or unused; availability and Club/country
calendar collisions remain canonical.

## World Championship

`InternationalCompetitionService` owns a generic World Championship with two
groups of three in the current seven-country world. Each group has three
single-round-robin fixtures. The top two from each group enter two single-leg
semi-finals and a final. The budget is 9 World Championship Matches per
four-Season cycle. Draws use independent namespaced hashes. Initial entries
are strength seeded; subsequent entries use completed domestic results and
stable fallback. No fabricated countries or global qualification matches are
created.

Group tables use points, goal difference, goals scored, and stable team ID.
Knockout matches use the shared `KnockoutResolutionService`, including
regulation, extra time, and penalties. Shootout goals are presentation/state
facts, not normal goals.

## Career integration

Detailed controlled Matches flow through canonical Match statistics, form,
development, availability, discipline, News, Career Events, Career History,
Matchday, and post-Match presentation. International aggregates are separate
from Club aggregates. NPC Matches deliberately avoid detailed Player evidence.
National-team pages, Career Home, Player Profile, and World navigation derive
from persisted service state; no rendered bracket snapshot is stored.

## Test and performance boundary

Focused tests cover selection determinism, position coverage, World fidelity,
draws, groups, knockout completion, persistence, UI presentation, and legacy
save initialization. A bounded acceptance must prove completion with a winner
even if the controlled country is eliminated. If performance work is needed,
profile query and transaction costs first; do not replace canonical football
simulation with a second international engine.

END OF DOCUMENT
