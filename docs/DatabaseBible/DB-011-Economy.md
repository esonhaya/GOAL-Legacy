# GOAL: Legacy
# Database Bible

Document ID: DB-011
Title: Economy Database

Version: 1.0
Status: Draft
Author: Jaime Haya
Last Updated: 2026-07-14

---

# Purpose

The Economy table stores the financial state of every football organization within GOAL: Legacy.

The Economy system ensures that every financial transaction is traceable, realistic, and historically preserved.

Money is never created or removed without an identifiable source.

---

# Ownership

Primary Module

Economy Module

Supporting Modules

- Club
- Competition
- Transfer
- Contract
- Legacy
- World

Only the Economy Module may directly modify financial records.

---

# Design Principles

- One record per financial entity.
- Permanent Economy ID.
- Complete financial traceability.
- Historical preservation.
- Event-driven accounting.

---

# Lifecycle

An Economy record is created when a financial entity is created.

Examples:

- Football Club
- Competition
- Federation (future)

Financial records remain permanently archived.

Annual snapshots are generated at the end of each season.

---

# Primary Key

Economy ID

Properties

- Permanent
- Unique
- Never reused
- Never edited

---

# Financial Entity

Stores references only.

Examples

- Club ID
- Competition ID

Future phases may support additional financial entities.

---

# Current Balance

Stores real-time values.

Examples

- Available Cash
- Operating Balance
- Reserve Funds

Values update after every financial transaction.

---

# Revenue Streams

Examples

- Ticket Sales
- Sponsorship
- Broadcasting Revenue
- Prize Money
- Merchandise
- Player Sales
- Loan Fees

Future revenue types may be introduced.

---

# Operating Expenses

Examples

- Player Wages
- Staff Wages
- Stadium Costs
- Training Facilities
- Medical Costs
- Scouting Costs
- Transfer Fees
- Loan Fees

---

# Financial Transactions

Every financial movement is recorded.

Examples

- Transaction ID
- Date
- Amount
- Currency
- Category
- Source
- Destination

Transactions are immutable after confirmation.

---

# Budgets

Examples

- Wage Budget
- Transfer Budget
- Operational Budget

Budgets are planning tools and do not replace actual balances.

## Controlled Player Finance Boundary (P2-005)

The Economy module remains the owner of organizational football finance. The
controlled career Player has a separate, intentionally small `PlayerFinance`
boundary for personal balance, weekly Contract-wage receipts, meaningful
expenses, and bounded lifestyle ownership. It is not an NPC economy and does
not create personal rows for the generated Player population. Its ledger is
controlled-Player career state and consumes Contract dates and wage metadata;
it does not mutate Club balances or replace Contract ownership.

---

# Financial Health

Examples

- Profit
- Loss
- Net Worth
- Debt
- Liquidity

These values influence AI decision making.

---

# Relationships

References:

- Club ID
- Competition ID
- Transfer ID
- Contract ID

Only IDs are stored.

---

# Validation Rules

Economy ID cannot change.

Balances may never become invalid through direct modification.

Every transaction must reference a valid source.

Every transaction must reference a valid destination.

---

# Read Permissions

Readable by all gameplay modules.

---

# Write Permissions

Economy Module

Full Access

Transfer Module

Transfer payment references only

Competition Module

Prize payment references only

Legacy Module

Historical archive only

---

# Performance Notes

Frequently indexed fields include:

- Economy ID
- Club ID
- Transaction Date
- Transaction Category

Historical transactions remain permanently archived.

---

# Future Expansion

Future versions may include:

- Financial Fair Play
- Inflation
- Exchange Rates
- Dynamic Sponsorship Markets
- Investor Ownership
- Stadium Financing
- Banking Systems
- Taxation

These additions extend the architecture without breaking compatibility.

---

# Locked Decisions

✓ Permanent Economy ID

✓ Event-Driven Accounting

✓ Complete Transaction History

✓ Historical Preservation

✓ Modular Ownership

✓ No Untraceable Money

---

## Revision History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-14 | Initial draft |

---

END OF DOCUMENT
