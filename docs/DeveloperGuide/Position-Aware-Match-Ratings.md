# Position-Aware Match Ratings

`PlayerMatchRatingService` is the sole owner of GOAL: Legacy's descriptive
per-Match rating. It derives a bounded 0.0--10.0 value from finalized
`PlayerMatchStat` evidence and the canonical `PlayerPosition`; no rating is
persisted, so save/reload reconstructs the same value.

Appearing Players receive a 4.5--6.0 participation foundation based on
minutes. A non-appearing Player has `null` rather than a normal rating.
Goals, assists, and non-goal shots on target are bounded additions; raw shots
are intentionally not rewarded. Goal and assist weights vary modestly by
position. GK ratings primarily use saves and clean sheets, defenders use clean
sheets, and midfielders/attackers get no material clean-sheet benefit. Saves
never benefit outfield Players. There is no goals-conceded penalty because the
current factual evidence does not attribute it cheaply enough for this model.

Ratings are shown through the existing Match player-summary read model only.
Season averages, recent-form integration, Career Hub history, and every career
or market consequence remain deferred. The model uses no RNG and has no xG,
xA, passing, defensive-action, card, or possession inputs.
