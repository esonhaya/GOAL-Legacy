# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-014
Title: Pulse Engine

Version: 1.0
Status: Approved Blueprint

## P2-015 naming boundary

This historical blueprint uses “Pulse Engine” for an internal trend/momentum
concept. The current player-facing brand is written `PULSE`, and its football
integration is implemented by `PulseService`. `EchoService` selects noteworthy
canonical facts before Pulse presentation. The old internal term is retained
here to avoid a broad architectural rename; new user-facing or integration
documentation must use PULSE for the platform and ECHO for routing.

P2-015 deliberately does not implement the world-wide trend managers,
individual AI participants, popularity history, or global archive described as
future blueprint concepts below. Goal: Legacy persists only controlled-player
Pulse sources/posts, one audience state, and curated response state. NPCs may
appear as contextual actor labels but do not receive Pulse accounts or social
careers.

## P3-001 presentation boundary

`EchoService` remains the event-kind and importance owner. `PulseService` owns
the presentation catalog: lightweight voices such as supportive, reactionary,
rival, tactical, casual, meme, old-school, optimistic, pessimistic, and
neutral viewers are assigned only where the existing actor context permits.
Voice dimensions vary sentence shape, capitalization, football terminology,
slang, punctuation, and emoji without creating persistent social identities.

Reaction families and opening families are selected with a small deterministic
`pulse-reaction:v1` namespace. A bounded recent-post window suppresses exact
text, close family, opening, slang, and emoji reuse, including between posts
created by one event. If a catalog cannot safely provide a distinct choice,
the existing actor template is used. New source context stores the selected
presentation metadata, so reloads do not regenerate persisted prose; legacy
posts are never rewritten.

Language remains evidence-bound: Match result, goal/assist, red card, injury,
return, transfer, achievement, and retirement wording use only the canonical
context supplied by Echo and the existing event path. No network, AI, World
scan, follower simulation, or historical reconstruction is added.

## P3-002 conversation boundary

New events may add a small conversation around existing root posts. Pulse
stores reply/quote relationships in `pulse_thread_edges`, with a maximum depth
of two and normally one or two children. Intent is lightweight—agreement,
pushback, reluctant credit, defence, banter, or a local callback—and reply
wording is selected from the same deterministic voice discipline as roots.
Quote reactions reference a stored parent structurally; they do not duplicate
the full parent body. A callback may use only the immediately persisted thread
text it references. Thread creation is event-local and deterministic, with
bounded pattern suppression. Legacy posts remain unconnected roots.

Dependencies

- Core Engine
- Event System
- World Engine
- Player Engine
- Club Engine
- Competition Engine
- News Engine
- Relationship Engine

---

# Purpose

The Pulse Engine monitors long-term trends across the football world.

Rather than recording individual events, it identifies momentum, popularity, reputation shifts, emerging stories, and world narratives that develop over time.

The Pulse Engine owns football trends.

---

# Responsibilities

The Pulse Engine is responsible for:

- Trend detection
- Momentum tracking
- Public attention
- Club momentum
- League popularity
- Nation reputation
- Emerging narratives
- Trend decay

---

# Non-Responsibilities

The Pulse Engine does NOT:

- Generate news articles
- Simulate matches
- Calculate finances
- Create relationships
- Change player attributes

It observes the simulation and produces world-level insights.

---

# Public Interface

Primary operations include:

- DetectTrend()
- UpdateMomentum()
- CalculatePopularity()
- ArchiveTrend()
- ExpireTrend()
- GeneratePulseSnapshot()

Implementation details may evolve.

---

# Internal Components

## Trend Detector

Identifies significant emerging patterns.

Examples:

- Winning streaks
- Losing streaks
- Rising stars
- Falling giants

---

## Popularity Manager

Tracks popularity for:

- Players
- Clubs
- Competitions
- Nations

Popularity changes gradually.

---

## Momentum Manager

Maintains momentum values for:

- Clubs
- Managers
- Players

Momentum is temporary and naturally fades.

---

## Narrative Tracker

Recognizes stories such as:

- Underdog runs
- Golden generations
- Dynasty clubs
- Club rebuilds
- Historic rivalries

Narratives are built from many smaller events.

---

## Pulse Archive

Stores important world trends for historical review.

---

# Input Events

Examples

- MatchFinished
- TrophyWon
- TransferCompleted
- ArticlePublished
- PlayerDebut
- Retirement

---

# Output Events

Examples

- TrendStarted
- TrendEnded
- MomentumChanged
- NarrativeCreated
- PulseSnapshotGenerated

---

# Data Ownership

The Pulse Engine owns:

- Active trends
- Momentum values
- Narrative records
- Popularity history

Persistent storage belongs to the Pulse Database.

---

# Daily Update Flow

Daily tasks include:

- Scan recent events
- Update momentum
- Detect emerging trends

---

# Weekly Update Flow

Weekly tasks include:

- Refresh popularity
- Evaluate active narratives
- Remove expired trends

---

# Seasonal Update Flow

Examples:

- League popularity review
- Nation reputation review
- Historic narrative archive
- World Pulse summary

---

# Failure Handling

If Pulse processing fails:

- Preserve previous trend data.
- Log the error.
- Continue simulation.
- Notify the Core Engine.

---

# Testing Strategy

The Pulse Engine should be tested for:

- Trend detection
- Momentum progression
- Narrative creation
- Trend expiration
- Archive stability

---

# Performance Goals

- Efficient event scanning
- Stable long-term trends
- Deterministic calculations
- Minimal duplicate processing

---

# Future Expansion

Future versions may support:

- Fan culture
- Internet trends
- Club popularity by region
- Broadcast influence
- Sponsorship popularity
- Football fashion trends

These additions should extend the Pulse Engine without changing its core responsibilities.

---

# Locked Decisions

✓ Pulse tracks trends, not events.

✓ Momentum naturally decays.

✓ Narratives emerge from multiple events.

✓ Other engines consume Pulse data.

✓ Historical trends are preserved.

✓ Pulse remains observational.

## P3-003 global identity and culture boundary

PulseService owns a bounded recurring fictional identity catalog. Identity
selection is deterministic and considers the current Club/competition country,
Player nationality, international context, rivalry, and movement context.
Personal P3-001 voice remains distinct from the identity's football culture.
Posts stay English-first, with restrained regional football language and
occasional short native expressions. Unsupported countries use the global
football fallback; no fake accents or stereotype generation is allowed.

An identity can recall only its own recent authored Pulse posts through a small
indexed window. A memory callback requires persisted source evidence and is
selected prospectively; rendering does not generate or repair it. Stable
identity IDs survive reload, while old posts without identity metadata remain
unchanged legacy roots. Recent identity reuse is softly suppressed within the
same bounded selection window so one account does not dominate a local feed.

## P3-004 context refinement

`PulseService` maps a country to its exact supported culture profile or to
`global`; unsupported countries never inherit an unrelated profile's terms.
Culture candidates are English-first and native-language candidates are
deterministically optional. Situational candidates consume existing canonical
Match and movement flags only, and share the existing reaction-family and
recent-history suppression.

Identity memory remains a bounded indexed lookup. New callbacks classify the
evidenced prior reaction and generate a short natural response; they never
insert an excerpt or reconstruct a prior body. Existing saved Pulse prose and
legacy roots remain unchanged.

## P3-005 Career story context

`PulseService` may derive a small list of presentation markers from the
canonical Echo kind and supplied source/headline evidence. This supports
breakthrough, explicit form labels, recorded injury return, trophy, transfer,
Contract context, and retirement families without persisting Career stage,
hype, sentiment, redemption, or popularity state. Missing evidence means the
specialized family is skipped.

Movement context can identify new-Club and former-Club aggregate actors when
both Club IDs are canonical. The stable identity catalog supplies allegiance;
no transfer or loan rewrites an identity. Memory callbacks continue to use only
the bounded recent authored-post lookup and classified prior stance, producing
natural wording rather than stored-text excerpts. Existing posts are immutable,
and feed/page reads remain DML-free.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
