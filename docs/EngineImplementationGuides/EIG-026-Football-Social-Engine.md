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
