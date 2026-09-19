# GOAL: Legacy
# Database Bible

Document ID: DB-007
Title: Transfer Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

The Transfer table stores every player and staff movement that occurs throughout the lifetime of a GOAL: Legacy universe.

A transfer is a historical event.

It records the movement between clubs, the financial details of the deal, the outcome of negotiations, and references to the contracts that were affected.

Transfers remain permanently archived.

---

# Ownership

Primary Module

Transfer Module

Supporting Modules

- Club
- Player
- Staff
- Contract
- Economy
- News
- Pulse
- Legacy

Only the Transfer Module may create or modify transfer records.

---

# Design Principles

- One record per transfer event.
- Permanent Transfer ID.
- Historical preservation.
- Financial transparency.
- Event-driven architecture.

---

# Lifecycle

A transfer record is created when negotiations officially begin.

The transfer progresses through defined stages until completion, cancellation, or expiration.

Completed transfers become permanent historical records.

Cancelled negotiations are retained for statistical and media purposes.

---

# Primary Key

Transfer ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

---

# Transfer Participants

Stores references only.

Fields include:

- Transfer ID
- Player ID or Staff ID
- Selling Club ID
- Buying Club ID

Only IDs are stored.

DOMAIN-005 Phase 1 supports agreed permanent Player transfers. A Transfer
stores Player ID, source Club ID, destination Club ID, Season ID, effective
date, and a non-negative integer fee in the smallest currency unit.

DOMAIN-012 adds a bounded career-facing offer projection without changing
Transfer ownership. The career movement service evaluates the controlled
Player at explicit SimulationDate checkpoints, persists only deterministic
structured offers as CareerOpportunity records, and hands an accepted agreed
transfer to TransferService. Offer context may include proposed fee, wage,
Contract end date, destination role, positional fit, and reason codes; these
are descriptive decision data, not Club balances or negotiation state. NPC
market-wide generation, scouting, negotiation, loans, and transfer windows
remain deferred.

---

# Transfer Type

Supported types include:

- Permanent Transfer
- Loan
- Free Transfer
- Contract Renewal
- Staff Appointment
- Staff Departure

Future phases may introduce player exchanges and multi-club transactions.

---

# Negotiation Status

Supported values:

- Rumoured
- Negotiating
- Offer Submitted
- Offer Accepted
- Medical Pending
- Personal Terms Pending
- Completed
- Cancelled
- Rejected

These values drive News and Pulse generation.

The Phase 1 lifecycle is `proposed`, `agreed`, `completed`, or `cancelled`.
An agreed Transfer may execute without a negotiation engine.

---

# Financial Information

Stores transfer-specific values.

Examples:

- Transfer Fee
- Loan Fee
- Installments
- Sell-on Percentage
- Buy-back Option
- Currency

Contract wages and bonuses are stored in the Contract table.

Phase 1 persists only the agreed fee as an integer minor-unit value. It does
not mutate Club finances, create a ledger, or implement installments,
bonuses, or clauses.

---

# Contract References

Stores:

- Previous Contract ID
- New Contract ID

Transfers reference contracts rather than duplicating contract information.

Transfer execution coordinates old and new Contract IDs, but Contract rows
remain owned by the Contract Module.

---

# Transfer Window

Stores:

- Window ID
- Season
- Registration Status

Transfers outside registration windows are validated by gameplay rules.

Transfer-window enforcement is deferred in Phase 1; the effective date is
validated against the simulation timeline only.

---

# Timeline

Stores key dates.

Examples:

- Negotiation Start
- Agreement Date
- Medical Date
- Registration Date
- Completion Date

---

# AI Decision Data

Stores references explaining transfer outcomes.

Examples:

- Financial Reason
- Sporting Reason
- Playing Time
- Reputation
- Personal Preference

Detailed AI calculations remain within the Transfer Module.

---

# Media References

Transfers may generate:

- Rumours
- News Articles
- Interviews
- Fan Reactions
- Trending Discussions

Only references are stored.

---

# Legacy References

Transfers contribute to:

- Career History
- Club History
- Transfer Records
- Financial Records

Historical calculations occur after completion.

---

# Relationships

References:

- Player ID
- Staff ID
- Club IDs
- Contract IDs
- Window ID

Only IDs are stored.

Squad membership and Competition/Season Player registration remain owned by
their relationship domains. Transfer execution coordinates their transition
transactionally and does not duplicate their records.

---

# Validation Rules

Transfer ID cannot change.

Buying and selling clubs must be valid.

Transfer fees cannot be negative.

Completed transfers cannot be edited through normal gameplay.

Contract references must exist.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

Transfer Module

Full Access

Economy Module

Financial calculations only

News Module

Media references only

Pulse Module

Discussion references only

Legacy Module

Historical records only

---

# Performance Notes

Frequently indexed fields include:

- Transfer ID
- Player ID
- Buying Club
- Selling Club
- Transfer Window
- Completion Date

Historical transfers remain permanently archived.

---

# Future Expansion

Future versions may include:

- Multi-club negotiations
- Agent interactions
- Deadline day events
- Swap deals
- Conditional bonuses
- Work permit decisions
- Third-party ownership rules
- Financial Fair Play validation

These additions should extend the existing architecture without breaking compatibility.

---

# Locked Decisions

✓ Permanent Transfer ID

✓ One Record Per Transfer Event

✓ Historical Preservation

✓ Contract References Instead of Duplication

✓ Event-Driven Lifecycle

✓ Modular Ownership

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

## P2-013 Market Context Boundary

Market stature is derived at evaluation/read time and is not persisted as a
continuously mutating Player score. Existing `CareerOpportunity` rows remain
the controlled-player offer state; their context may retain bounded projected
role, wage, expiry, Club level, competition, and stable reasons. Candidate
search is deterministic, capacity-aware, and limited to three offers. The
existing TransferService remains authoritative for Contract, squad,
registration, payroll, social, and history continuity. Legacy saves receive
only derivable current context; NPCs receive no market-stature rows or
detailed scouting evidence.

END OF DOCUMENT
