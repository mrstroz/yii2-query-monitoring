<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\adapter\CallableAdapter;
use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\mongodb\MongoDbNormalizer;
use mrstroz\querymonitoring\mongodb\Source as MongoDbSource;
use mrstroz\querymonitoring\sql\Source as SqlSource;
use mrstroz\querymonitoring\sql\SqlNormalizer;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\base\Event;
use yii\base\InvalidConfigException;

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
 * {@see self::createNormalizer()}, {@see self::createCollector()} and {@see self::createSources()} are the only
 * extension points.
 */
class QueryMonitor extends Component implements BootstrapInterface
{
    public bool $enabled = true;

    /** Application name for the batch header; required. */
    public string $app = '';

    /** @var list<string> ids of connection components; `module/id` for a connection inside a module */
    public array $connections = [];

    public int $maxEntries = 500;

    public int $maxBatchBytes = 262144;

    public int $maxQueryLength = SqlNormalizer::DEFAULT_MAX_QUERY_LENGTH;

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
     * Validates the settings, installs a source on each listed connection, captures the
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
     * Creates the normaliser shared by all monitored SQL connections, on the first of them.
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

    /**
     * The sources a listed component is offered to, in order; the first whose `supports()` accepts it installs.
     *
     * @return list<SourceInterface>
     */
    protected function createSources(QueryCollector $collector, Guard $guard, CallerFrames $callers): array
    {
        return [
            new SqlSource($collector, $guard, $callers, fn(): SqlNormalizer => $this->createNormalizer()),
            new MongoDbSource($collector, $guard, $callers, fn(): MongoDbNormalizer => new MongoDbNormalizer($this->maxQueryLength)),
        ];
    }

    private function install(Application $app, Guard $guard): void
    {
        if ($this->app === '') {
            throw new InvalidConfigException('QueryMonitor::$app is required.');
        }
        if ($this->maxEntries < 1 || $this->maxBatchBytes < 1) {
            throw new InvalidConfigException('QueryMonitor::$maxEntries and $maxBatchBytes must be positive.');
        }
        // Checked here, not by the lazily created normaliser: a wrong setting disables the whole package (spec 01 §1).
        if ($this->maxQueryLength < strlen(SqlNormalizer::ELLIPSIS)) {
            throw new InvalidConfigException('QueryMonitor::$maxQueryLength must be at least ' . strlen(SqlNormalizer::ELLIPSIS) . ' bytes.');
        }
        $adapter = $this->resolveAdapter();
        $collector = $this->createCollector($app);
        $sources = $this->createSources($collector, $guard, CallerFrames::forProcess());
        foreach ($this->connections as $id) {
            $guard->run(fn() => $this->installOn($app, $id, $sources), "connection {$id}");
        }
        $this->collector = $collector;
        $this->batchAdapter = $adapter;
        $this->captureEntryAction($app, $collector, $guard);
        $app->on(Application::EVENT_AFTER_REQUEST, fn() => $this->finalize());
        // exit() in an action and exit(1) from the ErrorHandler skip EVENT_AFTER_REQUEST (ADR-0003).
        register_shutdown_function(fn() => $this->finalize());
    }

    /**
     * Stores the unique id of the first action of the request as `route` (spec 01 §4). The handler detaches
     * itself after the first action; an action run by the error handler (`errorAction`) is skipped and leaves
     * it attached, so a 404 before routing keeps `route` null.
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
                $collector->setRoute($event->action->getUniqueId());
                $app->off(Application::EVENT_BEFORE_ACTION, $handler);
            }, 'action');
        };
        $app->on(Application::EVENT_BEFORE_ACTION, $handler);
    }

    /**
     * @param list<SourceInterface> $sources
     */
    private function installOn(Application $app, string $id, array $sources): void
    {
        $component = $this->component($app, $id);
        foreach ($sources as $source) {
            if ($source->supports($component)) {
                $source->install($id, $component);

                return;
            }
        }

        throw new InvalidConfigException("Connection {$id} is not a connection the package measures.");
    }

    /**
     * `db` from the application, `admin/db` from module `admin` (loading the module).
     */
    private function component(Application $app, string $id): object
    {
        $position = strrpos($id, '/');
        $owner = $position === false ? $app : $app->getModule(substr($id, 0, $position));
        if ($owner === null) {
            throw new InvalidConfigException("Module of connection {$id} does not exist.");
        }
        $component = $owner->get($position === false ? $id : substr($id, $position + 1), false);
        if (!is_object($component)) {
            throw new InvalidConfigException("Connection {$id} does not exist.");
        }

        return $component;
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
