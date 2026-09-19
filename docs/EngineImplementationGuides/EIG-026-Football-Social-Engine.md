# EIG-026 — Football Social Engine

## Processing

1. `MatchService` completes the canonical Match and its existing competition,
   availability, and development consumers.
2. `FootballSocialService::recordMatch` consumes only detailed controlled-
   Player evidence and writes a bounded state update plus an optional landmark.
3. `CareerExperienceService::resolve` applies a choice's optional social
   consequence in the same transaction as the existing focus, priority,
   finance, and event consequence.
4. Transfer request, withdrawal, free-agent signing, and completed transfer
   paths apply idempotent public/Club context changes.

Evidence is intentionally conservative. Good starts, strong ratings, goals,
important Cup/European/international Matches, and wins move context slowly;
poor evidence, red cards, transfer agitation, and losses are bounded negative
signals. No decay grind, ability bonus, salary modifier, or selection override
exists.

## Persistence and recovery

Five additive tables are initialized for new and legacy saves. State bootstrap
uses role/current Club only; old Matches are not replayed. Source markers use
canonical Match IDs, transfer decisions, and Career Event IDs. Reload therefore
preserves labels, active relationships, landmark history, and Club transition
context exactly.

## Future boundary

The same context can support future narrative consumers, but Europe,
international expansion, national social systems, followers, agents,
sponsorships, and manager simulation are not implemented by this guide.

## P2-015 integration: ECHO → PULSE

`EchoService` is the routing layer for public importance and reaction kind.
`PulseService` owns only controlled-career Pulse source/post/response
presentation. News remains curated factual reporting and Career History remains
durable truth; neither is reconstructed by parsing rendered Pulse text.

Pulse actor types are aggregate `fan`, `club`, `media`, `competition`,
`national`, valid `teammate`, valid `rival`, and controlled `player`. Stable
source keys are derived from canonical Match, transfer, achievement,
availability, or Career-choice identity. Posts are deterministic under
`pulse-feed:v1`; engagement is deterministic under `pulse-engagement:v1` and
does not consume gameplay RNG. The feed retains at most 200 source items per
controlled Player and reads never write.

Pulse adds no NPC account, follower row, social graph, detailed Match evidence,
finance, or legacy simulation. Legacy saves receive empty current Pulse state
only; future canonical facts populate the feed. A response writes one curated
Player post exactly once and may not change OVR, attributes, Match outcome,
rating, or development.

## Canonical validation

The configured PHPUnit suite is the canonical combined invocation:
`vendor/bin/phpunit`. `--testsuite core` covers the configured core and
Integration directories; `--testsuite additional` covers Avatar, Match, and
Web. PHPUnit 11 does not accept repeated `--testsuite` options, so the
`composer test:all` alias uses the default configured invocation. No retired
historical non-default file list is required.
