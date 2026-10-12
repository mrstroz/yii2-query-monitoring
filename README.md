# Yii 2 Query Monitoring

[![CI](https://github.com/mrstroz/yii2-query-monitoring/actions/workflows/ci.yml/badge.svg)](https://github.com/mrstroz/yii2-query-monitoring/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/mrstroz/yii2-query-monitoring)](https://github.com/mrstroz/yii2-query-monitoring/releases)
[![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4)](https://www.php.net/)
[![Yii](https://img.shields.io/badge/yii-2.0.55%2B-1f6fb2)](https://www.yiiframework.com/)
[![License](https://img.shields.io/github/license/mrstroz/yii2-query-monitoring)](LICENSE)

See every database query your Yii 2 application runs in production: for each HTTP request, console command and
queue job, with its time, its normalised text and the line of your code that ran it. MySQL, PostgreSQL and
MongoDB end up in one flat list, written to a JSON Lines file or handed to an adapter of your own.

```json
{"v":4,"app":"shop-api","type":"http","id":"9f3a1c2e5be0c7d2","seq":1,"route":"admin/orders/order/view","job":null,
 "ts":"2026-09-22T09:41:05.312Z","host":"web-03","user":"1187","dropped":0,"sample":null,
 "queries":[
  {"db":"mysql","conn":"db","op":"select","query":"SELECT * FROM `order` WHERE `id` = ?","time_ms":2.1,
   "result":"success","caller":["modules/admin/modules/orders/controllers/OrderController.php:41"]},
  {"db":"mysql","conn":"db","op":"insert","query":"INSERT INTO `audit_log` (`order_id`, `action`) VALUES (?, ?)",
   "time_ms":0.9,"result":"error","error":"23000","caller":["models/AuditLog.php:27","modules/admin/modules/orders/controllers/OrderController.php:44"]},
  {"db":"mongodb","conn":"mongodb","op":"find","query":"contacts filter{externalId:?,tenantId:?} sort{updatedAt:?} limit:?",
   "time_ms":1.3,"result":"success","caller":["components/ContactRepository.php:88"]}
 ]}
```

## Features

- **Every query, in order.** SQL and MongoDB in one list per request, command or job attempt, savepoints included.
- **Queries you can group.** Literals and Yii's `:qp0` parameters become `?` and value lists collapse
  (`IN (?, ...)`, `$in:[?,...]`), so the same query has the same text in every request and N+1 loops stand out.
- **The place in your code.** Up to three frames of your application per entry, as `path:line` relative to the
  project root.
- **Safe for production.** It never changes a query result or a response and catches its own failures. Parameter
  values, documents, credentials and absolute paths never enter a batch.
- **Works out of the box.** A rotated JSON Lines file under `@runtime/logs`, or an adapter of your own.
- **Console commands and queue jobs**, with a ready behavior for `yii2-queue`.
- **Sampling** that still keeps every slow or failing batch, and an optional **user id**.

## How it works

- **SQL.** The package sets `commandMap` of each listed connection's driver to its own `Command` subclass, which
  times `PDO::prepare()` and `PDOStatement::execute()`; Yii's profiling is not used. A hit in Yii's query cache
  sends nothing to the database and gives no entry.
- **MongoDB.** A command subscriber of the driver, added to the connection's `Manager`, records each command with
  the duration the driver reports.
- **Sending.** A request sends one batch in `EVENT_AFTER_REQUEST`, or at PHP shutdown after `exit()` or an
  unhandled exception. A command or a job sends a batch whenever one fills up, after `flushIntervalSeconds` and at
  its end. An exception of the package or the adapter is logged once per process with `Yii::error` (category
  `mrstroz\querymonitoring`), loses that batch and never reaches the application.

## Requirements

- PHP 8.1 or newer, Yii 2.0.55 or newer, Composer 2.1 or newer
- PHP-FPM or the CLI (no RoadRunner or Swoole)
- For MongoDB only: `ext-mongodb` 2.0+ and `yiisoft/yii2-mongodb` 3.0.4+. An application without MongoDB
  installs the package without them.
- For the queue behavior only: `yiisoft/yii2-queue` 2.3.7+

## Installation

```
composer require mrstroz/yii2-query-monitoring
```

## Configuration

Register the component, list it under `bootstrap` and name the connections to monitor. That is the whole setup:
batches go to `@runtime/logs/query-monitoring.jsonl`.

<!-- example:sql-config -->
```php
<?php

return [
    'bootstrap' => ['queryMonitor'],
    'components' => [
        'queryMonitor' => [
            'class' => \mrstroz\querymonitoring\QueryMonitor::class,
            'app' => 'shop-api',
            'connections' => ['db'],
        ],
    ],
];
```

| Option | Default | Meaning |
|---|---|---|
| `app` | required | Application name written to every batch |
| `connections` | `[]` | Ids of the `yii\db\Connection` and `yii\mongodb\Connection` components to monitor; `module/id` for a connection of a module |
| `adapter` | `null` | Where batches go: a class name or an object implementing `BatchAdapterInterface`, or a `callable(QueryBatch)`. `null` writes to a file, see [File output](#file-output) |
| `file` | `[]` | Settings of the file adapter, see [File output](#file-output). Ignored when `adapter` is set |
| `enabled` | `true` | `false` installs nothing and sends nothing |
| `maxEntries` | `500` | Entries per batch, see [Limits](#limits) |
| `maxBatchBytes` | `262144` | Bytes of the batch JSON (256 KB), see [Limits](#limits) |
| `maxQueryLength` | `8192` | Bytes of one normalised `query`; a longer one is cut and ends with `…` |
| `flushIntervalSeconds` | `30` | A command or a job sends its batch at the first query this many seconds after its previous batch |
| `excludedRoutes` | `[]` | Requests, commands and jobs not to monitor, see [Excluding routes](#excluding-routes) |
| `sampling` | `null` | Send only some batches, see [Sampling](#sampling) |
| `user` | `null` | Record the id of the logged-in user, see [Recording the user](#recording-the-user) |

A monitored connection must not set its own `commandClass` or `commandMap` for its driver: the package needs that
slot for its `Command` class. A wrong setting disables the package for the process with one `Yii::error`, and the
application runs as without it.

### MongoDB

List a `yii\mongodb\Connection` in `connections` like a SQL one. One batch then holds the entries of both kinds:

<!-- example:mongodb-config -->
```php
<?php

return [
    'bootstrap' => ['queryMonitor'],
    'components' => [
        'queryMonitor' => [
            'class' => \mrstroz\querymonitoring\QueryMonitor::class,
            'app' => 'shop-api',
            'connections' => ['db', 'mongodb'],
        ],
    ],
];
```

- Each command the driver sends is one entry, `getMore` and every part of a split `insert` included. A
  `writeErrors` or `writeConcernError` in the reply makes it `result: error` with the server's code. The replies
  of `find`, `getMore`, `aggregate` and `distinct` are not read, so a `writeConcernError` of an `aggregate` with
  `$out` or `$merge` is not an error entry.
- The driver shares one client, and its subscribers, between connections with the same DSN, `options` and
  `driverOptions`. Commands of an unlisted connection on such a client are recorded under the listed one once that
  one has been opened in the process, and two listed connections on one client are both recorded under the first.

## Batch format

A batch is one JSON object, format `v: 4`. The file adapter writes it as one line.

| Header field | Meaning |
|---|---|
| `v` | Format version, `4` |
| `app` | The `app` option |
| `type` | `http`, `console` or `job` |
| `id`, `seq` | Random id (16 hex characters) of the request, command or job attempt, and the number of the batch in it, from 1 |
| `route` | Route of the request or command, e.g. `admin/orders/order/view`; `null` when the request ended before routing, e.g. with a 404 |
| `job` | For `type: job`: `name`, `queue`, `message_id`, `attempt`; otherwise `null` |
| `ts`, `host` | When the batch was sent (ISO 8601, UTC) and `gethostname()` |
| `user` | Id of the logged-in user with the [`user`](#recording-the-user) option, otherwise `null` |
| `dropped` | Queries not stored because a limit was reached |
| `sample` | `null`, or `{"rate":…,"reason":…}` with [`sampling`](#sampling) |
| `queries` | Entries, in the order the queries ended |

| Entry field | Meaning |
|---|---|
| `db` | `mysql`, `pgsql` or `mongodb` |
| `conn` | Connection id from `connections` |
| `op` | First SQL keyword in lower case (`select`, `insert`, `with`) or the MongoDB command name (`find`, `getMore`) |
| `query` | Normalised text; `null` when the normaliser is not sure, e.g. on syntax it does not know |
| `time_ms` | SQL: `prepare()` plus `execute()`, without fetching rows. MongoDB: the duration the driver reports |
| `result`, `error` | `success` or `error`; `error` is the SQLSTATE (`"23000"`) or the MongoDB code (`"11000"`) |
| `caller` | Up to three frames of your code as `path:line` relative to the project root, nearest first; `[]` when none is in the first 64 frames of the stack |

**Normalisation.** String, number and MongoDB values become `?`; table, column, collection and field names stay,
because they are code. Only MySQL's default `sql_mode` is supported: with `ANSI_QUOTES` or `NO_BACKSLASH_ESCAPES`
the text is normalised wrongly. The full rules, per dialect, are in
[spec 02 §4](https://github.com/mrstroz/yii2-query-monitoring/blob/main/docs/spec/02-format-paczki.md#4-normalizacja) (in Polish).

### Limits

A request stops storing entries at the first one that exceeds `maxEntries` or `maxBatchBytes`, and counts that one
and every later query in `dropped`. A console command or a job sends the full batch instead and starts the next
one. The batch JSON never exceeds `maxBatchBytes`, header included. The defaults hold the requests
of a typical application whole; a request that reaches them usually has an N+1 loop worth fixing.

## File output

Without `adapter`, each batch is appended as one line to a file. Every key of `file` can be set on its own:

| Key | Default | Meaning |
|---|---|---|
| `path` | `@runtime/logs/query-monitoring.jsonl` | File path; Yii aliases allowed. The directory is created on the first write |
| `maxSize` | `10485760` | Bytes (10 MB); a file that grows over it is rotated |
| `maxFiles` | `5` | Rotated copies kept: `.1` is the newest, `.5` the oldest |
| `fileMode` | `0664` | Mode given to the file and its `.lock` by the process that creates them |
| `dirMode` | `0775` | Mode given to a directory the package creates; an inherited setgid bit is kept |

```php
'queryMonitor' => [
    'class' => \mrstroz\querymonitoring\QueryMonitor::class,
    'app' => 'shop-api',
    'connections' => ['db'],
    'file' => ['path' => '@app/runtime/monitoring/queries.jsonl', 'maxFiles' => 10],
],
```

- **Locking.** Writing and rotation take a non-blocking lock on `<path>.lock`. When another process holds it, the
  batch is dropped rather than making the request wait. A write that fails, e.g. on a full disk, is logged once
  per process and the batch is lost. Use a local disk, not NFS.
- **Rotation.** Copies move up only to the first missing one, so a failed rotation and its retry remove no copy. A
  file already over `maxSize` is rotated before the next write; when that fails too, the batch is not appended,
  so the file does not keep growing.
- **Web and console as different users.** Put both users in one group and give the directory to that group with
  the setgid bit. With the default modes the files are then writable by both, whoever created them:

  ```
  chgrp www-data runtime/logs && chmod 2775 runtime/logs   # the console user is a member of www-data
  ```

  Do not set the sticky bit on that directory: with `fs.protected_regular` the kernel then stops one user from
  opening the other user's files, and rotation cannot move them.

## Your own adapter

Set `adapter` to a class name, an object or a callable to send batches anywhere else:

```php
use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\batch\QueryBatch;

final class HttpAdapter implements BatchAdapterInterface
{
    public function send(QueryBatch $batch): void
    {
        // $batch->toJson() is the batch as one line of JSON.
    }
}
```

- Send `$batch->toJson()`, or encode `$batch->toArray()` with `QueryBatch::JSON_FLAGS`; without
  `JSON_PRESERVE_ZERO_FRACTION` a `sample.rate` of `1.0` becomes `1`.
- A request calls `send()` at most once, in `EVENT_AFTER_REQUEST` (before the response is sent, so a slow adapter
  delays it: set your own timeouts) or during PHP shutdown. A command and a job call it once per batch. A request
  without queries sends nothing.
- An exception from `send()` never reaches the application: it is logged once per process and the batch is lost,
  with no retry.

## Console commands and queue jobs

A console command is monitored like a request, but it may send many batches. They share one `id` and count up in
`seq` from 1. A batch is sent when it reaches `maxEntries`, before an entry that would not fit in `maxBatchBytes`,
at the first query after `flushIntervalSeconds`, and at the end of the command. There is no timer: an idle process
sends nothing until its next query or its end.

<!-- example:console-config -->
```php
<?php

return [
    'bootstrap' => ['queryMonitor'],
    'components' => [
        'queryMonitor' => [
            'class' => \mrstroz\querymonitoring\QueryMonitor::class,
            'app' => 'shop-console',
            'connections' => ['db'],
            'flushIntervalSeconds' => 30,
        ],
    ],
];
```

A **job** is one attempt to run one unit of work, usually a queue message. It gets batches of its own,
`type: job`, with a new `id`, `seq` from 1 and a `job` header: class name, queue name, message id and attempt
number, never the job's arguments or exception. Its queries do not appear in the batches of the command or request
that ran it.

### With `yii2-queue`

Attach the behavior to the queue component and exclude the worker commands, whose own queries only poll the
queue. The jobs they run are still monitored:

<!-- example:queue-config -->
```php
<?php

return [
    'bootstrap' => ['queryMonitor', 'queue'],
    'components' => [
        'queryMonitor' => [
            'class' => \mrstroz\querymonitoring\QueryMonitor::class,
            'app' => 'shop-worker',
            'connections' => ['db'],
            'excludedRoutes' => ['console' => ['queue/listen', 'queue/run', 'queue/exec']],
        ],
        'queue' => [
            'class' => \yii\queue\db\Queue::class,
            'as queryMonitor' => [
                'class' => \mrstroz\querymonitoring\queue\JobMonitorBehavior::class,
                'queueName' => 'queue',
            ],
        ],
    ],
];
```

- Worker routes start with the id of the queue component (`mailQueue/listen` for `mailQueue`); `queue/*` excludes
  every command of the component, `queue/info` included.
- The behavior opens a job in `Queue::EVENT_BEFORE_EXEC` and ends it in `EVENT_AFTER_EXEC` or `EVENT_AFTER_ERROR`.
  In `isolate` mode, the default, the job runs in a child process `queue/exec`, which sends its batches; that is
  why `queue/exec` is excluded too. A job another handler marks as `handled` ends at the first monitored operation
  after the queue returns from it.
- A `sync\Queue` with `handle: true` runs its jobs in `EVENT_AFTER_REQUEST`: list it in `bootstrap` before
  `queryMonitor`, or the package may finalise first and miss them.
- `monitor` (default `'queryMonitor'`) names the component. When it is not a `QueryMonitor`, the behavior logs one
  `Yii::error` and monitors no jobs.

### Any other consumer

Mark the job yourself. `endJob()` in `finally` ends it after success and after an exception alike:

<!-- example:consumer -->
```php
<?php

/** @var \mrstroz\querymonitoring\QueryMonitor $monitor */
$monitor = Yii::$app->get('queryMonitor');

$handle = $monitor->beginJob(SendInvoice::class, queue: 'invoices', messageId: $message->id, attempt: $message->attempt);
try {
    $job->run();
} finally {
    $monitor->endJob($handle);
}
```

`beginJob()` and `endJob()` never throw and never change what the job does or how the queue retries it. A handle
that was already ended, or one returned while the package is disabled, does nothing. Pass the message as `scope:`
for a safety net: the package keeps a weak reference, and once the object is released the job ends at the next
monitored operation.

### What to expect

- **Retries.** Every attempt is a new job with a new `id`; `job.message_id` and `job.attempt` tie the attempts of
  one message together.
- **Nesting.** A job run synchronously inside a request, a command or another job is a child: its queries belong
  only to it, and the parent collects again after `endJob()`. Ending a parent ends its open children. At most 16
  contexts are open at once, the request or command included; a `beginJob()` over that limit returns an inert
  handle, logs one `Yii::error`, and the job's queries go to the deepest open context. A consumer that forgets
  `endJob()` reaches the limit, so use `finally`.
- **Shutdown.** The end of the process sends what is left, also after `exit()` or an unhandled exception, and ends
  jobs that were not ended. A fatal error in some phases or `SIGKILL` loses the unsent remainder.
- **One job at a time.** Several jobs running at once in one process (fibers) are not supported.

## Excluding routes

`excludedRoutes` has one list of patterns per context type:

```php
'excludedRoutes' => [
    'http' => ['health/index', 'debug/*'],
    'console' => ['queue/*', 'migrate/*'],
    'job' => ['app\jobs\PingJob'],
],
```

`http` and `console` patterns are compared with the route (`health/index`, no leading `/`), `job` patterns with the
job name. A pattern matches exactly, or as a prefix when it ends with `*`; `*` alone matches everything. Case
matters. An excluded context records and sends nothing, and its queries are not counted in `dropped`; queries run
in bootstrap, before the route is known, are dropped once it turns out excluded. A request or command whose route
is never known (a 404, an unknown command) is never excluded. A job inside an excluded command is judged by its own name.

## Sampling

With `sampling` set, the package decides for every finished batch, just before the adapter, whether to send it. A
batch that matches an enabled criterion is always sent; any other is sent with the probability `rate`. A batch is
sent whole or not at all. This example sends 10% of ordinary batches and every batch with an error, a query of at
least 500 ms, 2 s of queries together, or at least 200 queries:

```php
'sampling' => [
    'rate' => 0.10,
    'keepErrors' => true,
    'slowQueryMs' => 500,
    'slowBatchMs' => 2000,
    'minQueries' => 200,
],
```

| Key | Default | Keeps a batch when |
|---|---|---|
| `rate` | required | Drawn with this probability, from `0` to `1`, when no criterion matches |
| `keepErrors` | `false` | An entry has `result: error` |
| `slowQueryMs` | `null` | An entry took at least this many milliseconds |
| `slowBatchMs` | `null` | The entries of the batch took at least this many milliseconds together |
| `minQueries` | `null` | The batch describes at least this many queries: its entries plus `dropped` |

- **The header** says why a batch was sent: `"sample":{"rate":0.1,"reason":"sample"}` when drawn, or
  `{"rate":1.0,"reason":"error"}` (`slow_query`, `slow_batch`, `many_queries`) when kept by a criterion; `null`
  without `sampling`. A skipped batch never reaches the adapter and is not logged or counted in `dropped`.
- **The draw** depends only on the batch `id` and `seq` (an xxh3 hash), so a batch always gets the same decision.
  A command or a job decides each batch on its own, so its `seq` may have gaps.
- **Criteria** see one batch: a command or a job splits at `maxEntries`, so `minQueries` above it hardly ever fires
  there, and `slowBatchMs` adds up stored entries only.
- **Weighting.** The receiver must weigh each batch by `1 / sample.rate` in counts, sums, averages and
  percentiles. `rate: 0` sends diagnostic batches only; without any criterion it is a configuration error.
- **Cost.** Sampling saves sending and the receiver's work; every query is still measured and normalised.

## Recording the user

With `user` set, every batch carries the id of the application user in the header field `user`, so the receiver
can see which users run the most queries. It is off by default.

```php
'user' => true,
```

`true` takes the id of the identity your application has **already loaded** in its `yii\web\User` component,
`Yii::$app->user->getIdentity(false)?->getId()`, when the batch is sent. The package never loads it itself (no
session, no auto-login cookie, no `findIdentity()`), so it adds no query. A request that never touched the identity
has `"user":null`, but most do, through `AccessControl` or `Yii::$app->user->isGuest` in a layout. A console
application without a `user` component gets `null`.

To decide yourself, give a callable. It gets the finished batch and returns the id: an `int`, a `string`, a
`Stringable` such as a MongoDB `ObjectId`, or `null`. It runs once per sent batch, after sampling, and its own
queries are not recorded.

```php
use mrstroz\querymonitoring\batch\QueryBatch;

// A keyed hash instead of the id. Keep the pepper secret, or a hash of a small int id is easy to reverse.
'user' => static function (QueryBatch $batch): ?string {
    $id = Yii::$app->has('user') ? Yii::$app->user->getIdentity(false)?->getId() : null;

    return $id === null ? null : hash_hmac('sha256', (string) $id, Yii::$app->params['userIdPepper']);
},
```

- **Values.** Ints and `Stringable`s are written as strings (`"42"`); `null` and `''` give `null`. A string must be
  valid UTF-8 and at most 66 bytes as JSON with quotes: 64 plain ASCII characters fit a UUID, an ObjectId or a
  SHA-256 in hex. Anything longer or of another type gives `null`; the id is never cut.
- **Do not load the identity in the callable.** `getId()` or `getIdentity()` without `false` renew the login at send
  time, possibly after the headers are out: session, `authTimeout`, auto-login cookie, `findIdentity()`. To have
  the id for every request, load the identity earlier yourself, e.g. in `beforeAction`.
- **Failures.** An exception or a rejected value gives `"user":null`, and the batch is still sent. It is logged once
  per process, without the value: only the reason or the exception class.
- **Personal data.** The id identifies a person. Return an internal id or a hash, never an e-mail or a login, nor
  an identity model whose `__toString()` gives one.

## Limitations

- PHP-FPM and CLI only: long-running HTTP servers (RoadRunner, Swoole) would need the collector reset per request.
- Only connections from the configuration are monitored, and only when they have no `commandClass` or `commandMap`
  of their own for their driver.
- `begin`, `commit` and `rollback` go through PDO directly and are not recorded; savepoints are. Fetching rows is not
  timed, and an error while reading a SQL cursor is not an entry.
- MySQL with `ANSI_QUOTES` or `NO_BACKSLASH_ESCAPES` gets wrongly normalised `query` texts.
- The file adapter needs a local disk: `flock` on NFS is not reliable.
- The library sends batches and does not aggregate them: counting, grouping and dashboards belong to the receiver.

## Documentation

The specification and the architecture decisions, in Polish, are in
[`docs/`](https://github.com/mrstroz/yii2-query-monitoring/tree/main/docs): what the package collects
([spec 01](https://github.com/mrstroz/yii2-query-monitoring/blob/main/docs/spec/01-zbieranie-danych.md)), the batch
format ([spec 02](https://github.com/mrstroz/yii2-query-monitoring/blob/main/docs/spec/02-format-paczki.md)) and the
adapters ([spec 03](https://github.com/mrstroz/yii2-query-monitoring/blob/main/docs/spec/03-adaptery-wyjsciowe.md)).

## Development

Everything runs in the package's Docker image, with MySQL 8, PostgreSQL 16 and MongoDB 7 from
`docker-compose.yml`. Once per checkout, set your user and group ids so files created in the container belong to
you:

```
cp .env.dist .env    # then set UID and GID to the output of `id -u` and `id -g`
docker compose run --rm php composer install
```

The checks CI runs:

```
docker compose run --rm php composer test   # PHPUnit; integration tests need all three databases
docker compose run --rm php composer stan   # PHPStan, level 8
docker compose run --rm php composer cs     # PHP CS Fixer, dry run
```

A skipped test fails the run, so `composer test` without the databases is red. `tests/app/` is a small Yii
application that the integration tests run in a separate PHP process per request. To play one request by hand once
`composer test` has created the `qm_order` table (`QM_DB` is `mysql` or `pgsql`, `QM_COMPONENT` takes JSON merged into the component configuration):

```
docker compose run --rm -e QM_ROUTE=order/index -e QM_DB=pgsql -e QM_CAPTURE_FILE=/tmp/batch.jsonl \
    php sh -c 'php tests/app/web/index.php && echo && cat /tmp/batch.jsonl'
```

## License

MIT, see [LICENSE](LICENSE).
