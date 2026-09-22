# Yii 2 Query Monitoring

Collects a flat list of the database queries run during one HTTP request of a Yii 2 application and hands
it, as one batch, to an output adapter you provide. Each entry has the connection, the operation, the
normalised query text with literals replaced by `?`, the time of `PDO::prepare()` and
`PDOStatement::execute()` and the result. Parameter values never enter a batch.

## Status

Milestone E0: MySQL and PostgreSQL over HTTP requests, with an adapter you write. A default file
adapter, MongoDB and console commands are planned and not available yet. The specification (in Polish)
is in [`docs/spec/`](docs/spec/).

## Requirements

- PHP 8.1 or newer
- Yii 2.0.55 or newer
- PHP-FPM request model (no RoadRunner or Swoole)

## Installation

```
composer require mrstroz/yii2-query-monitoring
```

## Configuration

Register the component, list its id under `bootstrap` and name the connections to monitor. A connection
inside a module is `module/id`. The `adapter` is your own class; the package does not ship one yet.

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
            'adapter' => \app\monitoring\QueueAdapter::class,
        ],
    ],
];
```

| Option | Default | Meaning |
|---|---|---|
| `app` | required | Application name written to every batch |
| `connections` | `[]` | Ids of `yii\db\Connection` components to monitor |
| `adapter` | required | Class name, object implementing `BatchAdapterInterface`, or `callable(QueryBatch)` |
| `enabled` | `true` | `false` installs nothing and sends nothing |
| `maxEntries` | `500` | Entries per batch; the excess is counted in `dropped` |
| `maxBatchBytes` | `262144` | Size of the batch JSON; the excess is counted in `dropped` |
| `maxQueryLength` | `2048` | Bytes of one normalised `query`, cut with `…` |

A monitored connection must not set its own `commandClass` or `commandMap` for its driver: the package
measures queries by setting `commandMap` to its own `Command` class.

## Writing an adapter

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

Everything runs in the package's Docker image, with MySQL 8 and PostgreSQL 16 started by
`docker-compose.yml`. Once per checkout, set your user and group ids so files created in the container
belong to you:

```
cp .env.dist .env    # then set UID and GID to the output of `id -u` and `id -g`
docker compose run --rm php composer install
```

The checks CI runs:

```
docker compose run --rm php composer test   # PHPUnit; integration tests need both databases
docker compose run --rm php composer stan   # PHPStan, level 8
docker compose run --rm php composer cs     # PHP CS Fixer, dry run
```

A skipped test fails the run, so `composer test` without the databases is red, not silently green
(PHPUnit still prints "OK, but some tests were skipped!", with exit code 1). GitHub Actions runs the
same checks against the same database images, started as service containers.

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
