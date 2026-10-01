# Yii 2 Query Monitoring

Collects a flat list of the database queries run during one HTTP request, console command or queue job of a
Yii 2 application and hands it, in batches, to an output adapter: by default a JSON Lines file, or an adapter you provide. Each
entry has the connection, the operation, the normalised query text with literals and Yii parameters `:qpN`
replaced by `?` and lists of values collapsed (`IN (?, ...)`, `$in:[?,...]`), the time and the result: for
SQL the time of `PDO::prepare()` and `PDOStatement::execute()`, for MongoDB the duration the driver reports
for each command. Parameter values and documents never enter a batch.

## Status

Milestone E5a: MySQL, PostgreSQL and MongoDB over HTTP requests, console commands and queue jobs, written to
a rotated JSON Lines file or handed to an adapter you write, with optional sampling of whole batches. Batches
use format `v: 4`; a receiver that accepts only `v: 3` rejects them, see [Sampling batches](#sampling-batches).
The specification (in Polish) is in [`docs/spec/`](docs/spec/).

## Requirements

- PHP 8.1 or newer
- Yii 2.0.55 or newer
- Composer 2.1 or newer (`caller` paths are relative to the root package Composer reports)
- PHP-FPM request model (no RoadRunner or Swoole)
- For MongoDB only: `ext-mongodb` 2.0 or newer and `yiisoft/yii2-mongodb` 3.0.4 or newer. An application
  without MongoDB installs the package without them
- For the `yii2-queue` behavior only: `yiisoft/yii2-queue` 2.3.7 or newer. Any other queue uses two method
  calls instead

## Installation

```
composer require mrstroz/yii2-query-monitoring
```

## Configuration

Register the component, list its id under `bootstrap` and name the connections to monitor. A connection
inside a module is `module/id`. Without `adapter`, batches go to `@runtime/logs/query-monitoring.jsonl`.

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
| `connections` | `[]` | Ids of `yii\db\Connection` and `yii\mongodb\Connection` components to monitor |
| `adapter` | `null` | Class name, object implementing `BatchAdapterInterface`, or `callable(QueryBatch)`; `null` writes to a file |
| `file` | `[]` | Settings of the file adapter, see below; ignored when `adapter` is set |
| `enabled` | `true` | `false` installs nothing and sends nothing |
| `maxEntries` | `500` | Entries per batch. HTTP counts the excess in `dropped`; a console command or a job sends the batch and starts the next one |
| `maxBatchBytes` | `262144` | Size of the batch JSON. HTTP counts the excess in `dropped`; a console command or a job sends the batch and starts the next one |
| `flushIntervalSeconds` | `30` | A console command or a job sends its batch at the first query after this many seconds since its last batch |
| `excludedRoutes` | `[]` | Patterns not monitored, per context type: `['http' => ['health/index'], 'console' => ['queue/*'], 'job' => [...]]`. See [Excluding routes](#excluding-routes) |
| `maxQueryLength` | `8192` | Bytes of one normalised `query`, cut with `…` |
| `sampling` | `null` | Send only some batches to the adapter. `null` sends every batch. See [Sampling batches](#sampling-batches) |

A monitored connection must not set its own `commandClass` or `commandMap` for its driver: the package
measures queries by setting `commandMap` to its own `Command` class.

### MongoDB

List a `yii\mongodb\Connection` in `connections` like a SQL one. The package adds a command subscriber of
the MongoDB driver to the connection's `Manager`, also when the connection was opened before the package
and every time it is opened again. One batch then holds the entries of both kinds, in the order the
commands ended:

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

Each command the driver sends is one entry, `getMore` and every part of a split `insert` included; a
`writeErrors` or `writeConcernError` in the reply makes it `result: error` with the server's code. The
driver shares one client, and its subscribers, between connections with the same DSN, `options` and
`driverOptions`: commands of such a connection that is not in `connections` are recorded under the listed
one while that one has been opened in the same process, and two listed ones on one client are both
recorded under the first. The reply of `find`, `getMore`, `aggregate` and `distinct` is not read, so a
`writeConcernError` of an `aggregate` with `$out` or `$merge` is not an error entry.

A wrong setting, including a wrong key of `file` when the file adapter is used, disables the package for
the process with one `Yii::error`; the application runs as without it.

## The file adapter

Each batch is one line of JSON appended to the file. Every key of `file` can be set on its own; the
others keep their defaults:

| Key | Default | Meaning |
|---|---|---|
| `file.path` | `@runtime/logs/query-monitoring.jsonl` | File path; Yii aliases allowed. The directory is created on the first write |
| `file.maxSize` | `10485760` | Bytes; a file that grows over it is rotated |
| `file.maxFiles` | `5` | Rotated copies kept: `.1` is the newest, `.5` the oldest |
| `file.fileMode` | `0664` | Mode given to the file and its `.lock` by the process that creates them |
| `file.dirMode` | `0775` | Mode given to the directory, and to any missing parent, by the process that creates it; an inherited setgid bit is kept |

```php
'queryMonitor' => [
    'class' => \mrstroz\querymonitoring\QueryMonitor::class,
    'app' => 'shop-api',
    'connections' => ['db'],
    'file' => ['path' => '@app/runtime/monitoring/queries.jsonl'],
],
```

Writing and rotation take a non-blocking lock on `<path>.lock`. When another process holds it, the
batch is dropped rather than making the request wait. The file must be on a local disk (no NFS). A write
that fails, for example on a full disk or a directory without write permission, is logged once per
process with `Yii::error` and the batch is lost.

When the web server and console commands run as different users, put both in one group and give the
directory to that group with the setgid bit, so every file created in it gets the group:

```
chgrp www-data runtime/logs && chmod 2775 runtime/logs   # the console user is a member of www-data
```

Do not set the sticky bit on that directory: with `fs.protected_regular`, the kernel then stops the second user
from opening the other user's lock and data file, and rotation cannot move them.

With the default modes the file and the lock are then writable by both users, whoever created them.
Rotation renames files, which needs write permission on the directory. Copies move up only to the first
missing one, so a rotation that fails, and its retry, remove no copy; a file already over `maxSize` is rotated before
the next write, and when that fails too, the batch is not appended, so the file does not keep growing.

## Console commands and jobs

A console command is monitored like a request, but it may send many batches. Its batches share one `id` and
count up in `seq` from 1. A batch is sent when it reaches `maxEntries`, before an entry that no longer fits
in `maxBatchBytes`, at the first query after `flushIntervalSeconds`, and at the end of the command. There is
no timer: an idle process sends nothing until its next query or its end.

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

A job is one attempt to run one unit of work, usually a message of a queue. It gets batches of its own,
`type: job`, with a new `id` and `seq` from 1, and a `job` header with its class name, queue name, message
id and attempt number, never its arguments or exception. Its queries do not appear in the batches of the
command or request that ran it.

### Queue workers with `yii2-queue`

Attach the behavior to the queue component and exclude the worker commands, whose own queries only poll
the queue. The jobs they run are still monitored:

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

The routes start with the id of the queue component: with `mailQueue` they are `mailQueue/listen` and so on.
A pattern like `queue/*` excludes every command of that component, `queue/info` included.

The behavior opens the job in `Queue::EVENT_BEFORE_EXEC` and ends it in `EVENT_AFTER_EXEC` or
`EVENT_AFTER_ERROR`. In `isolate` mode, the default of `queue/listen` and `queue/run`, the job runs in a child
process `queue/exec`, and the child sends the job's batches; that is why `queue/exec` is excluded too. A job
another handler marks as `handled` gets no end event; it ends at the first monitored operation after the queue
returns from it, also in `yii\queue\sync\Queue`, which has no worker loop. A `sync\Queue` with `handle: true`
runs its jobs in `EVENT_AFTER_REQUEST`; when the queue component is created after the package's bootstrap (for
example at the first `push()`), that happens after the package has finalised and their queries are not monitored.
List the queue in `bootstrap` before `queryMonitor` to monitor them. When
`monitor` does not name a `QueryMonitor` component, the behavior logs one `Yii::error` and monitors no jobs.

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

Pass the message object as `scope:` to `beginJob()` as a safety net: the package keeps only a weak reference,
and once the object is released the job ends at the latest at the next monitored operation. `endJob()` in
`finally` stays the way to end it on time.

`beginJob()` and `endJob()` never throw and never change what the job does, throws, or how the queue
retries or acknowledges it. A handle that was already ended, or one returned while the package is disabled,
does nothing.

### What to expect

- **Retries.** Every attempt is a new job with a new `id`; `job.message_id` and `job.attempt` tie the attempts
  of one message together.
- **Nesting.** A job run synchronously inside a request, a command or another job is a child: its queries
  belong only to it, and the parent collects again after `endJob()`. Ending a parent ends its children that
  are still open. At most 16 contexts are open at once, the request or command included; a `beginJob()` over
  that limit returns an inert handle, logs one `Yii::error`, and the job's queries go to the deepest open
  context with that context's metadata, even when the job's name is excluded. A consumer that forgets `endJob()`
  reaches the limit, so use `finally`.
- **Idle workers.** A job sends its remainder when it ends, so nothing waits for the next message.
- **Shutdown.** The end of the process sends what is left, also after `exit()` or an unhandled exception, and
  ends jobs that were not ended. A fatal error in some phases or `SIGKILL` loses the unsent remainder.
- **Several jobs at once** in one process (fibers, Swoole) are not supported.

### Excluding routes

`excludedRoutes` has one list per context type. `http` and `console` patterns are compared with the route of
the request or command (`health/index`, `queue/listen`, no leading `/`), `job` patterns with the job name. A
pattern matches exactly, or as a prefix when it ends with `*`; `*` alone matches everything. Case matters. A
request or command whose route is never known (a 404, an unknown command) is never excluded.

An excluded context records nothing and sends nothing; its queries are not counted in `dropped`. Queries run
before the route is known, in bootstrap, are held and dropped once the route turns out excluded. A job inside
an excluded command is judged by its own name, so excluding a worker command keeps its jobs monitored.

## Sampling batches

With `sampling` set, the package decides for every finished batch, just before the adapter, whether to send it.
A batch that matches an enabled diagnostic criterion is always sent. Any other batch is sent with the
probability `rate`. A batch is sent whole or not at all. This example sends 10% of ordinary batches and every
batch with an error, a query of at least 500 ms, 2 s of queries together, or at least 200 queries:

```php
'queryMonitor' => [
    'class' => \mrstroz\querymonitoring\QueryMonitor::class,
    'app' => 'shop-api',
    'connections' => ['db'],
    'adapter' => $sendToWorker,
    'sampling' => [
        'rate' => 0.10,
        'keepErrors' => true,
        'slowQueryMs' => 500,
        'slowBatchMs' => 2000,
        'minQueries' => 200,
    ],
],
```

| Key | Default | Keeps a batch when |
|---|---|---|
| `rate` | required | Chosen by chance, with this probability from `0` to `1`, when no criterion matches |
| `keepErrors` | `false` | An entry has `result: error` |
| `slowQueryMs` | `null` | An entry took at least this many milliseconds |
| `slowBatchMs` | `null` | The entries of this batch took at least this many milliseconds together |
| `minQueries` | `null` | This batch describes at least this many queries: its entries plus `dropped` |

`false` turns `keepErrors` off and `null` turns a threshold off; no criterion is on by default. Criteria cover SQL and MongoDB entries.
A `sampling` that is not an array or `null`, an unknown key, a missing `rate` or a value out of range disables
the package, like any wrong setting.

In HTTP, `dropped` counts every query cut by a limit, so `minQueries` above `maxEntries` still keeps a request
that hit the limit. A command or a job splits at `maxEntries`, so a threshold above it hardly ever fires there.
`slowBatchMs` adds up only the stored entries: the time of dropped queries is unknown, so a truncated request
can stay below it.

- **Share of batches.** More than 10% of batches reach the adapter when many of them match a criterion. With
  `rate: 1` every batch is sent. With `rate: 0` only diagnostic batches are sent, and they cannot estimate the
  whole traffic; `rate: 0` with no criterion is a configuration error.
- **The choice.** It depends only on the batch `id` and `seq` (an xxh3 hash), not on time, route, host or
  queries, so the same batch always gets the same decision.
- **What is sent.** A sent batch keeps all its entries, their order, times, `dropped`, `id` and `seq`; only the
  header field `sample` says `{"rate":0.1,"reason":"sample"}` for a batch chosen by chance, or
  `{"rate":1.0,"reason":"error"}` (`slow_query`, `slow_batch`, `many_queries`) for one kept by a criterion.
  Without `sampling` it is `null`.
- **What is skipped.** A skipped batch never reaches the adapter. It is not an error, is not logged and does
  not count in `dropped`. A command or a job decides every batch on its own, so `seq` may have gaps, and an
  error later in the same command does not bring back a batch skipped before it. The count and total time of a
  batch describe that batch, not the whole command.
- **Cost.** Sampling saves sending and the receiver's work, not the cost of collecting queries in PHP: every
  query is still measured and normalised.

**Format `v: 4`.** Since the header field `sample` was added, every batch has `"v":4` and `"sample"`, with
sampling or without. A receiver that accepts only `v: 3`, such as a worker validating the version, rejects
every batch, so update it before the package. Before you turn sampling on, the receiver must weigh each batch by
`1 / sample.rate` in counts, sums, averages and percentiles (multiplied by its own sampling factor, e.g.
Analytics Engine's `_sample_interval`), or show clearly that its numbers describe only the sample. Accepting
the extra field is not enough. The requirements are in
[spec 02 §7](docs/spec/02-format-paczki.md#7-próbkowanie-po-stronie-odbiorcy).

## Writing an adapter

Set `adapter` to send batches somewhere else instead of the file:

```php
use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\batch\QueryBatch;

final class QueueAdapter implements BatchAdapterInterface
{
    public function send(QueryBatch $batch): void
    {
        // $batch->toJson() is one line of JSON; see docs/spec/02-format-paczki.md for the format.
    }
}
```

Send `$batch->toJson()`, or encode `$batch->toArray()` with `QueryBatch::JSON_FLAGS`; without
`JSON_PRESERVE_ZERO_FRACTION` a `sample.rate` of `1.0` becomes `1`.

`send()` is called at most once per request, in `EVENT_AFTER_REQUEST` or, when the request ends with
`exit()` or an unhandled exception, during PHP shutdown. A console command and a job call it once per batch,
also while the command runs. A batch skipped by `sampling` never reaches it. A request without queries sends nothing. In the
normal path the call happens before the response is sent, so a slow adapter delays it; the adapter is
responsible for its own timeouts.

An exception from the adapter, or from the package itself, never reaches the application: it is logged
once per process with `Yii::error` (category `mrstroz\querymonitoring`) and the batch is lost. Queries
the adapter runs are not recorded.

## Development

Everything runs in the package's Docker image, with MySQL 8, PostgreSQL 16 and MongoDB 7 started by
`docker-compose.yml`. Once per checkout, set your user and group ids so files created in the container
belong to you:

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

A skipped test fails the run, so `composer test` without the databases is red, not silently green
(PHPUnit still prints "OK, but some tests were skipped!", with exit code 1). GitHub Actions runs the
same checks against the same database images.

### Test application

`tests/app/` is a small Yii application with Active Record and a capturing adapter. The integration
tests run it in a separate PHP process per request, like one PHP-FPM request. To play one request by
hand, after `composer test` has created the `qm_order` table:

```
docker compose run --rm -e QM_ROUTE=order/index -e QM_DB=pgsql -e QM_CAPTURE_FILE=/tmp/batch.jsonl \
    php sh -c 'php tests/app/web/index.php && echo && cat /tmp/batch.jsonl'
```

It prints the response and then the batch the adapter received. `QM_DB` is `mysql` or `pgsql`;
`QM_COMPONENT` takes JSON merged into the component configuration, e.g. `'{"enabled": false}'`.

## License

MIT, see [LICENSE](LICENSE).
