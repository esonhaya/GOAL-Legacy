# GDB-008 — Football Reputation and Relationships

P2-010 adds a small social reaction layer to the controlled Player's football
Career. It does not add a competition, a second Match model, or a popularity
game. Match results, appearances, ratings, goals, discipline, movement,
international context, and resolved Career choices are the only sources of
social change.

## State

`PUBLIC_PROFILE` describes accumulated public recognition. `CLUB_STANDING`
describes the Player's standing with the current Club community.
Supporters use one aggregate sentiment derived from a bounded score; the
manager relationship is a Club-scoped context, not a simulated manager life.
Values are clamped and presented as Unknown, Prospect, Recognized, Established,
Star, Elite; New Arrival, Recognized, Trusted, Club Favorite; Frustrated,
Skeptical, Neutral, Supportive, Adored; and Strained through Key Relationship.
Reputation never changes OVR, attributes, salary, or Match difficulty.

## Relationships and media

Only the controlled Player receives social state. Up to eight active meaningful
Player relationships can exist: mentor, friend, ally, competitor, or rival.
They arise from a meaningful Career Event or a significant knockout/
international Match. A transfer preserves historical relationship rows while
closing the current Club manager context and starting a bounded new Club
context. No NPC social graph, fan records, follower count, or social-media
posting exists.

Public attention is a reaction layer. High-value events may produce a Career
Event, News headline, or landmark Career History entry; routine NPC outcomes do
not create social records. Choices alter sentiment, standing, manager context,
or relationships only. Choices are resolved once by the existing Career Event
source identity.

## Football boundaries

League, domestic Cup, European, and international Matches use the canonical
Match/selection/statistics/form/development systems. Controlled Matches use
Player fidelity; NPC Matches remain World fidelity. A meaningful rival may be
shown as concise Matchday context. International success can raise public and
international recognition, but does not create a separate international
reputation engine.

Legacy P2-009 saves bootstrap lazily from current role and current football
context. They do not receive fabricated historical reputation or social
events. International football remains Club-to-country football only; no
national social or political simulation is implied.

## P2-012 achievement reactions

Completed-Season awards, honours, meaningful personal records, and major
milestones are bounded inputs to the existing controlled-Player reaction layer.
Stable source IDs make award and trophy effects idempotent across reloads and
repeated boundary calls. They may add a landmark Career History/News context
and modest public, supporter, or manager reaction, but never change OVR,
attributes, wages, Match outcomes, or relationship counts. NPC award winners
are allowed from compact football evidence; NPC social simulation remains out
of scope.
