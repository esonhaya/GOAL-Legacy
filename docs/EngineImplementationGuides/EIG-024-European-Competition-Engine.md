# GOAL: Legacy
# European Competition Engine

Document ID: EIG-024
Title: European Club Competition Engine
Status: Phase 2 implementation guide

## V1 boundary

`EuropeanCompetitionService` owns qualification, deterministic group draws,
group-to-knockout progression, compact European state, and qualification
history for two generic competitions: Continental Tier 1 and Continental Tier
2. It uses existing Big-5 first- and second-tier Clubs only. Scotland, Wales,
and other nations are not fabricated into the European field.

The content schema keeps the existing nation anchor for compatibility, but the
`continental` competition type is authoritative. Promotion/relegation filters
strictly to domestic leagues.

## Qualification and format

Season 1 seeds the 96 existing eligible Clubs by deterministic reputation and
Club ID order: the first 16 enter Tier 1 and the next 16 enter Tier 2. From
Season 2 onward, qualification uses completed prior-season domestic League
tables: Tier 1 receives the top two from each Big-5 nation plus the domestic
Cup winner, with deterministic fallback and no duplicate Club; Tier 2 uses the
next League positions excluding Tier 1, then stable fallback entries.

Each tier has 16 Clubs, four groups of four, and a single round robin: 24
group Matches. The top two in each group advance to four single-leg
quarter-finals, two semi-finals, and a final: seven knockout Matches. The
bounded budget is therefore 31 Matches per tier, 62 European Matches per
Season when both fields are complete.

## Draw, calendar, and resolution

Group and knockout draws are namespaced by world seed, Season, competition,
stage, round, and Club identity. Group allocation is seeded by reputation and
tries to avoid same-nation groups while always preserving four Clubs per
group. League fixtures are generated first; European dates are allocated
through the canonical Club collision check. Continue selects the nearest
scheduled Match across League, domestic Cup, and Europe.

The canonical Match engine owns football. Controlled-Player European Matches
use `SimulationFidelity::Player`; NPC-only Matches use `World`. Knockout
resolution is shared with domestic Cups and records regulation, deterministic
extra time, penalties, and winner without counting shootout goals as Match
goals.

## Persistence and rollover

European season, entry, group-membership, and Match-state rows reference
canonical Competition, Season, Club, and Match rows. History stores Season,
competition, winner, runner-up, and Club qualification facts; it does not
snapshot full brackets. State is reconciled on load and initialized once during
rollover. Next-Season qualification reads completed domestic results from the
previous Season.

## Career and future reuse

The existing statistics, form, development, availability, discipline, News,
Career Experience, Career History, and graphical Matchday boundaries are
reused. European events are selective contextual moments, not a second career
engine. Europe does not add prize money, finance rules, coefficient history,
registration windows, or national-team football. International competitions
remain future work.

END OF DOCUMENT
