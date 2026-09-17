# GOAL: Legacy
# Phase 1 Release Scope

Document ID: GDB-004
Title: Phase 1 Release Scope
Status: Active reconciliation
Last Updated: 2026-09-17

---

## Purpose

This document records the current Phase 1 player-facing boundary after the
DOMAIN-036 through DOMAIN-040 implementation slices. Earlier Bible chapters
remain historical design records. Where an earlier domain paragraph says that
a capability is deferred, this current scope records the later Phase 1
decision without rewriting that history.

The release is a deterministic CLI football career. The player controls one
Player through the normal career path, while canonical domain services remain
the only owners of football facts and lifecycle transitions.

## Included player experience

Phase 1 includes:

- deterministic Player creation and the concise Youth Camp placement flow;
- Player identity, physical profile, one primary position, six headline
  attributes, derived OVR, potential, and the three development profiles;
- Big-5 domestic first and second tiers, Clubs, memberships, schedules,
  standings, bounded NPC recruitment, promotion, and relegation;
- deterministic simulated league Matches with score, goals, assists, shots,
  shots on target, saves, clean sheets, passing, defensive actions,
  discipline, substitutions, selection status, ratings, and highlights where
  canonical Match facts exist;
- starter, substitute, unused-substitute, unavailable, and not-selected
  participation paths, with performance derived from actual participation;
- one Player development path owned by `PlayerDevelopmentService`, fed by
  Match evidence, explicit deterministic training blocks, and Season
  lifecycle stimulus;
- player-facing training focus and medium-term career priority choices;
- bounded deterministic between-Match career events with persisted choices,
  consequences, history, and save/reload stability;
- Contract lifecycle, controlled transfer requests, bounded transfer
  opportunities, renewal, stay, movement, free agency, and continuation
  through the existing Contract and Transfer owners;
- Career Home, Career, World, News, Matchday, Season summary, and next-Season
  presentation reconstructed from canonical state;
- a bounded derived News feed that reports canonical Match, performance,
  movement, role, development, Season, and newsworthy career-event facts;
- Season history, Club movement history, OVR/development history, and
  meaningful off-pitch career history; and
- one SQLite save per career with retry-safe progression and compatible
  reconstruction after reload.

Between-Match career fantasy is intentionally bounded. The event catalog
contains contextual training, teammate, manager, form, role, movement,
family, social, media, fan, community, adaptation, and career-stage moments.
Choices can route through the existing training-focus and priority owners or
leave small structured memory flags for a later callback. Deterministic
selection prefers current football context and remains limited to one event
per calendar month before a controlled fixture. Football remains primary;
events provide life texture, bounded consequence, and continuity through
career history.

Youth Camp is a placement bridge, not a separate youth-academy simulation.
Training focus is a development input, not a second growth engine. Career
events are persisted gameplay situations, not a second generic event bus or
football-history ledger.

## Canonical ownership

| Capability | Owner |
| --- | --- |
| Player identity, attributes, potential, development state/history | Player Module and `PlayerDevelopmentService` |
| Training stimulus and focus | Training Service feeding `PlayerDevelopmentService` |
| Club membership and squad role | Club Module |
| Contracts | Contract Module |
| Transfers and movement execution | Transfer Module |
| Match facts, participation, highlights, and results | Match Module |
| Standings and competition membership | Competition/Match projections |
| Calendar and Season rollover | World Module and `SeasonRolloverService` |
| Career opportunities and between-Match events | Player career boundary |
| Player-facing News | Presentation derived from canonical facts |
| Save persistence and reconstruction | Core persistence plus domain repositories |

No presentation service calculates ratings, form, performance, standings,
development, movement, or event outcomes independently.

## Explicit Phase 1 deferrals

The following remain outside the current release boundary:

- Player balance, salary payments, income, expenses, purchases, assets,
  luxury/status progression, and a finance ledger; Contract wage is metadata
  only;
- secondary positions, preferred foot, appearance customization, shirt
  numbers, and deeper role taxonomies;
- Club budgets, staff/coaches, facilities, daily training schedules,
  training injuries, XP/skill-point systems, morale, confidence, and a second
  development model;
- domestic cups, continental competitions, international football, and
  national-team selection;
- transfer windows, loans, agents, negotiations, fees as financial
  transactions, clauses, bonuses, and competing bids;
- live or interactive Matches, tactics, minute-by-minute simulation, xG,
  xA, dribbles, key passes, aerials, VAR, and advanced analytics;
- persisted News articles, a separate social ledger, followers, sentiment,
  media interviews, press conferences, and a full Pulse simulation;
- detailed relationships, family trees, dating, staff careers, awards,
  records, Hall of Fame, bloodlines, and a full Legacy archive; and
- developer-only inspection and audit commands as a substitute for ordinary
  player gameplay.

The Phase 1 event layer does not change the finance, luxury, relationship,
social, Match-depth, or training-schedule deferrals above. It is presentation
and bounded career context built on existing canonical state, not a new
economy, personality, event bus, or football-fact ledger.

The existing presentation News feed and career-event history may expose
canonical facts without creating any of these deferred systems.

## Reconciliation notes

The original DOMAIN-004 and DOMAIN-013 boundary text predates the playable
Youth Camp, recurring Season rollover, second-tier world, recruitment,
promotion/relegation, controlled movement decisions, and off-pitch career
experience. The later implementation and Developer Guide slices supersede
those specific deferral statements for the current Phase 1 release.

The older technical module inventory remains an architectural catalogue. It
does not require inactive Staff, Economy, Relationship, Pulse, Legacy, or
Achievement engines to be fabricated for this release. Their documented
interfaces remain extension points for later phases.

## Release rule

Phase 1 is complete when the included experience is reachable through the
normal CLI path, survives save/reload, and passes the DOMAIN-042 full
regression and end-to-end release gate. No additional feature batch should
be scheduled before that gate unless it addresses a concrete blocker.

---

END OF DOCUMENT
