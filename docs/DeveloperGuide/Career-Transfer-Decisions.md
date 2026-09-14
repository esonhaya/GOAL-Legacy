# Controlled career transfer decisions

The pre-season Club recruitment checkpoint reuses the existing bounded NPC
candidate search. When that search identifies the controlled Player as a
legitimate contracted candidate, it does not execute the NPC transfer. The
Club market instead creates one `transfer_interest` CareerOpportunity with a
`stay` option and at most three deterministically ranked destination options.

The opportunity is created only for an active, contracted Player whose
Contract continues beyond the outgoing Season. An expiring Contract is owned
by the DOMAIN-019 Contract decision flow and never receives both decision
types at the same boundary. There is no daily tick, player-requested listing,
negotiation, wage system, agent, or competing-bid system.

Accepting a destination delegates the complete contracted movement to
`TransferService`: the source Contract and registration are closed, the
destination Contract and squad membership are created, and current
registration is materialized through the canonical owners. Choosing `stay`
resolves the opportunity without changing Club, Contract, or registration.
The Player ID and all historical records are preserved.

Before accepting, the decision revalidates the controlled career identity,
source Contract and squad, destination seasonal membership and capacity,
source positional viability, and same-window movement state. A missing
destination vacancy makes an old offer stale rather than substituting another
Club. Source and destination state remain safe under the existing 25-player
squad policy. Stable Season/Player/Club keys make creation and resolution
retry-safe; a completed transfer replay resolves the stored opportunity
without executing movement twice.

Promotion/relegation changes the seasonal Club context consumed by the same
market and does not force an offer or movement. NPC Players continue to use
the existing automatic path. Player-requested transfers, loans, fees or
finance, agents, counteroffers, mid-season windows, and negotiation remain
deferred.
