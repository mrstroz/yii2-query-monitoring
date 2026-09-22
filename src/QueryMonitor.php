<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\adapter\CallableAdapter;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\sql\Command;
use mrstroz\querymonitoring\sql\Dialect;
use mrstroz\querymonitoring\sql\Recorder;
use mrstroz\querymonitoring\sql\SqlNormalizer;
use mrstroz\querymonitoring\support\Guard;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\db\Connection;

/**
 * Application component and the package's only entry point (spec 01 §1).
 *
 * Register it under `components` and list its id under `bootstrap`:
 *
 * ```php
 * 'bootstrap' => ['queryMonitor'],
 * 'components' => [
 *     'queryMonitor' => [
 *         'class' => \mrstroz\querymonitoring\QueryMonitor::class,
 *         'app' => 'shop-api',
 *         'connections' => ['db', 'admin/db'],
 *         'adapter' => MyAdapter::class,
 *     ],
 * ],
 * ```
 *
 * All work happens inside one {@see support\Guard}: a wrong setting disables the package for the
 * process with one `Yii::error`, and the application answers as without the package.
 */
class QueryMonitor extends Component implements BootstrapInterface
{
    public bool $enabled = true;

    /** Application name for the batch header; required. */
    public string $app = '';

    /** @var list<string> ids of `yii\db\Connection` components; `module/id` for a connection inside a module */
    public array $connections = [];

    public int $maxEntries = 500;

    public int $maxBatchBytes = 262144;

    public int $maxQueryLength = 2048;

    /**
     * Class name, {@see BatchAdapterInterface} object or `callable(QueryBatch): mixed` (spec 03 §1).
     * In E0 there is no file adapter yet: `null` sends nothing and logs one `Yii::error`.
     *
     * @var class-string<BatchAdapterInterface>|BatchAdapterInterface|callable(QueryBatch): mixed|null
     */
    public mixed $adapter = null;

    private ?Guard $guard = null;
    private ?QueryCollector $collector = null;
    private ?BatchAdapterInterface $batchAdapter = null;

    /**
     * Validates the settings, installs the measured `Command` on the listed connections and
     * subscribes {@see self::finalize()} to `Application::EVENT_AFTER_REQUEST`.
     * With `enabled: false` it does nothing; a wrong setting disables the package with one `Yii::error`.
     */
    public function bootstrap($app): void
    {
        $guard = $this->guard = new Guard();
        if (!$this->enabled) {
            return;
        }
        $guard->run(fn() => $this->install($app, $guard), 'bootstrap');
    }

    /**
     * Closes the collector and sends a non-empty batch to the adapter. The single finalisation path:
     * YQM-8 adds the "finalised" flag and the shutdown fallback here. A no-op when the package is
     * disabled or was not installed.
     */
    public function finalize(): void
    {
        $collector = $this->collector;
        $adapter = $this->batchAdapter;
        if ($collector === null || $adapter === null || $this->guard === null) {
            return;
        }
        $this->guard->run(static function () use ($collector, $adapter): void {
            $batch = $collector->close(new \DateTimeImmutable());
            if ($batch->queries !== [] || $batch->dropped > 0) {
                $adapter->send($batch);
            }
        }, 'send');
    }

    private function install(Application $app, Guard $guard): void
    {
        if ($this->app === '') {
            throw new InvalidConfigException('QueryMonitor::$app is required.');
        }
        if ($this->maxEntries < 1 || $this->maxBatchBytes < 1) {
            throw new InvalidConfigException('QueryMonitor::$maxEntries and $maxBatchBytes must be positive.');
        }
        if (!Command::supportsInstalledYii()) {
            throw new InvalidConfigException('The installed yii\\db\\Command lacks the private fields the measured Command reads; see ADR-0001.');
        }
        $normalizer = new SqlNormalizer($this->maxQueryLength);
        $adapter = $this->resolveAdapter();
        $collector = new QueryCollector(
            app: $this->app,
            type: $app instanceof \yii\console\Application ? BatchType::Console : BatchType::Http,
            id: bin2hex(random_bytes(8)),
            host: (string) gethostname(),
            maxEntries: $this->maxEntries,
            maxBatchBytes: $this->maxBatchBytes,
        );
        foreach ($this->connections as $id) {
            $guard->run(fn() => $this->installOn($app, $id, $normalizer, $collector, $guard), "connection {$id}");
        }
        $this->collector = $collector;
        $this->batchAdapter = $adapter;
        $app->on(Application::EVENT_AFTER_REQUEST, fn() => $this->finalize());
    }

    private function installOn(Application $app, string $id, SqlNormalizer $normalizer, QueryCollector $collector, Guard $guard): void
    {
        $connection = $this->connection($app, $id);
        // Without a DSN prefix getDriverName() would open a connection; with one it only reads the DSN
        // (or an explicit driverName) and returns the key createCommand() looks up in commandMap.
        if (!str_contains((string) $connection->dsn, ':')) {
            throw new InvalidConfigException("Connection {$id} has no DSN to read the driver from.");
        }
        $driver = (string) $connection->getDriverName();
        if (Dialect::tryFrom($driver) === null) {
            throw new InvalidConfigException("Connection {$id} uses driver {$driver}, which is not monitored.");
        }
        if ($connection->commandClass !== \yii\db\Command::class) {
            throw new InvalidConfigException("Connection {$id} sets its own commandClass.");
        }
        $current = $connection->commandMap[$driver] ?? \yii\db\Command::class;
        if (is_array($current) && ($current['class'] ?? null) === Command::class) {
            throw new InvalidConfigException("Connection {$id} is already monitored under another id.");
        }
        if ($current !== \yii\db\Command::class) {
            throw new InvalidConfigException("Connection {$id} sets its own commandMap for {$driver}.");
        }
        $connection->commandMap[$driver] = [
            'class' => Command::class,
            'recorder' => new Recorder($id, $driver, $normalizer, $collector, $guard),
        ];
    }

    /**
     * `db` from the application, `admin/db` from module `admin` (loading the module).
     */
    private function connection(Application $app, string $id): Connection
    {
        $position = strrpos($id, '/');
        $owner = $position === false ? $app : $app->getModule(substr($id, 0, $position));
        if ($owner === null) {
            throw new InvalidConfigException("Module of connection {$id} does not exist.");
        }
        $connection = $owner->get($position === false ? $id : substr($id, $position + 1), false);
        if (!$connection instanceof Connection) {
            throw new InvalidConfigException("Connection {$id} does not exist.");
        }

        return $connection;
    }

    private function resolveAdapter(): BatchAdapterInterface
    {
        $adapter = $this->adapter;
        if (is_string($adapter) && class_exists($adapter)) {
            $adapter = \Yii::createObject($adapter);
        }
        if ($adapter instanceof BatchAdapterInterface) {
            return $adapter;
        }
        if (is_callable($adapter)) {
            return new CallableAdapter($adapter);
        }

        throw new InvalidConfigException('QueryMonitor::$adapter must be a class name, an adapter object or a callable; the file adapter arrives in E1.');
    }
}
