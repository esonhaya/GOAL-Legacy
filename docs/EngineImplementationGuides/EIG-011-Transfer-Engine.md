# GOAL: Legacy
# Engine Implementation Guides

Document ID: EIG-011
Title: Transfer Engine

Version: 1.0
Status: Approved Blueprint

Dependencies

- Core Engine
- Event System
- World Engine
- Player Engine
- Club Engine
- Staff Engine
- Economy Engine
- Relationship Engine

---

# Purpose

The Transfer Engine manages every player and staff movement between clubs.

It coordinates scouting, transfer interest, negotiations, contract agreements, loans, releases, and registrations while respecting financial, sporting, and regulatory constraints.

The Transfer Engine owns transfer behaviour.

DOMAIN-005 implementation boundary: the production slice supports
deterministic agreed permanent Player transfers and the bounded P2-036
controlled-Player loan lifecycle. Contract rows remain owned by the Contract
Module, registration rows remain owned by the Competition Module, and
Transfer coordinates their transactional transitions. Negotiation AI, agents,
releases, Club finance, NPC loans, and loan-market economics remain deferred.

DOMAIN-012 implementation boundary: CareerMovementService is a bounded
Player-decision layer over this existing TransferService. It evaluates only
the controlled career Player at explicit checkpoints within the current
competition, persists transfer offers as structured CareerOpportunity records,
and never auto-accepts an offer. Accepting an open offer creates the agreed
Transfer and calls TransferService; that service remains the only owner of
Contract, squad, registration, and Transfer state transitions. Offer expiry,
stale-state validation, and source-key idempotency are handled without a
global market scan or negotiation engine.

---

# Responsibilities

The Transfer Engine is responsible for:

- Transfer interest
- Scouting requests
- Negotiations
- Transfer offers
- Loan agreements
- Permanent transfers
- Free transfers
- Transfer-time Contract transitions coordinated with the Contract Module;
  World lifecycle owns date-driven expiry evaluation
- Transfer-time squad and Competition registration coordination
- Transfer window enforcement

---

# Non-Responsibilities

The Transfer Engine does NOT:

- Calculate player growth
- Simulate matches
- Manage club finances directly
- Generate news articles
- Update player morale directly

Those systems belong to their respective engines.

---

# Public Interface

Primary operations include:

- SearchTargets()
- CreateTransferOffer()
- NegotiateTransfer()
- AcceptTransfer()
- RejectTransfer()
- CompleteTransfer()
- CreateLoan()
- RecallLoan()
- RegisterPlayer()

Implementation details may evolve.

---

# Internal Components

## Interest Manager

Tracks:

- Club interest
- Player interest
- Manager interest
- Agent influence (future)

Interest changes dynamically over time.

---

## Scouting Integration

Receives scouting information from club staff.

Supports:

- Domestic scouting
- International scouting
- Youth scouting

---

## Negotiation Manager

Handles:

- Transfer fees
- Installments
- Sell-on clauses
- Bonuses
- Loan fees
- Optional purchase clauses

Future transfer mechanics should extend this manager.

---

## Registration Manager

Responsible for:

- Competition eligibility
- Squad registration
- Foreign player limits
- Home-grown player rules

Competition-specific regulations remain data-driven.

---

## Transfer Window Manager

Controls:

- Window opening
- Window closing
- Emergency transfers
- Registration deadlines

---

## Loan Manager

The implemented P2-036 boundary is responsible only for:

- one explicit controlled-Player decision;
- a Season-end loan duration;
- parent-vs-active Club membership and registration transition;
- parent Contract preservation; and
- idempotent return before Season rollover.

Recall clauses, purchase options, loan fees, wage sharing, NPC loan markets,
and loan negotiation remain out of scope.

---

# Input Events

Examples

- TransferWindowOpened
- PlayerListed
- ContractExpiring
- ClubInterested
- OfferReceived
- SeasonEnded

---

# Output Events

Examples

- TransferCompleted
- LoanStarted
- LoanEnded
- PlayerRegistered
- TransferRejected
- TransferWindowClosed

---

# Data Ownership

The Transfer Engine owns:

- Active negotiations
- Transfer offers
- Loan agreements
- Registration status
- Transfer history

Persistent records belong to the Transfer Database.

---

# Daily Update Flow

Daily tasks include:

- Evaluate transfer interest
- Process negotiations
- Monitor transfer deadlines
- Update active offers

---

# Weekly Update Flow

Weekly tasks include:

- AI recruitment review
- Squad needs analysis
- Loan evaluations
- Transfer target updates

---

# Seasonal Update Flow

Examples:

- Open transfer windows
- Close transfer windows
- Release expired contracts
- Archive completed transfers
- Prepare next registration period

---

# Failure Handling

If transfer processing fails:

- Preserve current negotiations.
- Log the failure.
- Prevent incomplete transfers.
- Notify the Core Engine.

---

# Testing Strategy

The Transfer Engine should be tested for:

- Transfer negotiations
- Loan agreements
- Registration rules
- Window restrictions
- AI recruitment
- Long-term transfer stability

---

# Performance Goals

- Efficient AI negotiations
- Deterministic transfer processing
- Stable transfer windows
- Minimal duplicate evaluations

---

# Future Expansion

Future versions may support:

- Player agents
- Release clauses
- Buy-back clauses
- Swap deals
- Multi-club negotiations
- Pre-contract agreements
- Dynamic market inflation
- Media-driven transfer rumours

These additions should extend the Transfer Engine without changing its core responsibilities.

---

# Locked Decisions

✓ Transfer Engine owns player movement.

✓ Economy Engine owns financial transactions.

✓ Registration is centralized.

✓ Negotiations are modular.

✓ Controlled loans reuse the Transfer Engine and CareerOpportunity path.

✓ Transfer rules are data-driven.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

## P2-013 Controlled Career Market

`CareerMovementService` owns controlled-player market evaluation and its
read-only `marketContext()` projection. It reuses existing transfer,
Contract, Club, competition, form, performance, and legacy owners. Club
profiles are cached per database/Season during a bounded evaluation and carry
Club level, primary competition, European context, capacity, and positional
need. Stable score and Club-ID ordering keeps offers deterministic. Wages are
bounded and age/role-aware; no Club bank balance or negotiation simulator is
introduced. The canonical PHPUnit configuration names `core` and the
explicit `additional` suite for the 18 legitimate Avatar, Match, and Web
tests.

## P2-023 Decision Context Boundary

`CareerMovementService` remains the owner of transfer eligibility, transfer
requests, offer creation, stay/accept resolution, Contract-boundary options,
and free-agency mechanics. P2-023 only enriches legitimate controlled choices
with factual current/target Club comparison: human-readable Club and
competition, Club level, role, wage, Contract term, Europe, current role and
playing time, attachment label, Club objective context, trade-offs, and a
former-Club return note when the target is already in canonical history.

No offer is created because a Club is former, no offer is ranked by a synthetic
"better move" score, and the Player is never auto-directed. Stay, transfer
request, renewal, expiry, free agency, and return resolution retain their
existing owners and rules. Context is deterministic and neutral; it does not
modify OVR, attributes, Match results/ratings, development, injury/readiness,
selection, finances, reputation, or football traits. NPC transfer processing
does not receive attachment/ambition ticks or a new relationship history.

## P2-035 Contract offer terms

The same `CareerOpportunity` path exposes concrete controlled-player Contract
terms for renewal and free-agent decisions: Club, bounded Season term, weekly
GC wage, expected `SquadRole`, and decision expiry. Term calibration is a
small deterministic age rule (one to four Seasons); wage calibration reuses
existing OVR, Club stature, role, recognition, and age evidence with the
existing cap. No universal market-value score is introduced.

One wage counter is supported per concrete Contract option. It requests a
modest bounded increase and receives a deterministic accepted/rejected answer
from the offering Club's existing reputation/stature signal. A successful
counter changes only the stored option; the Player must still explicitly
accept, and `TransferService` applies the accepted wage/end date/role to the
canonical Contract and Season squad membership. The expected role is not a
selection promise. Transfer-interest offers retain their existing atomic
transfer path and show their stored terms without creating a second
negotiation system.

## P2-036 Controlled loan lifecycle

`CareerMovementService` owns the controlled Player's explicit loan decision,
while `TransferService` owns the transactional movement. `LoanRepository`
stores only the compact active/completed loan state. Starting a loan removes
the parent active membership and registrations, creates one loan-Club
membership and registrations, and leaves the parent Contract untouched.
`PlayerCareerProgressionQuery` resolves the loan Club as active football
context while exposing the Contract Club separately. `returnDueLoans()` runs
before Season rollover continuity, restores the parent membership and
registrations, and reuses the captured parent role for ordinary role
re-evaluation. Repeated return is a no-op.

The implementation supports `UNTIL_SEASON_END` only. It has no loan fee,
wage contribution, counterproposal, recall, buy option, free-agent loan,
NPC negotiation, loan-development bonus, or World scan. Match statistics and
development continue through their existing actual-Club/actual-minutes paths;
the loan layer changes registration context, not Match mathematics. Read
presentation and legacy-load paths construct no loan rows.

## P2-038 Career lifecycle integration gate

The lifecycle keeps four Club meanings separate: the Contract Club remains the
parent and wage owner; the active squad membership and Match/statistics Club is
the loan destination during an active loan; historical Clubs come from the
canonical movement/loan record; and an opportunity destination is only a
proposal until accepted. A Player therefore has at most one active playing
membership and one active loan, while a valid parent Contract remains
singular.

The bounded integration path is: accept a Contract-backed loan, move the one
active registration, consume actual destination-Club Match minutes and
player-scoped availability/injury/discipline, return at the scheduled Season
boundary, then allow normal post-return movement. Return restores the parent
membership without rewriting loan statistics or physical state and is
idempotent. Season preparation returns due loans before Contract continuity and
next-Season role derivation; replay creates no additional movement, membership,
history, or financial event. Permanent transfer remains deferred while a loan
is active and resumes after return. Presentation and availability reads remain
observational; no loan, recovery, or offer state is repaired or generated on
page render.

END OF DOCUMENT
