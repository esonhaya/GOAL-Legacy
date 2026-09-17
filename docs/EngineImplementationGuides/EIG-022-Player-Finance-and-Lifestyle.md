# GOAL: Legacy
# Engine Implementation Guide

Document ID: EIG-022
Title: Controlled Player Finance and Lifestyle
Version: 1.0
Status: Phase 2 implementation guide

## Ownership

`PlayerFinanceService` owns controlled-Player balance changes. It uses
`PlayerFinanceRepository` for the additive finance state, transaction ledger,
and lifestyle ownership tables. `ContractService` remains the owner of wage,
Contract dates, renewal, transfer, expiry, and free agency. Finance consumes
those canonical facts and does not mutate Contracts or Club finance.

The service is deliberately not an NPC economy. Automatic payroll begins only
from `career_player_references`, so generated Players with wage metadata never
receive personal balances, transactions, or assets.

## Accounting contract

Phase 2 uses normalized integer `GC` units. Contract wages are weekly. New
Careers receive one deterministic opening transaction of `GC 50`; legacy
Careers initialize at first feature activation without historical backfill.
Payroll advances from the persisted weekly cursor to the simulated date and
uses `wage:<contract-id>:<period>` as its stable source identity. A repeated
date, reload, refresh, or Continue cannot create a second receipt.

Every balance mutation is a ledger transaction with date, type, amount,
before/after balance, source identity, and human context. Purchases validate
the installed catalog price, balance, and ownership inside one transaction;
the debit and ownership row either both commit or neither does. V1 is
purchase-only and has no resale market.

## Lifestyle effects

The catalog is small and data-driven. Effects use a bounded vocabulary such as
training support, recovery support, travel convenience, and event-context
weights. They are presentation/event context only unless a later canonical
consumer is added. They never directly change attributes, OVR, potential, or
Match outcomes. Duplicate effect stacking is not used in V1; consumers should
use the strongest relevant owned context if they later consume these values.

## Runtime and persistence rules

World calendar advancement is the only automatic payroll trigger. Finance
summary, Career Home, Finances, Lifestyle, and Player Profile are read paths
and do not process payroll. Event money choices call the finance service with
an event-and-choice source key, so event replay cannot duplicate a debit or
credit. Finance rows are compact controlled-career state; portraits and other
rendered assets never enter the Career SQLite file.

END OF DOCUMENT
