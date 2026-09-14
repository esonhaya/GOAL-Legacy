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
