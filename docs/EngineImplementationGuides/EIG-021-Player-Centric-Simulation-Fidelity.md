# GOAL: Legacy
# Engine Implementation Guide

Document ID: EIG-021
Title: Player-Centric Simulation Fidelity
Version: 1.0
Status: Approved Implementation Contract
Author: GOAL: Legacy Engineering
Last Updated: 2026-09-17

## Purpose

The Match Module simulates one football world. It does not persist the same
level of evidence for every fixture when that evidence has no gameplay or
historical consumer.

The controlled Player and any directly experienced Club fixture use
`SimulationFidelity::Player`. Other production world fixtures use
`SimulationFidelity::World`.

Both modes share Club strength, eligible squad selection, home advantage,
bounded score generation, scorer/assist attribution, discipline, and stable
hash-keyed deterministic inputs. The fidelity boundary controls detail
generation and persistence, not the football outcome rules.

## Player Fidelity

When a controlled career Player belongs to either Club in a fixture, the
existing full path remains authoritative. It persists selections,
substitutions, Player Match stats, highlights, availability workload,
development idempotency history, evaluation evidence, ratings, and all
existing Matchday consumers.

## World Fidelity

An NPC-only fixture persists the completed Match result and decisive
structured highlights. The simulation retains the participation inputs needed
for goals, assists, discipline, clean sheets, minutes, development stimulus,
availability state, and Season aggregates while omitting replay-only
per-Match action evidence.

World fixtures update one compact `player_season_statistics` row per
Player/Club/Season and a rolling `player_form_summaries` signal for selection
and role progression. NPC per-Match stats, selection history, substitutions,
and development history are not written because no downstream Phase-1
consumer requires them for world fixtures. Detailed records from existing
saves remain readable and are not destructively migrated.

## Ownership

- `MatchSimulationService` owns the shared football model and fidelity-aware
  evidence generation.
- `MatchService` resolves fidelity and owns the atomic Match write boundary.
- `PlayerSeasonStatisticsRepository` owns compact Season Player aggregates.
- `PlayerAvailabilityService` owns current fatigue and injury state.
- `PlayerDevelopmentService` owns Player attributes and progress. World
  Match development updates current state but does not create NPC history
  rows.
- `ClubExpectationService` owns evaluation and role consequences. Controlled
  evaluation rows remain detailed; world evaluations use the rolling summary.
- `StandingsService` continues to rebuild tables from completed Match results.

## Replay and Determinism

World and Player evidence use stable hash namespaces derived from Match and
Player identity. Omitting world-only action draws cannot perturb score,
scorer, assist, discipline, or controlled Match outcomes. A completed Match
remains the transaction idempotency boundary; all result, aggregate, state,
and summary writes commit together.

## Permanent Principles

**No computation without a gameplay consumer.** Cheap transient calculations
are allowed when they are needed to produce a believable canonical world
result.

**No persistence without a historical or future consumer.** Results,
decisive events, current state, and compact aggregates remain durable;
presentation-only and replay-only NPC evidence does not.

## Compatibility

Existing detailed Phase-1 and P2 saves remain valid. New simulation behavior
applies to future scheduled fixtures. Season compaction remains available for
old high-detail saves and for idempotent cleanup of completed Seasons.

## P2-024 Role Fidelity Boundary

On-pitch role is a controlled Player usage preference, not a World simulation
dimension. `MatchSimulationService` may use it only in Player fidelity to
redistribute attempted action volume. World fidelity does not scan Players for
roles, create NPC preferences, or run role ticks. Existing result, selection,
statistic, rating, and Season-aggregate owners remain unchanged, and role
calculation has no effect on team outcome or action success.
