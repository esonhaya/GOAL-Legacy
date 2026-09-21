# Controlled career Contract decisions

Controlled Players use the existing `CareerOpportunity` persistence at the
outgoing Season Contract boundary. NPC Players continue through the
deterministic Contract policy in `SeasonRolloverService`.

## Boundary and rollover

When an expiring controlled Player has a current-Club renewal under the NPC
policy or a bounded external fit, one `contract_renewal` opportunity is
created. Its stable source key is the Player, current Club, and next Season.
The next Season cannot materialize while that decision is open. This keeps
the decision before registration, recruitment, and fixture activation while
leaving the current Season historical.

Options are limited to a current-Club renewal, a small deterministic list of
external free-signing offers, and entering free agency. Each concrete Club
offer stores the Club, bounded Season term, weekly GC wage, expected
`SquadRole`, and expiry date in the existing opportunity context. Those are
decision terms, not a second Contract ledger; `ContractService` and the
canonical `TransferService` execution path remain authoritative.

The controlled Player may submit one bounded wage counter for one concrete
offer. The request is deterministic, limited to a modest increase, and uses
Club reputation only as an existing stature/fit boundary; it is not a Club
wage-budget model. An accepted counter revises the stored offer and remains
open for explicit acceptance. A rejected counter leaves the original offer
available. Reloading cannot reroll terms, and a second counter is rejected.
There are no agents, fees, clauses, bonuses, or negotiation personalities.

## Resolution and free agency

Resolution is idempotent: a resolved opportunity returns its stored result,
and a stale or unknown option is rejected. Acceptance uses the stored term,
wage, and expected role exactly once; renewal and external signing use
the canonical free-agent Contract/squad/registration path; no transfer-fee
record is created for an out-of-contract Player. Historical Contracts and
registrations remain durable.

Entering free agency leaves the active Player controlled, without a current
Club, Contract, squad membership, or fixture participation. The Player is
not retired or deleted. `CareerMovementService::evaluateFreeAgentOffers()`
can later create another bounded decision when a legitimate Club vacancy is
available.

Promotion/relegation does not force a Contract decision or transfer. The
decision context uses the Club membership and Competition state from the
outgoing Season; the next Season's normal membership, registration, and
fixture lifecycle remains authoritative.

The active Contract remains the owner of current Club, dates, and wage. The
weekly controlled-Player payroll consumes the accepted Contract wage through
the existing finance ledger and creates no signing payment. Squad role remains
the Club/Season membership owner; an expected Contract role does not guarantee
selection. Early renewals, mid-contract requests, loans, agents, release
clauses, bonuses, morale, and Club wage-budget simulation remain deferred.
