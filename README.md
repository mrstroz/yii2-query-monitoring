# Yii 2 Query Monitoring

Collects a flat list of the database queries run during one HTTP request of a Yii 2 application and hands
it, as one batch, to an output adapter: by default a JSON Lines file, or an adapter you provide. Each
entry has the connection, the operation, the normalised query text with literals and Yii parameters `:qpN`
replaced by `?` and lists of values collapsed (`IN (?, ...)`, `$in:[?,...]`), the time and the result: for
SQL the time of `PDO::prepare()` and `PDOStatement::execute()`, for MongoDB the duration the driver reports
for each command. Parameter values and documents never enter a batch.

## Status

Milestone E4: MySQL, PostgreSQL and MongoDB over HTTP requests, written to a rotated JSON Lines file or
handed to an adapter you write. Console commands are planned and not available yet. The specification
(in Polish) is in [`docs/spec/`](docs/spec/).

## Requirements

- PHP 8.1 or newer
- Yii 2.0.55 or newer
- Composer 2.1 or newer (`caller` paths are relative to the root package Composer reports)
- PHP-FPM request model (no RoadRunner or Swoole)
- For MongoDB only: `ext-mongodb` 2.0 or newer and `yiisoft/yii2-mongodb` 3.0.4 or newer. An application
  without MongoDB installs the package without them

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
| `maxEntries` | `500` | Entries per batch; the excess is counted in `dropped` |
| `maxBatchBytes` | `262144` | Size of the batch JSON; the excess is counted in `dropped` |
| `maxQueryLength` | `8192` | Bytes of one normalised `query`, cut with `…` |

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

```php
'queryMonitor' => [
    'class' => \mrstroz\querymonitoring\QueryMonitor::class,
    'app' => 'shop-api',
    'connections' => ['db'],
    'file' => ['path' => '@app/runtime/monitoring/queries.jsonl'],
],
```

Writing and rotation take a non-blocking lock on `<path>.lock`. When another process holds it, the
batch is dropped rather than making the request wait. The file must be on a local disk (no NFS) and
writable by both the web server and console users. A write that fails, for example on a full disk or a
directory without write permission, is logged once per process with `Yii::error` and the batch is lost.

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

`send()` is called at most once per request, in `EVENT_AFTER_REQUEST` or, when the request ends with
`exit()` or an unhandled exception, during PHP shutdown. A request without queries sends nothing. In the
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
