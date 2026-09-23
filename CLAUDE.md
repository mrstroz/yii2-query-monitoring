# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## State of the repository

Milestones E0 (`YQM-1`..`YQM-12`) and E1 (`YQM-13`..`YQM-18`) are finished: SQL monitoring for MySQL and PostgreSQL over HTTP requests, written by default to a rotated JSON Lines file (`FileAdapter`) or handed to an adapter the application sets; the CI workflow runs the tests on both databases. E2 (`YQM-19`..`YQM-26`, test architecture and conventions) is finished: the suite is split into four testsuites (`Unit`, `Yii`, `Filesystem`, `Process`), the conventions are written down in `tests/README.md` and applied, and the whole matrix (PHP 8.1 and 8.4, MySQL and PostgreSQL) is green. E3 (entry context and limits) is under way: `YQM-27` measured the stack with a probe in the test application (`tests/app/CallerProbe.php`, `CallerProbeTest`), and `YQM-28` introduced format `v: 2` (ADR-0009): `route` in the header in place of `module`/`controller`/`action`, and `caller` in every entry, up to three application frames as `path:line` relative to the project root, searched in 30 frames from the guard's closure in `Recorder::record()` (`support\CallerFrames`). `YQM-29` set the default `maxQueryLength` to 8192. The next step is `YQM-30` (`maxBatchBytes` and `maxEntries` from a measurement on real traffic), blocked until that measurement exists outside this repository; by the user's decision E4 (MongoDB) and later milestones wait for the end of E3. `YQM-1` created the package skeleton (`mrstroz/yii2-query-monitoring`, namespace `mrstroz\querymonitoring`, code in `src/`, tests in `tests/`). The package is a Composer library for Yii 2 that collects a flat list of database queries (MySQL, PostgreSQL, MongoDB) per HTTP request or console run and hands one `QueryBatch` to an output adapter. Everything about what it must do is in `docs/`.

## Start here

`docs/plan/roadmap.md`, section "Stan na dziś". It names the current milestone, the last finished task and the next one. Do not start coding from memory of the conversation that produced the docs; read the spec sections the task links to.

Documentation is in Polish. This file stays in English.

## How the docs are organised

- `docs/spec/` says **what** the system does. `00` scope, glossary and open questions; `01` how entries are collected (Command subclass for SQL, driver events for MongoDB, HTTP and console lifecycle); `02` the batch format, normalisation rules and limits; `03` adapter contract, file adapter, performance test.
- `docs/adr/` says **why**. Nine accepted decisions. A changed parameter edits the ADR in place; a new decision gets a new ADR.
- `docs/plan/` says **when**. Tasks are `YQM-NN`, numbered continuously, one task per commit. Milestones E0, E1, E2 and E3 have tasks written out; E4–E6 have a goal and mandatory acceptance scenarios.
- `tests/README.md` says **how the tests are written**: where a test belongs, naming, data providers, isolation. [ADR 0008](docs/adr/0008-architektura-i-konwencje-testow.md) says why.
- `docs/query-monitoring-library-brief.md` is the original brief. Where it disagrees with `spec/`, `spec/` wins.

Rules that hold across the tree: spec section numbers are addresses and are never renumbered; plan tasks link to spec and never describe behaviour; if a task changes behaviour, fix the spec first, then the code; tick the checkbox and replace "Stan na dziś" in the same commit as the code.

## Commands

The host has no PHP or Composer. Everything runs in the package's own Docker image (PHP 8.1 by default, `PHP_VERSION=8.4` for another version). The container runs as `UID:GID` from `.env`; shells do not export them, so without `.env` it falls back to `1000:1000`. Once per checkout: `cp .env.dist .env` and set both to `id -u` and `id -g`.

```
docker compose run --rm php composer install
docker compose run --rm php composer test   # PHPUnit 10.5
docker compose run --rm php composer stan   # PHPStan 2, level 8
docker compose run --rm php composer cs     # PHP CS Fixer, dry run
```

These three scripts are the plan's definition of done; keep their names. Do not use or touch containers of other projects running on the host. `composer.lock` is not committed.

Commit messages in English with the task id: `feat: YQM-5 measured Command class`.

## Architecture constraints that shape the code

These come from the ADRs and are easy to violate by accident:

- The library must never change the result of a database operation or the application response (`spec/00 §2`). Every collector and adapter call is wrapped; exceptions are logged once per process via `Yii::error` and not rethrown.
- SQL is measured by setting `commandMap` for the driver of each configured connection to the package's `Command` class (ADR-0001; `commandClass` is deprecated), not by enabling Yii profiling. Timing covers `PDO::prepare()` and `PDOStatement::execute()` only. `begin`/`commit`/`rollback` go through PDO directly and are out of scope; savepoints go through `Command` and are in.
- The batch is a flat list, no aggregates (ADR-0002). Limits: 500 entries, 256 KB per batch, 8 KB per `query`. HTTP drops the excess and counts it in `dropped`; console flushes and starts a new batch.
- Finalisation happens in `EVENT_AFTER_REQUEST` with a `register_shutdown_function` fallback (ADR-0003). The "finalised" flag is set before the batch is built and is never reset, so an adapter failure does not trigger a second attempt.
- Normalisation replaces literals with `?` and returns `query: null` when unsure (ADR-0004). Rules differ per dialect: `"..."` is a string in MySQL and an identifier in PostgreSQL. Only MySQL's default `sql_mode` is supported.
- Parameter values, MongoDB documents, credentials and absolute paths never enter a batch. Field, table and collection names, and paths relative to the project root in `caller`, are treated as code, not data.
- `yiisoft/yii2-mongodb` and `ext-mongodb` are optional (`suggest`). SQL-only applications must install without them.
- Target: PHP 8.1+, Yii 2.0.55+, Composer 2.1+, PHP-FPM request model. No RoadRunner/Swoole, no NFS.
