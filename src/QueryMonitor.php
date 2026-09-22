<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\adapter\CallableAdapter;
use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\sql\Command;
use mrstroz\querymonitoring\sql\Dialect;
use mrstroz\querymonitoring\sql\Recorder;
use mrstroz\querymonitoring\sql\SqlNormalizer;
use mrstroz\querymonitoring\support\Guard;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\base\Event;
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
 *     ],
 * ],
 * ```
 *
 * All work happens inside one {@see support\Guard}: a wrong setting disables the package for the
 * process with one `Yii::error`, and the application answers as without the package.
 * {@see self::createNormalizer()} and {@see self::createCollector()} are the only extension points.
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
     * Class name, {@see BatchAdapterInterface} object or `callable(QueryBatch): mixed` (spec 03 §1);
     * `null` writes to a file with {@see FileAdapter}, set by {@see self::$file}.
     *
     * @var class-string<BatchAdapterInterface>|BatchAdapterInterface|callable(QueryBatch): mixed|null
     */
    public mixed $adapter = null;

    /**
     * Settings of the file adapter, used only when `adapter` is `null` (spec 03 §3): `path` (Yii alias
     * allowed), `maxSize` in bytes and `maxFiles` archived copies. An omitted key keeps its default
     * from {@see FileAdapter}; an unknown key or a wrong value disables the package.
     *
     * @var array<string, mixed>
     */
    public array $file = [];

    private ?Guard $guard = null;
    private ?QueryCollector $collector = null;
    private ?BatchAdapterInterface $batchAdapter = null;
    private bool $finalized = false;

    /**
     * Validates the settings, installs the measured `Command` on the listed connections, captures the
     * entry action and subscribes {@see self::finalize()} to `Application::EVENT_AFTER_REQUEST` and to shutdown.
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
     * Closes the collector and sends a non-empty batch to the adapter, once per process (spec 01 §4, ADR-0003).
     * Called from `Application::EVENT_AFTER_REQUEST` and from the shutdown callback, whichever comes first.
     *
     * `$finalized` is the only finalisation state: it is set before anything else, also when the package
     * was not installed, and never reset, so an adapter or collector failure is not retried in shutdown.
     * The collector is paused while the adapter sends, so queries the adapter runs are not entries (spec 01 §6).
     */
    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;
        $collector = $this->collector;
        $adapter = $this->batchAdapter;
        if ($collector === null || $adapter === null || $this->guard === null) {
            return;
        }
        $this->guard->run(static function () use ($collector, $adapter): void {
            $collector->pause();
            try {
                $batch = $collector->close(new \DateTimeImmutable());
                if ($batch->queries !== [] || $batch->dropped > 0) {
                    $adapter->send($batch);
                }
            } finally {
                $collector->resume();
            }
        }, 'send');
    }

    /**
     * Creates the normaliser shared by all monitored connections.
     */
    protected function createNormalizer(): SqlNormalizer
    {
        return new SqlNormalizer($this->maxQueryLength);
    }

    /**
     * Creates the collector for this process, with a new random batch id.
     */
    protected function createCollector(Application $app): QueryCollector
    {
        return new QueryCollector(
            app: $this->app,
            type: $app instanceof \yii\console\Application ? BatchType::Console : BatchType::Http,
            id: bin2hex(random_bytes(8)),
            host: (string) gethostname(),
            maxEntries: $this->maxEntries,
            maxBatchBytes: $this->maxBatchBytes,
        );
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
        $normalizer = $this->createNormalizer();
        $adapter = $this->resolveAdapter();
        $collector = $this->createCollector($app);
        foreach ($this->connections as $id) {
            $guard->run(fn() => $this->installOn($app, $id, $normalizer, $collector, $guard), "connection {$id}");
        }
        $this->collector = $collector;
        $this->batchAdapter = $adapter;
        $this->captureEntryAction($app, $collector, $guard);
        $app->on(Application::EVENT_AFTER_REQUEST, fn() => $this->finalize());
        // exit() in an action and exit(1) from the ErrorHandler skip EVENT_AFTER_REQUEST (ADR-0003).
        register_shutdown_function(fn() => $this->finalize());
    }

    /**
     * Stores the first action of the request in the header (spec 01 §4). The handler detaches itself
     * after the first action; an action run by the error handler (`errorAction`) is skipped and leaves
     * it attached, so a 404 before routing keeps three `null`s.
     */
    private function captureEntryAction(Application $app, QueryCollector $collector, Guard $guard): void
    {
        // The parameter is a plain Event: a type error on a foreign trigger() must happen inside the guard.
        $handler = static function (Event $event) use (&$handler, $app, $collector, $guard): void {
            $guard->run(static function () use ($event, $handler, $app, $collector): void {
                if (!$event instanceof ActionEvent) {
                    return;
                }
                if ($app->has('errorHandler') && $app->getErrorHandler()->exception !== null) {
                    return;
                }
                $controller = $event->action->controller;
                $module = $controller->module;
                $collector->setAction($module instanceof Application ? null : $module->getUniqueId(), $controller->id, $event->action->id);
                $app->off(Application::EVENT_BEFORE_ACTION, $handler);
            }, 'action');
        };
        $app->on(Application::EVENT_BEFORE_ACTION, $handler);
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
        if ($adapter === null) {
            return $this->createFileAdapter();
        }
        if (is_string($adapter) && class_exists($adapter)) {
            $adapter = \Yii::createObject($adapter);
        }
        if ($adapter instanceof BatchAdapterInterface) {
            return $adapter;
        }
        if (is_callable($adapter)) {
            return new CallableAdapter($adapter);
        }

        throw new InvalidConfigException('QueryMonitor::$adapter must be null, a class name, an adapter object or a callable.');
    }

    /**
     * The default adapter from {@see self::$file}, checked key by key. Only the alias is resolved here:
     * the directory and the files are created on the first write (spec 03 §3).
     */
    private function createFileAdapter(): FileAdapter
    {
        $defaults = ['path' => FileAdapter::DEFAULT_PATH, 'maxSize' => FileAdapter::DEFAULT_MAX_SIZE, 'maxFiles' => FileAdapter::DEFAULT_MAX_FILES];
        $unknown = array_diff_key($this->file, $defaults);
        if ($unknown !== []) {
            throw new InvalidConfigException('QueryMonitor::$file has unknown keys: ' . implode(', ', array_keys($unknown)) . '.');
        }
        $file = array_replace($defaults, $this->file);
        if (!is_string($file['path']) || $file['path'] === '') {
            throw new InvalidConfigException('QueryMonitor::$file[\'path\'] must be a non-empty string.');
        }
        foreach (['maxSize', 'maxFiles'] as $key) {
            if (!is_int($file[$key]) || $file[$key] < 1) {
                throw new InvalidConfigException("QueryMonitor::\$file['{$key}'] must be an integer of at least 1.");
            }
        }
        $path = \Yii::getAlias($file['path'], false);
        if ($path === false) {
            throw new InvalidConfigException("QueryMonitor::\$file['path'] uses an unknown alias: {$file['path']}.");
        }

        return new FileAdapter($path, $file['maxSize'], $file['maxFiles']);
    }
}
