# TB-009 — Football Social State

`FootballSocialService` is the sole owner of P2-010 controlled-career social
state. It consumes finalized canonical facts after `MatchService`, transfer
decisions through `TransferService`/`CareerMovementService`, and choices inside
`CareerExperienceService` transactions. It does not select Players, rate
Matches, develop Players, or simulate NPC social state.

Match processing is cheap and bounded: it checks only career Player IDs and
only persists detailed social evidence when the controlled Player is in a
Player-fidelity Match. World-fidelity NPC Matches produce zero social rows.
Stable source markers prevent page refresh, retry, and duplicate Continue
operations from applying a consequence twice.

The read boundary is additive: progression summaries expose compact labels,
Career Home/Profile show public context, Relationships shows at most eight
active relationships, News includes landmark social headlines, and Matchday
may show one rival context. These reads do not mutate Player social state.

Manager identity is intentionally a stable Club-manager context because the
current game has no manager entity. Transfer and free-agency transitions close
the old manager context; Club history and meaningful Player relationships are
retained. International, European, Cup, and League systems remain canonical
consumers and do not fork social services.

## P2-011 integration boundary

The Youth Camp acceptance path is a second atomic career-start writer because
it also creates the initial Contract, registration, squad membership, finance
row, and Career reference. After that transaction succeeds it invokes
`FootballSocialService::initializeCareer`, matching `PlayerService`'s normal
controlled-career initialization without duplicating the atomic records.

Current employment is read from the active Contract and matching current
squad membership. Historical squad rows remain available to Career history,
but the controlled Player profile never treats them as a current Club after
free agency. Social state remains controlled-player-only and retains its
former Club context separately.
