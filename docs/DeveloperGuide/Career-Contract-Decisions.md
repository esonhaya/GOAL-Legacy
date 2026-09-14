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
external free-signing offers, and entering free agency. There are no wages
negotiation, agents, fees, clauses, or counteroffers. The current Club and
external options reuse the existing Contract and `TransferService` owners.

## Resolution and free agency

Resolution is idempotent: a resolved opportunity returns its stored result,
and a stale or unknown option is rejected. Renewal and external signing use
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

Early renewals, mid-contract requests, loans, negotiation, finance, agents,
release clauses, bonuses, morale, and retirement choices remain deferred.
