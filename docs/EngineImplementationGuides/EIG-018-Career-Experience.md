# GOAL: Legacy
# Engine Implementation Guide

Document ID: EIG-018
Title: Career Experience Boundary
Version: 1.0
Status: Phase 1 implementation guide

## Ownership

`CareerExperienceService` owns the player-facing between-match event
boundary. It is separate from the Core EventDispatcher: the dispatcher
delivers transient module notifications, while Career Experience persists a
bounded gameplay situation, its choices, and its resolved consequence.

Canonical football services remain authoritative. Career Experience may
route a choice to `PlayerDevelopmentService` through the existing training
service or to the priority repository. It does not calculate Match ratings,
form, Season performance, role, standings, movement, Contracts, or
development outcomes.

## Catalog and selection

`CareerEventCatalog` contains declarative definitions with stable IDs,
categories, copy, choices, eligibility requirements, repeatability policy,
priority relevance, and optional chain metadata. Eligibility reads a bounded
career snapshot and recent Match evidence. It does not scan the world or
hydrate every Player.

Selection is deterministic from Player, Season, calendar-month source key,
priority, and the eligible catalog. Contextual candidates are scored above
generic candidates; recent categories are lightly cooled down. Continue
remains the sole calendar owner and creates no more than one event per
calendar month before a controlled fixture.

## Persistence and replay protection

The existing `career_events` record is the durable boundary. Its structured
context stores the definition, Club context, repeatability, chain stage, and
the canonical context signals used for presentation. A resolved consequence
stores the selected choice, any training/priority change, and a small list of
narrative memory flags.

The existing monthly source-key uniqueness and transactional resolve path
protect event generation and consequences against reloads and retries. A
resolved event is returned unchanged if resolution is attempted again.
Pending events therefore show the same copy and options after save/reload.

## Chains and transfer continuity

Chains are ordinary catalog definitions linked by `chain_id`, `chain_stage`,
and memory requirements. They are intentionally limited to two or three
stages. A transfer changes the current Club context for new eligibility;
historical event records remain queryable and memory flags remain durable.
Rollover resets only seasonal repeatability through the existing Season ID.

## Deferred systems

Phase 1 does not add a balance, salary-payment, purchase, asset, relationship,
morale, reputation, follower, social, or generic event ledger system. It also
does not add a daily event scheduler, training minigame, or new Match facts.
That is the historical Phase 1 boundary; P2-005 adds a separate
controlled-Player finance owner without changing event ownership or
introducing NPC personal finance.

## P2-018 Manager Context

`ManagerTrustService` is a read-only adapter over existing football evidence.
It distinguishes interpersonal `FootballSocialService` manager relationship
from selection-facing football trust, without persisting a second trust
score. It derives a bounded label, same-position competition summary,
role-based playing-time expectation, recent minutes assessment, factual
feedback, and selection context for the controlled Player.

`MatchSelectionService` remains authoritative. In production it receives a
small controlled-Player-only trust tie-break after role, OVR, form, position,
and availability; World/NPC selection does not calculate detailed manager
trust. `CareerOutlookService` consumes the derived mismatch context but does
not become a second playing-time owner.

The `manager-playing-time-review` catalog event is eligible only after at
least three recent selection observations show below-expectation minutes. It
is once per Season, resolves through the existing Career Event transaction,
and routes manager consequences through `FootballSocialService`. Reads never
create or reroll it; the monthly event source key and resolved memory protect
reload/double-submit behavior.

## Revision History

| Version | Date | Notes |
|---|---|---|
| 1.0 | 2026-09-17 | Documented the enriched Phase 1 career-event boundary. |

END OF DOCUMENT
