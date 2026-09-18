# DB-018 — Football Social State

P2-010 adds five additive, controlled-career tables:

| Table | Responsibility |
|---|---|
| `player_social_states` | Current public profile, international profile, Club standing, supporter score/sentiment, and manager context. |
| `player_social_club_context` | Compact current/former Club snapshots, including former Club history. |
| `player_social_relationships` | At most eight active controlled-Player relationships to real Player IDs. |
| `player_social_history` | Visible landmark public, relationship, rivalry, transfer, and Match context. |
| `player_social_sources` | Idempotency markers for consumed football facts and choices. |

Rows are created only for IDs in `career_player_references`. NPC Players do
not receive reputation, fan state, manager rows, or relationship matrices.
Presentation values are derived from bounded integers; no rendered or bracket
snapshot data is stored. `source_key`/primary-key guards make Match, transfer,
Career Event, reload, and double-submit processing safe.

The schema is initialized additively by World load/initialize and by the
canonical owners that may consume a legacy save. Reading context does not
insert a Player row. Initial bootstrap is current-state only and does not
rewrite Match or Career History.
