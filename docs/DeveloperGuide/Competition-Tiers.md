# Canonical Domestic Competition Tiers

DATA-017 adds the second domestic tier for each currently installed Big-5
country using the repository's `2024-25` content baseline. The selected
world now contains ten domestic league Competitions and 198 Clubs:

| Country | Tier 1 | Tier 2 | Clubs |
| --- | --- | --- | ---: |
| England | Premier League | EFL Championship | 20 / 24 |
| Spain | La Liga | Segunda División / LaLiga Hypermotion | 20 / 22 |
| Germany | Bundesliga | 2. Bundesliga | 18 / 18 |
| Italy | Serie A | Serie B | 20 / 20 |
| France | Ligue 1 | Ligue 2 | 18 / 18 |

Competition definitions persist a minimal positive `tier` value. Tier 1
content remains in `core-competitions`; tier 2 definitions and Clubs are
selected through the dependent `core-second-tier-competitions` and
`core-second-tier-clubs` packages. All initial memberships target
`season-2024-25` and continue through the existing Season, Club membership,
population, Contract, registration, fixture, recruitment, and Transfer
paths.

The existing fixture generator handles each even-sized league independently.
The population service creates the normal 25-player synthetic senior squad
for every canonical Club; no licensed real-player data is introduced.

DOMAIN-017 adds the first lifecycle movement over these genuine tier pairs.
At each completed Season boundary, the existing `SeasonRolloverService`
asks `StandingsService` for the finalized outgoing tables and exchanges two
automatic places in each country: the bottom two tier-1 Clubs are relegated
and the top two tier-2 Clubs are promoted. This direct exchange is
deterministic and uses Club identity, squads, Contracts, registrations, and
fixtures through their existing owners. Playoff places are deliberately not
modelled.

The exchange is applied while the next Season's seasonal memberships are
materialized. Historical memberships are never rewritten, retrying the same
rollover reuses the same completed standings and membership keys, and the
next fixture set is generated only from the exchanged membership. Clubs keep
their existing reputation and squad; promotion does not itself trigger a
transfer or squad rebuild.

Competitions without a modeled tier-1/tier-2 pair remain unchanged. This
milestone does not add playoffs, finances, coefficients, dynamic Competition
sizes, reserve teams, or tier-specific contracts.
