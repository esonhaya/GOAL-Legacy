# SQLite Performance Profiling

PERF-001 adds an opt-in developer profiler at the existing SQLite boundary.
Normal saves still use `SqliteDatabase` directly. A profiled save creates the
same PDO-backed database with a statement-class decorator, so repositories and
football-domain services do not depend on profiling and no profile data enters
the save.

## Usage

Run the real production audit with profiling enabled:

```text
php game/devtools/console.php career:season-audit --seed=13003 --profile-sql
```

Use `--profile-sql-top=10` to change the concise text report, or
`--profile-sql-json=/path/profile.json` for a compact machine-readable report.
The audit performs EXPLAIN QUERY PLAN only after the Match workload, against
the selected read fingerprints and their first representative parameters.

## What is measured

SQL is fingerprinted by trimming, collapsing whitespace, and removing a
trailing semicolon. Prepared placeholders remain placeholders, so parameter
values do not create separate fingerprints. Each fingerprint retains bounded
calls, total/average/maximum milliseconds, one example parameter set, failed
calls, and (when explainable) plan flags.

The report also records total SQL calls, transaction count and duration, and
commit count and duration. Commit timing is measured only around the existing
PDO commit; transaction boundaries and durability settings are not weakened.

`SCAN` is a plan observation, not automatically a defect. A frequent scan on
a small table may be cheaper than another write-amplifying index. Prioritize a
repair when a hot historical query scans or sorts repeatedly, when a plan uses
`USE TEMP B-TREE`, or when call counts prove N+1 multiplication.

## Index and query workflow

1. Capture the same deterministic workload before and after a change.
2. Rank fingerprints by total time and call count.
3. Explain the union of the top fingerprints once at the end.
4. Compare filter and ordering columns with existing primary keys and
   `PRAGMA index_list`/`PRAGMA index_info` output before adding an index.
5. Prefer removing duplicate work and bounded batch reads before adding
   write-amplifying indexes.
6. Verify gameplay output, save/reload, and structural query invariants;
   avoid hardware-dependent millisecond assertions in PHPUnit.

Repository schema setup is guarded once per live PDO connection. The guard
does not remember DDL executed only inside a transaction because SQLite can
roll that DDL back. Match schemas are warmed before the atomic Match write.
This preserves failure rollback while avoiding repeated hot-path
`CREATE TABLE IF NOT EXISTS` work.

Profiling is developer-only, disabled by default, bounded in memory, and does
not add a cache, ORM, second persistence layer, or simulation clock.

## P3-020 production Career gate

P3-020 measures player-facing production paths and persistent save growth. It
is deliberately separate from the P3-007 longitudinal simulation observatory:
P3-007 measures simulation-engine cost, while this gate measures the real
`WebApplication` read/action paths and the SQLite state those paths reopen.
P3-019 provides the remote CI/browser release checks; it is not a substitute
for this age-and-storage measurement.

Run one bounded deterministic gate with the existing canonical multi-season
runner:

```text
php game/devtools/console.php career:multi-season-audit \
  --seasons=5 --seed=3020 \
  --performance-json="$(php -r 'echo sys_get_temp_dir();')/goal-p3020.json"
```

The command captures `AGE0`, `AGE1`, `AGE3`, and `AGE5` at season boundaries,
then measures a warmup plus two real production GET requests for Career Home,
Profile, Training, Career History, Trophy Room, and available context pages.
It records wall time, SQL calls, DML/data-version changes, response status,
SQLite size/row growth, integrity/FK checks, duplicate primary-key groups, and
the largest tables. A small owned copy is used for the training action probe;
the Match action uses the canonical command path and proves its write through
the save data version. The probe is read-only apart from those explicit action
copies and cleans only its own temporary files.

The JSON output is diagnostic evidence, not a hard millisecond test. A normal
GET must have zero gameplay DML. Durable Match/stat/history growth is classified
as expected linear or event-driven growth; only duplicated, orphaned,
diagnostic, or otherwise unbounded ephemeral state is a storage defect. A
release blocker requires a reproducible write-on-read change, integrity/data
corruption, or material player-facing latency with an identified root cause.
Do not optimize merely because an older Career is numerically slower.

The output path must be writable by the local shell. On Termux, `sys_get_temp_dir()`
may be under the writable application temp directory rather than `/tmp`.
Reuse the generated JSON/checkpoints when comparing ages; do not regenerate a
five-season Career repeatedly. No timing threshold should be added to PHPUnit:
environment-dependent timings are reported as evidence and classified as
`HEALTHY`, `WATCH`, `MATERIAL_BOTTLENECK`, or `CLEAR_DEFECT`.

## P4-001 mobile storage foundation

P4-001 separates storage lifecycle work from historical gameplay compaction.
It does not delete Match statistics, highlights, Season history, registrations,
memberships, role history, or development history. Those families remain in the
save until their active and historical consumers are traced in a later batch.

### Ownership classes

The configured `game/saves` root is the only canonical save root. A file is
automatically actionable only when its location and convention identify it:

| Class | Evidence and policy |
| --- | --- |
| `NORMAL_CAREER_SAVE` | Valid `core_save_metadata`, `sandbox=false`, canonical `<id>.sqlite`. |
| `SANDBOX_SAVE` | Valid metadata with `sandbox=true`; `sandbox_source_id` is informational and does not create a delete cascade. |
| `TEMP_TEST_SAVE` | Private temporary directory with a repository-owned `goal-legacy-*` convention, or an exact `.career-preview-*` directory. |
| `TEMP_SIMULATION_SAVE` | Scenario, multi-season, observatory, or season-audit owned temporary directory. |
| `BROWSER_TEST_SAVE` | Valid canonical save whose fixture ID starts with `p3019-browser-`. |
| `RECOVERY_SNAPSHOT` / `SANDBOX_SNAPSHOT` | Reserved explicit classes; there are no external snapshot files in the current implementation. Existing Sandbox snapshots are save-owned database state. |
| `DIAGNOSTIC_ARTIFACT` | P3-020 probe directories and other exact diagnostic conventions. |
| `UNKNOWN_EXTERNAL` | Corrupt/unreadable SQLite, arbitrary sidecar, symlink, path, or file without a known convention. Never automatically deleted. |

`OwnedArtifactCleanup` accepts no arbitrary glob. It validates the storage
root, rejects symlinks, and removes only exact GOAL-owned sidecars or stale
owned directories. Its explicit maintenance call is bounded by root and age;
normal GET rendering never invokes it. Browser fixtures retain their own exact
prefix guard and cleanup. Simulation Lab, ScenarioFixture, the P3-020
performance probe, and the multi-season audit share the same owned-directory
cleanup boundary and all keep cleanup in `finally` paths.

### Career and Sandbox lifecycle

Save management exposes the metadata name, last-updated value, measured file
size, Sandbox marker, `Delete Career`/`Delete Sandbox`, and `Optimize Storage`.
Deletion is a confirmation page followed by a one-use CSRF/action-token POST.
The server validates the save ID, opens canonical metadata, checks account
ownership, then deletes the canonical SQLite file and only the exact SQLite
sidecars associated with that validated ID. A GET cannot delete. A missing
save is an idempotent no-op at the store boundary; a replayed web token is
rejected. Source Careers and Sandbox children are independent: deleting one
does not cascade to the other. Unknown nearby files survive.

The `storage:inspect` developer command is the deliberate maintenance
boundary for inventory, attribution, bounded orphan cleanup, and optional
compaction. It may be run with a save ID, `--cleanup`, or `--compact=<id>`.
No storage cleanup or `VACUUM` occurs on a save list, Career Home, Profile, or
History GET.

### Attribution evidence

`StorageInventory` reports canonical and owned temporary artifacts without
claiming ownership of the rest of the OS temporary directory. For the save
root it reports normal/Sandbox/browser/unknown counts and owned/unknown bytes.
`SqliteStorageAttribution` reports SQLite-native values for a representative
save:

- file size, page size, page count, freelist count, used-page approximation,
  free-page approximation, journal mode, auto-vacuum, integrity, and FK checks;
- row count for every application table;
- `dbstat` page bytes, payload/unused bytes, database percentage, and index
  bytes when the runtime exposes `dbstat`; otherwise the report says
  `UNAVAILABLE` rather than inventing precision;
- growth classification, known consumers, and retention class.

The P4-001 retention map currently classifies the largest known families as:

| Family | Current consumers | Retention class |
| --- | --- | --- |
| Registrations | Competition eligibility and transfer logic during active Seasons | `ACTIVE_REQUIRED` |
| Squad memberships | Active squad, movement, selection, Career History | `PLAYER_HISTORY_REQUIRED` |
| Role history | Profile and progression history | `PLAYER_HISTORY_REQUIRED` |
| Development history | Training feedback, Profile, Career History | `PLAYER_HISTORY_REQUIRED` |
| Match records/highlights/selections | Match loading, Match Story, saved history/audit | `WORLD_HISTORY_REQUIRED` |
| Match player statistics | Profile, Career History, Trophy/records aggregation | `PLAYER_HISTORY_REQUIRED` |
| Season and competition statistics | Profile, leaders, awards, Trophy/records | `PLAYER_HISTORY_REQUIRED` / `WORLD_HISTORY_REQUIRED` |
| Career events/awards/honours/records | Decisions, Pulse/news, Legacy and Trophy Room | `PLAYER_HISTORY_REQUIRED` |
| Pulse sources/posts/threads | Pulse/news rendering and deduplication | `WORLD_HISTORY_REQUIRED` |

Unknown tables are reported as `UNKNOWN_CONSUMER`. For every later candidate,
P4-002/P4-003 must still answer who writes it, active-season use, finalization
use, Career History/Profile/Trophy/leaderboard/award/Pulse/development use,
contract/transfer use, derivability, NPC summarization safety, and whether
controlled-player evidence needs richer retention. P4-001 provides the map;
it does not perform destructive historical compaction.

### Physical reclamation boundary

Deleting rows and shrinking the file are separate operations. `SqliteSaveStore`
captures file/page/freelist/integrity/FK metrics, runs SQLite `VACUUM` only on
an explicitly requested canonical save, and reopens it through the canonical
metadata path before reporting success. It refuses an unknown/corrupt save and
an active transaction; SQLite's busy timeout/locking remains the concurrency
boundary. It is never called during Match simulation, Season processing, a
normal GET, or after every small deletion. A failed operation is reported as a
maintenance failure and is not reported as a successful optimized save.

The player-facing Optimize Storage action supplies a read-only canonical
semantic checkpoint containing controlled Player, Club, Contract/loan, role,
availability, Season/date, and Career-history projections. The store compares
that checkpoint before and after `VACUUM` and reports `PRESERVED`; an incomplete
metadata-only file uses a bounded metadata checkpoint and is not allowed to
initialize gameplay schema as a side effect.

The P4-001 fixture deliberately creates and removes non-gameplay diagnostic
rows to produce free pages, then proves:

```text
FILE_SIZE_AFTER < FILE_SIZE_BEFORE
integrity_check=ok before and after
foreign_key_violations=0 before and after
metadata/semantic checkpoint unchanged
canonical SaveStore reopen=PASS
```

The measured reduction is not a gameplay target. P3-020 remains the official
healthy long-career baseline: AGE5 `102010880` bytes, approximately `17.8 MB`
per Season, and the old Profile watch of about `5.4 s`/`4139` queries. Phase 4
directional targets are AGE5 `<=40 MB`, stretch `25–30 MB`, and steady growth
`<=5 MB`/Season (stretch `<=3 MB`/Season); P4-001 does not enforce them.

Next safe candidates are the largest `dbstat`-measured historical families
whose consumers are proven, with controlled-player evidence retained richer
than NPC evidence where the product contract permits it. No P4-001 operation
changes match simulation, season simulation, formulas, balance, or gameplay
fidelity.

## P4-002 historical world retention

P4-002 makes the first row-aware historical retention reduction. The canonical
owner is `SeasonCompactionService`, invoked at the safe completed-Season to
next-Season boundary by `CareerContinueCommand`. `CareerCompactCommand` invokes
the same owner for older completed Seasons and performs one explicit physical
compaction after the logical work when the threshold recommends it. A normal
GET never invokes either operation.

The retention contract is three-tiered:

| Tier | Durable representation |
| --- | --- |
| A — controlled Player | Rich Match, Season, development, availability, movement, contract, role, award, trophy, milestone, and Career-history evidence remains. |
| B — player-relevant world | Finalized Match facts and detail for Matches involving the controlled Player's Season Club remain available; existing Season/competition aggregates and movement/competition records remain authoritative. |
| C — background world | Replay-only NPC Match detail is removed after finalization; existing Season/competition aggregates and form summaries carry the minimum durable performance facts. |

The targeted families are `match_player_stats`,
`match_player_selections`, `match_highlights`, `match_substitutions`,
`career_match_evaluations`, `player_development_history`, and
`player_availability_sources`. `match_records` is never removed. A Match is
protected when its home or away Club has a current-Season squad membership for
the controlled Player, so Match Story and player-relevant context do not lose
their source facts. NPC rows in unrelated finalized Matches are eligible.

The consumer map for the targeted families is:

| Family | Active consumer | Post-Season consumer | P4-002 representation |
| --- | --- | --- | --- |
| Match stats/selections | selection, availability, Club recruitment, Match lifecycle | performance, Profile, leaders, records | existing Season/competition aggregates for background NPCs; rich rows for controlled/relevant Matches |
| Highlights/substitutions | Match Story and integrity | controlled/relevant Match history | retain protected Matches; remove background replay detail |
| Match evaluations | development/form feedback, national-team selection | form summary | retain the newest two NPC evaluations per Player/Club and fold older rows into existing `player_form_summaries`; readers merge both fidelity boundaries |
| Development history | development and feedback | controlled Career history | retain controlled Player history; remove expired NPC history once the next Season boundary is reached |
| Availability sources | selection and availability | controlled history where consumed | retain controlled Player sources; remove expired NPC sources |
| Season/competition stats | active performance and awards | Profile, leaders, records, Trophy Room | retained; these are the durable background summary boundary |

Registrations, memberships, role history, contracts, `player_records`,
`match_records`, Season/competition outcomes, awards, records, and controlled
Player detail are deliberately deferred. They have active or historical
consumers whose P4-002 evidence is not sufficient for destructive removal.
This is why the batch does not claim that a whole table is one retention tier.
P4-003 candidates are registrations/membership operational history after
movement summaries are proven complete, NPC role/development residue not used
by future selection, and any remaining large world indexes or detail families
identified by a fresh byte-first attribution report.

### Compaction safety and idempotency

Logical compaction requires a persisted `season_records` row with
`status=completed`, all Match rows for that Season to be `completed`, and all
persisted Competition rows for that Season to be `completed` when that table is
present. It captures row/page/freelist and targeted `dbstat` measurements,
materializes missing background Season aggregates through the existing
`player_season_statistics` boundary, then deletes eligible rows inside one
canonical transaction. `save_compaction_seasons` is the durable idempotency
marker. A repeated run returns an idempotent result and makes no further
semantic change. A failure rolls back the marker, aggregates, and deletions
together.

Logical row removal and physical file reclamation remain separate. Physical
`VACUUM` is recommended only when the post-logical freelist is at least 1 MiB
or at least 10% of database pages. It is never run inside the Season
transaction, Match simulation, Season finalization, or normal rendering.
`SqliteSaveStore` performs the explicit VACUUM with integrity/FK checks and a
canonical reopen. P4-002's deterministic integration fixture proves material
(at least 50%) reduction in targeted eligible detail bytes, file shrinkage,
`integrity_check=ok`, zero FK violations, unchanged controlled-player/world
checkpoints, idempotency, rollback, and continued canonical reads. It does not
claim an AGE5 result; P4-004 remains the authoritative longitudinal gate.

The production readers for Season performance and competition statistics use
the existing compact aggregates for background NPCs and retain detailed
evidence for controlled Players. This prevents a mixed save that retains raw
detail for Match Story from double-counting an NPC's Season performance. No
formula, Match result, Season result, transfer rule, or simulation behavior is
changed.

## P4-003 post-compaction longitudinal storage gate

P4-003 extends the existing `career:multi-season-audit --performance-json`
runner. With `--seed=3020 --seasons=5`, it creates one comparable Career and
captures external AGE0, AGE1, AGE3, and AGE5 JSON checkpoints. Each checkpoint
contains the player age/Season/date/Club/role, file/page/freelist metrics,
SQLite integrity/FK results, row counts, full `dbstat` table/index attribution,
read-path timing/query/DML samples, and the compaction result for the completed
Season immediately before it. The report is written outside the save and is
removed with the owned run artifacts after inspection.

The performance-gate runner invokes the canonical `SeasonCompactionService`
after each completed Season, then invokes canonical `SqliteSaveStore::compact`
when the P4-002 1 MiB/10% freelist threshold recommends physical reclamation.
It closes and reopens the save around VACUUM, captures all five boundary
results, and reruns the AGE5 eligible Season compaction once. The second run
returned `idempotent=true` and `logical_rows_removed=0`.

### SEED=3020 measured trajectory

| Checkpoint | Player age | Date | File bytes | Rows | Pages | Freelist | Integrity/FK |
| --- | ---: | --- | ---: | ---: | ---: | ---: | --- |
| AGE0 | 19 | 2024-07-31 | 13,021,184 | 37,167 | 3,179 | 0 | `ok` / 0 |
| AGE1 | 20 | 2025-06-01 | 20,176,896 | 62,566 | 4,926 | 0 | `ok` / 0 |
| AGE3 | 22 | 2027-06-01 | 48,062,464 | 144,507 | 11,734 | 0 | `ok` / 0 |
| AGE5 | 24 | 2029-06-01 | 74,829,824 | 221,095 | 18,269 | 0 | `ok` / 0 |

The starting state is apples-to-apples with P3-020: the same seed, runner,
population, initial Career installation, AGE0 bytes, and AGE0 rows were
reproduced. P4-003 adds completed-Season logical compaction and thresholded
physical VACUUM after AGE0; therefore the post-AGE0 comparison is the
post-compaction trajectory rather than an unmodified reproduction.

Against P3-020, the byte comparison is:

| Checkpoint | P3-020 bytes | P4-003 bytes | Saved | Reduction |
| --- | ---: | ---: | ---: | ---: |
| AGE0 | 13,021,184 | 13,021,184 | 0 | 0% |
| AGE1 | 23,314,432 | 20,176,896 | 3,137,536 | 13.46% |
| AGE3 | 63,299,584 | 48,062,464 | 15,237,120 | 24.07% |
| AGE5 | 102,010,880 | 74,829,824 | 27,181,056 | 26.65% |

Rows changed from `37,167 → 62,566 → 144,507 → 221,095`. Relative to the
P3-020 row baseline, AGE1 saved 4,167 rows, AGE3 saved 27,485, and AGE5 saved
50,954. The measured post-AGE0 growth is:

```text
AGE0_TO_AGE1                 7,155,712 bytes / 7.16 MB
AGE1_TO_AGE3                 13,942,784 bytes / Season / 13.94 MB
AGE3_TO_AGE5                 13,383,680 bytes / Season / 13.38 MB
AVERAGE_POST_AGE0            12,361,728 bytes / Season / 12.36 MB
P3-020_ENDPOINT_AVERAGE      17,797,939 bytes / Season / 17.80 MB
GROWTH_REDUCTION             30.54%
```

P4-002 therefore provides a material reduction, but the AGE5 `<=40 MB` and
steady-state `<=5 MB/Season` targets are not yet achieved. The run completed
1,900 Matches, preserved the controlled Player at Arsenal, and produced no
reload failures.

### Compaction effectiveness

| Completed Season | Logical rows removed | Target bytes before → after | Physical bytes reclaimed |
| --- | ---: | ---: | ---: |
| 2024/25 | 4,213 | 5,181,440 → 3,911,680 | 3,178,496 |
| 2025/26 | 11,462 | 9,572,352 → 6,455,296 | 6,975,488 |
| 2026/27 | 11,200 | 10,821,632 → 7,798,784 | 7,606,272 |
| 2027/28 | 11,353 | 12,386,304 → 9,334,784 | 8,404,992 |
| 2028/29 | 11,387 | 14,049,280 → 11,018,240 | 9,117,696 |

Totals were 49,615 logical rows and 35,282,944 physical bytes across five
threshold-approved VACUUM operations. Every physical result reported
`integrity_check=ok`, zero FK violations, and zero freelist pages afterward.
The threshold behaved sensibly: each logical compaction created approximately
1.27–3.12 MiB of free pages, so the 1 MiB byte threshold—not an artificially
low ratio—triggered physical reclamation.

The production Career generated almost exclusively controlled-Club detailed
Match evidence. Consequently, P4-002 removed highlights, older evaluations,
and NPC development rows, while Match stats/selections/substitutions were
protected by the player-relevant Match rule. This is evidence that further
blanket Match-detail deletion would not explain the remaining growth.

### AGE5 attribution

Termux exposed SQLite `dbstat`. AGE5 used 74,829,824 database bytes, of which
31,604,736 bytes (42.24%) were indexes. The largest table-plus-index families
were:

| Family | Table/index bytes | Main evidence |
| --- | ---: | --- |
| Player registrations | 15,138,816 | 53,506 rows; three registration indexes total 11,378,688 bytes |
| Squad role history | 8,593,408 | 32,578 rows; player-history index 3,514,368 bytes |
| Contracts | 5,455,872 | 14,689 rows; status/club/expiry indexes |
| Squad memberships | 5,136,384 | 29,414 rows; club/player indexes |
| Competition statistics | 3,796,992 | 15,221 durable aggregate rows |
| Match selections | 2,928,640 | 9,789 protected/current-detail rows and indexes |
| Season statistics | 1,732,608 | 12,585 durable summary rows |

The top AGE5 indexes were `idx_player_registration_club`,
`idx_player_registration_competition`, `idx_player_registration_player`,
`idx_club_squad_role_history_player`, the three Contract status/expiry indexes,
the two squad indexes, and the two competition-statistics indexes. No index
was dropped: all remain candidates for a separate query-plan-backed review.

### Consumer decision

The single next target is `player_competition_registrations`. It is the
largest measured family at 15,138,816 bytes including indexes and grows with
each Season. Its confirmed consumers are active Match eligibility,
Season activation, population registration, transfers/loans, and registration
validation against current squad/Contract state. Historical deletion is not
safe yet because `byPlayer()` and movement/registration paths still expose
all Seasons and the required Career-history replacement has not been proven.

P4-003 does not modify registrations. The proposed future boundary is after
Season finalization and movement/eligibility work, retaining current-season,
controlled-player, and player-relevant registrations while replacing or
summarizing only proven historical operational rows. This is one target for a
future batch, not authorization for speculative deletion. Role history,
memberships, Contracts, player records, and indexes remain deferred.

### Longitudinal health

Career Home, Profile, Training, Career History, and Trophy Room returned HTTP
200 at AGE5 with `DML=0` and zero `data_version` changes. AGE5 Profile measured
5,649.91 ms and 3,819 queries versus the P3-020 watch of approximately
5,399 ms and 4,139 queries; this is classified `SIMILAR`, not a P4-003
optimization target. The AGE5 controlled Career/Profile/History/Trophy reads,
league champions/standings, Match results, SaveStore reopen checks, and the
P4-002 semantic tests remained valid. No gameplay formulas, Match engine,
Season results, or world content were changed.

## P4-004 end-of-Season archival

P4-004 adds the end-of-Season `SeasonArchiveService` boundary. The production
ordering is: complete the old Season, resolve its competition/world outcomes,
activate the successor, then archive the old Season. Archival is rejected when
the old Season is not `completed`, the successor is not `active`, the World
does not point at the successor, historical Matches/Competitions are not
complete, successor squads are absent, or the canonical controlled-player
reference is absent. It is never called from a normal GET, Match simulation, or
an active Season-finalization transaction.

The storage contract is deliberately row-aware:

| State | Durable representation |
| --- | --- |
| Active Season | Full operational registrations, squads, contracts, role/development state, Match detail, and current indexes. |
| Completed Season | Existing Season/competition aggregates, Career History, movement, awards, trophies, records, and durable world outcomes. Replay-only detail is not a second archive. |
| Controlled Player | Rich historical registrations, memberships, role/development/contract/movement evidence, Season/Career statistics, and any existing player-facing Match facts. |
| NPC/world | Old operational registrations and role transitions are removable after successor activation; expired NPC contracts are removable only when unreferenced by movement and loans; same-Club NPC memberships remain when tenure is still a gameplay input. |
| Match evidence | P4-002 removes obsolete NPC detail; `match_records`, Season/competition summaries, controlled/relevant history, and durable outcomes remain. P4-004 does not remove background Match records without a separate consumer proof. |

The consumer audit found that registrations feed active eligibility, Season
activation, movement, and transfer/loan validation; memberships feed current
squads plus captaincy/set-piece tenure; role history is consumed by controlled
progression/profile; and Contracts remain operational or are referenced by
movement. Accordingly the canonical P4-004 deletion unit removes old
non-controlled registrations, old NPC role history, and old NPC memberships
only when a same-Club successor membership is not needed. Expired NPC Contracts
are guarded against movement references and active loans; no eligible rows
were present in the representative fixture. Controlled-player rows are never
selected by these predicates. P4-002 remains the nested owner for NPC Match
detail, development evidence, evaluations, and availability sources.

`save_archival_seasons` is the minimal idempotency marker. The P4-004 logical
deletion unit and marker insert share one canonical transaction; a failure
rolls back the family deletions. A second call returns `idempotent=true` and
zero new rows. Physical reclamation remains separate: the existing P4-001
SaveStore VACUUM boundary runs only after the logical transaction when free
space is at least 1 MiB or 10% of pages, then verifies integrity, FK count,
semantic state, and canonical reopen.

### P4-004 representative evidence

The reusable `GoalScenarioBuilder::buildFinalizedSeasonWithActiveSuccessor`
fixture uses canonical World, Match, rollover, squad, registration, and
production persistence services. It does not implement Match or Season
formulas. Its completed Season and active successor measured:

| Metric | Before | After logical archive | After VACUUM |
| --- | ---: | ---: | ---: |
| File bytes | 28,110,848 | 28,131,328 | 18,812,928 |
| Rows removed | — | 15,968 P4-004 + 4,195 P4-002 | — |
| Physical reclamation | — | — | 9,318,400 bytes |
| Integrity / FK | `ok` / 0 | checked | `ok` / 0 |

The targeted registration/membership/role/Contract family footprint fell from
12,886,016 to 9,924,608 attributed bytes. Registration rows fell by 10,700,
role-history rows by 5,223, and memberships by 45; the controlled player,
standings, historical Match result, NPC Season aggregate, next-Season
eligibility, canonical next Match result, and SaveStore checkpoint were
preserved. The second archive removed zero rows. The bounded next-Match result
matched an untouched owned comparison save. The integration test also proved
transaction rollback with an injected archive failure and verified Home,
Profile, History, and Trophy reads performed zero DML.

P4-004 is a material finalized-Season reduction, not an AGE5 replacement
measurement. The authoritative longitudinal trajectory remains P4-003:
AGE5 `74,829,824` bytes and `12.36 MB`/Season post-AGE0 versus P3-020's
`102,010,880` bytes and `17.8 MB`/Season. A fresh final Phase 4 longitudinal
gate is still required. Its one primary follow-up target is the remaining
`player_competition_registrations` footprint (including its indexes), but only
after current-season, movement, and controlled-player consumers are shown to
be replaceable by existing durable history. Contracts, memberships, role and
development history, Match core/detail, Season/competition statistics,
player records, and Pulse/news remain consumer-deferred where the evidence is
not yet sufficient. No Match result, simulation formula, world population,
competition, or player-facing Career history was reduced.
