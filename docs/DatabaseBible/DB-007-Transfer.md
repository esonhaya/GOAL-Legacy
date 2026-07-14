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

---

# Contract References

Stores:

- Previous Contract ID
- New Contract ID

Transfers reference contracts rather than duplicating contract information.

---

# Transfer Window

Stores:

- Window ID
- Season
- Registration Status

Transfers outside registration windows are validated by gameplay rules.

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

END OF DOCUMENT
