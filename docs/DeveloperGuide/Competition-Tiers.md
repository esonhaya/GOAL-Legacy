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

Promotion and relegation are intentionally deferred to DOMAIN-017. This
milestone does not add movement rules, playoffs, finances, coefficients,
dynamic Competition sizes, reserve teams, or tier-specific contracts. The
future movement service must use finalized standings and the existing
seasonal membership authority.
