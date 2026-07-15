# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-013
Title: News Engine

Version: 1.0
Status: Approved Blueprint

Dependencies

- Core Engine
- Event System
- World Engine
- Player Engine
- Club Engine
- Staff Engine
- Match Engine
- Transfer Engine
- Relationship Engine

---

# Purpose

The News Engine transforms significant football events into articles, headlines, stories, and historical records.

It provides context to the simulation by giving players visibility into what is happening throughout the football world.

The News Engine owns football journalism.

---

# Responsibilities

The News Engine is responsible for:

- News generation
- Headlines
- Match reports
- Transfer reports
- Injury reports
- Awards coverage
- Historical articles
- Story categorization
- News archiving

---

# Non-Responsibilities

The News Engine does NOT:

- Create football events
- Simulate matches
- Negotiate transfers
- Change morale
- Change relationships

It only reports existing events.

---

# Public Interface

Primary operations include:

- GenerateArticle()
- PublishHeadline()
- ArchiveStory()
- GenerateMatchReport()
- GenerateTransferStory()
- GenerateFeatureArticle()
- DeleteExpiredNews()

Implementation details may evolve.

---

# Internal Components

## Event Filter

Receives events from every engine.

Determines whether an event deserves coverage.

Examples:

- World-class transfer
- Derby victory
- Club bankruptcy
- Historic goal
- Retirement

---

## Story Generator

Creates:

- Headlines
- Short reports
- Full articles
- Feature stories

Future templates may vary by competition or region.

---

## News Categories

Examples:

- Transfers
- Matches
- Injuries
- Awards
- Finance
- Club News
- International Football
- Legacy
- Youth

---

## Priority Manager

Ranks stories.

Examples:

High Priority

- Champions League Final
- World Record Transfer
- Legendary Retirement

Medium Priority

- League Match
- Contract Extension

Low Priority

- Reserve Team News
- Friendly Match

---

## Archive Manager

Stores historical articles.

Supports long-term browsing and legacy records.

---

# Input Events

Examples

- GoalScored
- MatchFinished
- TransferCompleted
- TrophyWon
- ContractSigned
- Retirement
- InjuryOccurred

---

# Output Events

Examples

- ArticlePublished
- BreakingNews
- StoryArchived
- WeeklyDigestGenerated

---

# Data Ownership

The News Engine owns:

- Articles
- Headlines
- Categories
- Publication dates
- Archive

Persistent storage belongs to the News Database.

---

# Daily Update Flow

Daily tasks include:

- Collect new events
- Generate articles
- Publish headlines
- Archive outdated stories

---

# Weekly Update Flow

Weekly tasks include:

- Weekly digest
- Trending stories
- Archive maintenance

---

# Seasonal Update Flow

Examples:

- Season review
- Award coverage
- Historic summaries
- Hall of Fame articles

---

# Failure Handling

If article generation fails:

- Preserve queued events.
- Log the failure.
- Continue processing unrelated stories.
- Notify the Core Engine.

---

# Testing Strategy

The News Engine should be tested for:

- Article generation
- Event prioritization
- Archive management
- Story categorization
- Long-term archive stability

---

# Performance Goals

- Fast article generation
- Efficient archive storage
- Deterministic story prioritization
- Minimal duplicate reporting

---

# Future Expansion

Future versions may support:

- Journalists
- Newspapers
- Regional media
- Social media
- Interviews
- Rumours
- Fan reactions
- AI-generated writing styles

These additions should extend the News Engine without changing its core responsibilities.

---

# Locked Decisions

✓ News reports events.

✓ News never creates events.

✓ Articles are archived.

✓ Story priority is centralized.

✓ History is preserved.

✓ News remains data-driven.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
