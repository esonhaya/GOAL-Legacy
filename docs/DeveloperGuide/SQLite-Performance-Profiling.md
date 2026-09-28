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
