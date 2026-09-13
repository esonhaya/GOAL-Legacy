# GOAL: Legacy
# Database Bible

Document ID: DB-006
Title: Contract Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

The Contract table stores every employment agreement between a player or staff member and a club.

A contract defines the legal, financial, and sporting relationship between both parties.

Transfers, renewals, loans, and free agency are all governed through contracts.

---

# Ownership

Primary Module

Contract Module

Supporting Modules

- Player
- Club
- Staff
- Economy
- Legacy
- News

Only the Contract Module may create, terminate, or modify Contract records.
The Transfer Module may request coordinated Contract transitions through the
Contract service but does not own Contract persistence.

---

# Design Principles

- One record per contract.
- Permanent Contract ID.
- Historical preservation.
- Financial accuracy.
- Immutable history after expiration.

---

# Lifecycle

A contract is created when:

- A player signs for a club.
- A staff member is hired.
- A contract is renewed.
- A loan agreement begins.

A contract ends when:

- It expires.
- It is terminated.
- A transfer replaces it.
- A retirement occurs.

Expired contracts remain archived permanently.

---

# Primary Key

Contract ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

---

# Contract Parties

Stores references only.

Fields include:

- Contract ID
- Player ID or Staff ID
- Club ID

Only IDs are stored.

---

# Contract Duration

Stores employment dates.

Examples

- Start Date
- Expiration Date
- Signing Date

Future phases may support optional extension years.

---

# Financial Terms

Stores agreed financial values.

Examples

- Weekly Wage
- Signing Bonus
- Loyalty Bonus
- Appearance Bonus
- Goal Bonus
- Clean Sheet Bonus
- Promotion Bonus
- Relegation Clause

Additional incentives may be introduced in future phases.

---

# Release Clauses

Supported examples:

- General Release Clause
- Domestic Release Clause
- Foreign Club Release Clause
- Minimum Fee Release Clause

Future contract types may extend this list.

---

# Squad Status

Defines expected role.

Examples

- Star Player
- Important Player
- Rotation
- Prospect
- Youth Player

Squad status influences morale and playing-time expectations.

---

# Loan Information

Loan contracts may include:

- Parent Club ID
- Loan Club ID
- Loan Fee
- Wage Contribution
- Recall Option (future)
- Purchase Option (future)

Permanent transfers ignore these fields.

---

# Contract Status

Supported values:

- Active
- Expiring
- Expired
- Loan
- Suspended
- Terminated

DOMAIN-005 Phase 1 uses `pending`, `active`, `expired`, and `terminated`.
Loan, suspension, staff, and detailed clause behavior remain deferred.

---

# Relationships

References:

- Player ID
- Staff ID
- Club ID
- Transfer ID (if applicable)

Only IDs are stored.

Contract records do not contain Player or Club snapshots, squad membership,
competition registration, or Transfer state. A Player has at most one active
permanent Contract at a time.

Phase 1 wage is a non-negative integer in the project's smallest currency
unit. It is a Contract term only; no Club balance or finance ledger is
modified.

---

# Validation Rules

Contract ID cannot change.

Expiration date must occur after the start date.

A player may have only one active permanent contract.

A player on loan may have one parent contract and one temporary loan contract.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

Transfer Module

Full Access

Economy Module

Financial calculations only

Legacy Module

Historical archive only

News Module

Contract announcement references only

---

# Performance Notes

Frequently indexed fields include:

- Contract ID
- Player ID
- Staff ID
- Club ID
- Expiration Date
- Contract Status

Expired contracts should remain in historical storage.

---

# Future Expansion

Future versions may include:

- Agent commissions
- Image rights
- Sponsorship clauses
- Automatic wage increases
- Optional extension clauses
- Contract disputes
- Buy-back clauses
- Sell-on clauses
- Financial Fair Play restrictions

These additions should extend the existing structure without breaking compatibility.

---

# Locked Decisions

✓ Permanent Contract ID

✓ One Record Per Contract

✓ Historical Preservation

✓ One Active Permanent Contract Per Player

✓ Loan Contracts Stored Separately

✓ Modular Ownership

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

## DOMAIN-010 Generated Contracts

Generated Players receive ordinary durable Contracts through the Contract
Module. Terms are deterministic bounded save-initialization data; there is no
wage-budget, renewal, negotiation, or market system in this phase.

END OF DOCUMENT
