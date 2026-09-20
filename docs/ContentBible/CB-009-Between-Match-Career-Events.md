# GOAL: Legacy
# Content Bible

Document ID: CB-009
Title: Between-Match Career Events
Version: 1.0
Status: Phase 1 content guidance

## Purpose

Between-match events add texture to a football career while keeping football
as the centre of the experience. They describe bounded situations that arise
from the Player's current Club, role, form, movement, training focus, and
career stage.

## Categories

Phase 1 may use training, recovery, teammates, manager, family, friends,
social life, media, fans, community, adaptation, career, form, role,
transfer, contract, season, and Club culture situations. A category is a
content label; it is not a second simulation system.

## Event writing

- Titles are short and concrete.
- Descriptions identify the football context and avoid unsupported facts.
- Choices are two to four concise actions.
- Choices should express plausible tradeoffs rather than a correct and an
  obviously foolish answer.
- Consequences describe what the Player chose in ordinary football language.
- Raw enum names, internal IDs, progress units, and implementation terms do
  not appear in player-facing copy.
- Family and social situations remain abstract and respectful. Phase 1 does
  not simulate relationships, dating, money, or social popularity.

## Context and continuity

Eligibility may use canonical current state such as role, recent form,
participation, goals, movement, Contract boundary, transfer request, Club
membership, or free-agent status. A fresh Player receives early-career and
Club context rather than commentary about performances that do not exist.

A small number of event chains may use structured memory flags. Chains have
two or three stages and callbacks reference an earlier choice without
creating a quest engine or a personality meter. Transfer and rollover logic
must discard stale Club-specific eligibility while preserving resolved
history.

## Repeatability and cadence

Events use one of these policies:

- `once_per_career`
- `once_per_club`
- `once_per_season`
- `cooldown`

Continue creates at most one event per calendar month and only before a
controlled fixture. Deterministic selection prefers a current contextual
event, then a priority-relevant event, then a generic event. Recent
categories receive a small cooldown so the event layer does not overwhelm
Matches.

## History and News

Every resolved choice is durable for replay protection and future
eligibility. Career History displays meaningful milestones. News reports a
small subset: firsts, notable performances, movement, Contract milestones,
promotion/relegation, and substantial media or community moments. Routine
recovery, private family time, and ordinary training choices remain private
career context.

## Locked Phase 1 boundary

Events may change training focus or career priority through their existing
owners and may leave small narrative memory flags. They do not create
attributes, ratings, form, money, purchases, assets, relationships,
followers, reputation, or a second event ledger. P2-005 may use controlled-
Player financial context and bounded purchase milestones; money changes route
through PlayerFinanceService and routine payroll remains in the finance
ledger rather than Career History or News.

## Revision History

| Version | Date | Notes |
|---|---|---|
| 1.0 | 2026-09-17 | Added Phase 1 between-match event guidance. |

## P2-006 Financial and Lifestyle Context

Phase 2 events may respond to the controlled Player's derived financial
context, owned lifestyle category/effect, active Club, transfer movement,
free-agent state, and Contract boundary. These signals create situations
about saving, relocation, training investment, community involvement, and
the visibility that can accompany success. They do not simulate NPC money,
relationships, sponsorship contracts, or a recurring household budget.

Financial choices use fixed GC amounts in the initial content set. Every
debit or credit is applied by PlayerFinanceService with an event-and-choice
source key. A purchase or event may become a meaningful Career History item;
ordinary payroll and minor purchases remain in the finance ledger.

The catalog distinguishes permanent assets from Experience items. Asset-aware
events must remain occasional and contextual; ownership changes eligibility
and weighting but does not make every purchase generate a popup. Choice copy
must make the football/life tradeoff clear without moral scoring or a
universally correct answer.

## P2-017 Readiness Presentation

Between-Match presentation may explain the controlled Player's derived
readiness as Fresh, Ready, Managed, Tired, Fatigued, or Injured. Copy should
describe the football consequence without exposing coefficients or implying
a diagnosis. Training focus remains the development choice; existing Career
priority supplies the light/normal/intense workload approach.

Routine fatigue, recovery, and training completion are not social or News
stories. Significant Injury and return facts use the existing availability,
Career History, News, and Pulse routes. Pages remain read-only: recovery is
owned by simulation time and no page view creates an event.

## P2-018 Manager and Playing-Time Conversations

Manager football feedback is private Career context. Use factual language
such as strong recent performances, close positional competition, limited
minutes, returning from Injury, or the need for consistency. Do not expose
hidden point changes or promise a start.

The controlled Player may receive a once-per-Season conversation only after a
sustained below-expectation playing-time window. Curated responses include
asking for more minutes, focusing on earning the place, and accepting the
current role while developing. The existing manager relationship receives a
small bounded consequence; no new personality or morale system is created.
Silence remains represented by no conversation when evidence is insufficient.

## P2-019 Positional Development Presentation

Position training is private football context. Present the current primary,
established secondary capability, active target, and bounded progress on the
Training/Profile surfaces. Do not turn routine focus changes into News,
Pulse, or Career History. A completed primary-position transition may be a
single durable Career landmark, but it does not change historical Match copy,
attributes, or grant manager trust directly. Player-facing language should
describe learning an option rather than promising future selection.

## P2-020 Club Season Pressure

Club Season copy should explain the football situation, not simulate a
boardroom. Use the stable Club expectation, current standings, actual Season
phase, promotion/relegation context, Cup/European progress, and meaningful
upcoming fixtures. Prefer factual tones such as `on track`, `under pressure`,
`at risk`, or `exceeding expectations`.

The controlled Player's role and contribution provide context: a Prospect is
not narrated as the cause of Club failure, while a Key Player may be described
as important during a run-in when appearances, minutes, or performance support
that reading. Routine pressure changes stay private. Promotion, relegation,
title, and major Cup/European outcomes may use the existing News, Echo, Pulse,
and Career History routes; no duplicate narrative system or NPC objective
conversation is created.

END OF DOCUMENT
