# GOAL: Legacy
# Domestic Cup State

Document ID: DB-015
Title: Domestic Cup Season State
Version: 1.0
Status: Phase 2 implementation contract

## Ownership

`DomesticCupService` owns the mutable bracket state for a domestic Cup
Season. `MatchRepository` remains the sole owner of scheduled and completed
Match records. Club competition memberships remain normalized through the
Club Module.

The Cup state consists of three additive structures:

- one Season row containing participant count, round, status, winner, and
  runner-up;
- one entry row per participating Club containing active/eliminated state;
- one state row per Cup Match containing round/stage, winner, extra-time score,
  and optional shootout score.

The structures reference existing Competition, Season, Club, and Match IDs.
They do not duplicate Match results or Player Match evidence.

## Lifecycle

Cup state is initialized after the Season and league memberships exist. The
first round is scheduled after the League calendar baseline is present. Later
rounds are created only after every Match in the current round has a winner.
The final marks the Season complete and retains its winner and runner-up.

State writes are replay-safe: a completed Cup Match can acquire a winner only
once, and a round key prevents duplicate generation. Missing Cup state on an
older compatible save is created additively when the world is loaded.

## Historical boundary

Completed Cup state is retained as compact competition history. Routine NPC
Player Match evidence is not introduced for Cups. Rendered portraits, UI
brackets, and other presentation data never enter the Career SQLite file.

---

END OF DOCUMENT
