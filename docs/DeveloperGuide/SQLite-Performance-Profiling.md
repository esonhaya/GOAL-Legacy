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
