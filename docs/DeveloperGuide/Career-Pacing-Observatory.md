# Career Pacing Observatory

The developer-only observatory measures a controlled Player through the real
Career, Match, training, Contract, and Season rollover owners. It is a derived
diagnostic path; it does not create a second simulator, persist checkpoints,
or alter normal Career saves.

Run one archetype or the bounded comparison:

```sh
php game/devtools/console.php career:multi-season-audit --observatory --archetype=prodigy --seasons=5 --seed=13007
php game/devtools/console.php career:multi-season-audit --observatory --archetype=all --seasons=5 --seed=13007
```

`--seasons` is limited to 1–5. `--equivalence` is intended for one archetype
and checks the final persisted World/checkpoint after reopening the isolated
save:

```sh
php game/devtools/console.php career:multi-season-audit --observatory --archetype=regular --seasons=1 --seed=13007 --equivalence
```

The output contains `START`, `SEASON_1`, `SEASON_3`, and `SEASON_5` when the
requested horizon reaches them, plus compact OVR, six-attribute, role, minutes,
availability, Contract, movement, honours, awards, outlook, and retirement
fields. The driver consumes newly scheduled cup/competition fixtures until the
canonical rollover reports the Season complete, and resolves only the existing
controlled Contract decision at a boundary so the next Season can activate.

Each archetype runs with a fresh service graph and an isolated temporary SQLite
save. The same seed, archetype, and horizon should reproduce the same Career
checkpoints. `OBSERVATORY_WARNINGS` are developer signals, not gameplay state;
they currently flag only very early potential reach and a possible low-minutes
role trap for follow-up balance review.
