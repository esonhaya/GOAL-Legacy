# GOAL: Legacy
# European Club Football Delivery Guide

Document ID: GDB-007
Title: P2-008 European Club Football
Status: Phase 2 implementation record

## Delivery contract

P2-008 adds two compact European Club competitions to the existing Big-5
world. The canonical Match, Fixture, Season, World, fidelity, statistics,
form, development, availability, News, Career Experience, Career History,
persistence, and Web boundaries remain the owners of their domains.

Qualification is deterministic and result-based after the initial seeded
Season. League fixtures are generated before domestic Cups and Europe. The
calendar has one Match clock, prevents Club/date collisions, and leaves
domestic League fixture counts unchanged. Single-leg European knockouts reuse
the domestic Cup AET/penalty resolver.

## Acceptance checklist

- two 16-Club tiers, four groups of four, 24 group plus seven knockout Matches;
- no duplicate Club across tiers and stable draw/save/reload state;
- controlled European Matches are Player fidelity and NPC Matches are World
  fidelity with no detailed NPC evidence;
- League, Cup, Tier 1, Tier 2, and all-competition Player statistics remain
  distinguishable;
- European history, News, Career Experience, Web competition pages, Matchday,
  rollover, and Season-2 qualification are additive;
- domestic Cup, League, P2-003 fidelity, finance, and promotion/relegation
  regressions remain green.

The bounded performance target is 62 European Matches per completed Season.
No heavyweight browser/raster tooling is required; inherited human visual QA
remains blocked by environment only.

END OF DOCUMENT
