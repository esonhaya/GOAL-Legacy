# Career Outlook

`CareerOutlookService` derives the read-only `career_outlook` section of the
`PlayerCareerProgressionQuery` Career Hub. It does not persist recommendations,
create opportunities, or mutate Player, Club, Contract, role, or transfer
state.

## Evidence and precedence

The outlook uses the Hub's current Contract/Club state, canonical squad role,
same-position squad OVR counts, completed-Season performance assessment,
normalized minutes/starts shares, recent form, transfer-request state, and
open CareerOpportunity records. Immediate states take precedence: free agent,
pending Contract decision, pending transfer interest, active transfer request,
and approaching Contract expiry. Only then are progression states derived:
breaking through, blocked path, needs minutes, competing for a role, or good
situation.

Playing opportunity is bounded to `high`, `moderate`, `low`, or `none`. A
blocked path requires low opportunity, at least two higher-OVR same-position
Players, and limited evidence; limited performance alone is not a blocked
label. Strong or breakout evidence can describe breaking through but never
changes role, selection, development, Contract policy, or market scoring.

Guidance contains stable codes/messages and, where applicable, an existing
action type such as `request_transfer`, `withdraw_transfer_request`, or
`resolve_opportunity`. No action is executed by the read model. Outlook is
reconstructed after save/reload and has no separate persistence or narrative,
morale, promise, or recommendation system.
