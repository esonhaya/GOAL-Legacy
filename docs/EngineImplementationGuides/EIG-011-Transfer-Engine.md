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

DOMAIN-005 implementation boundary: the current production slice supports
deterministic agreed permanent Player transfers only. Contract rows remain
owned by the Contract Module, registration rows remain owned by the
Competition Module, and Transfer coordinates their transactional transition.
Negotiation AI, transfer windows, loans, scouting, agents, releases, and Club
finance are deferred.

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

Responsible for:

- Loan duration
- Playing-time expectations
- Recall clauses
- Future purchase options

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

✓ Loan system is independent.

✓ Transfer rules are data-driven.

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-15 | Initial draft |

---

END OF DOCUMENT
