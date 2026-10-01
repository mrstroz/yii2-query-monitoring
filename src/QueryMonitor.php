<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\adapter\CallableAdapter;
use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\context\BatchSampling;
use mrstroz\querymonitoring\context\ContextSettings;
use mrstroz\querymonitoring\context\ContextStack;
use mrstroz\querymonitoring\context\RouteExclusions;
use mrstroz\querymonitoring\context\UserSource;
use mrstroz\querymonitoring\context\UserValueException;
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
 * {@see self::createNormalizer()}, {@see self::createCollector()}, {@see self::createSources()} and
 * {@see self::createClock()} are the only extension points; {@see self::beginJob()} and {@see self::endJob()}
 * are the public API of job boundaries (spec 01 §5.3).
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

    /** Seconds after which a console or job context sends its batch at the next entry (spec 01 §5.2). */
    public int $flushIntervalSeconds = 30;

    /**
     * Patterns excluded from monitoring by context type, e.g. `['console' => ['queue/*']]` (spec 01 §5.4): keys
     * `http` and `console` match `route`, `job` matches the job name; a pattern ending with `*` is a prefix.
     *
     * @var array<string, list<string>>
     */
    public array $excludedRoutes = [];

    /**
     * Class name, {@see BatchAdapterInterface} object or `callable(QueryBatch): mixed` (spec 03 §1);
     * `null` writes to a file with {@see FileAdapter}, set by {@see self::$file}.
     *
     * @var class-string<BatchAdapterInterface>|BatchAdapterInterface|callable(QueryBatch): mixed|null
     */
    public mixed $adapter = null;

    /**
     * Settings of the file adapter, used only when `adapter` is `null` (spec 03 §3): `path` (Yii alias
     * allowed), `maxSize` in bytes, `maxFiles` archived copies, and `fileMode`, `dirMode` given to what it
     * creates. An omitted key keeps its default from {@see FileAdapter}; an unknown key or a wrong value disables
     * the package.
     *
     * @var array<string, mixed>
     */
    public array $file = [];

    /**
     * Sampling of finished batches before the adapter (spec 01 §5.6), e.g. `['rate' => 0.1, 'keepErrors' => true]`:
     * `rate` (required, 0 to 1) is the probability of sending a batch no criterion keeps; `keepErrors`,
     * `slowQueryMs`, `slowBatchMs` and `minQueries` keep a batch always, each off by default. `null` sends every batch.
     * An unknown key or a wrong value disables the package.
     *
     * Not typed natively: a value of another type set by the application config must disable the package inside the
     * guard, not fail in Yii's configuration (spec 00 §2).
     *
     * @var array<string, mixed>|null
     */
    public mixed $sampling = null;

    /**
     * Source of the header field `user` (spec 01 §5.7): `null` leaves it null; `true` takes the id of the identity the
     * application already loaded in its `yii\web\User` component, without loading it (no session, no
     * `findIdentity()`); a callable, e.g. `[UserIds::class, 'of']` or `fn(QueryBatch $batch) => ...`, gets each sent
     * batch and returns the id, not the identity: an int, a Stringable (e.g. a MongoDB ObjectId), null, or a valid
     * UTF-8 string of at most 66 bytes as JSON with {@see QueryBatch::JSON_FLAGS}, quotes included (64 ASCII
     * characters without `"` or `\`; escaped and multi-byte characters take more). A wrong value or an exception of
     * the source gives null with one `Yii::error` of its own per process, never with the value; any other setting
     * disables the package. The source must not load the identity itself (`getId()`, `getIdentity()`): it runs when
     * the batch is sent, possibly after the headers, and would change the session and the login state.
     *
     * Not typed natively, like {@see self::$sampling}.
     *
     * @var bool|callable|null
     */
    public mixed $user = null;

    private ?Guard $guard = null;
    private ?ContextStack $contexts = null;
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
     * Finalises the process: ends every open context, the deepest first, and sends each non-empty remainder
     * (spec 01 §5.5, ADR-0003). Called from `Application::EVENT_AFTER_REQUEST` and from the shutdown callback,
     * whichever comes first.
     *
     * `$finalized` is the finalisation state of the process: it is set before anything else, also when the package
     * was not installed, and never reset, so an adapter or collector failure is not retried in shutdown. The context
     * stack keeps its own flag, so nothing reaches it after its finalisation either.
     * Intake is paused while the adapter sends, so queries the adapter runs are not entries (spec 01 §6).
     */
    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;
        $contexts = $this->contexts;
        if ($contexts === null || $this->guard === null) {
            return;
        }
        $this->guard->run(static fn() => $contexts->finalize(), 'send');
    }

    /**
     * Opens a job context over the deepest open context (spec 01 §5.3). Never throws; returns an inert handle
     * when there is nothing to open.
     *
     * @param string $name class or handler name of the job, never its arguments
     * @param string|int|null $messageId id of the message in the queue, not of the attempt
     * @param int|null $attempt number of the attempt, from 1
     * @param object|null $scope object whose lifetime is the latest end of the job, e.g. the message; only a weak
     *                           reference is kept, and once it is released the job ends at the next operation
     */
    public function beginJob(
        string $name,
        ?string $queue = null,
        string|int|null $messageId = null,
        ?int $attempt = null,
        ?object $scope = null,
    ): JobHandle {
        $contexts = $this->contexts;
        if ($this->finalized || $contexts === null || $this->guard === null) {
            return new JobHandle();
        }
        $context = $this->guard->run(
            static fn() => $contexts->beginJob(JobInfo::create($name, $queue, $messageId, $attempt), JobInfo::name($name), $scope),
            'beginJob',
        );

        return $context === null ? new JobHandle() : new JobHandle($contexts, $context);
    }

    /**
     * Ends the job context of `$handle` and sends its remainder (spec 01 §5.3). Never throws; an ended, inert
     * or foreign handle does nothing.
     */
    public function endJob(JobHandle $handle): void
    {
        $contexts = $this->contexts;
        $context = $handle->context();
        if ($contexts === null || $this->guard === null || $context === null || !$handle->belongsTo($contexts)) {
            return;
        }
        $this->guard->run(static fn() => $contexts->end($context), 'endJob');
    }

    /**
     * Creates the normaliser shared by all monitored SQL connections, on the first of them.
     */
    protected function createNormalizer(): SqlNormalizer
    {
        return new SqlNormalizer($this->maxQueryLength);
    }

    /**
     * Creates the buffer of one batch of a context. Called for every new batch, with the id of its context.
     */
    protected function createCollector(BatchType $type, string $id, ?JobInfo $job): QueryCollector
    {
        return new QueryCollector(
            app: $this->app,
            type: $type,
            id: $id,
            host: (string) gethostname(),
            maxEntries: $this->maxEntries,
            maxBatchBytes: $this->maxBatchBytes,
            job: $job,
        );
    }

    /**
     * The sources a listed component is offered to, in order; the first whose `supports()` accepts it installs.
     * Every source hands its entries to `$contexts`, never to one batch.
     *
     * @return list<SourceInterface>
     */
    protected function createSources(ContextStack $contexts, Guard $guard, CallerFrames $callers): array
    {
        return [
            new SqlSource($contexts, $guard, $callers, fn(): SqlNormalizer => $this->createNormalizer()),
            new MongoDbSource($contexts, $guard, $callers, fn(): MongoDbNormalizer => new MongoDbNormalizer($this->maxQueryLength)),
        ];
    }

    /**
     * The monotonic clock of `flushIntervalSeconds`, in seconds. Called once, in bootstrap, after the settings
     * are checked.
     *
     * @return \Closure(): float
     */
    protected function createClock(): \Closure
    {
        return static fn(): float => hrtime(true) / 1e9;
    }

    private function install(Application $app, Guard $guard): void
    {
        if ($this->app === '') {
            throw new InvalidConfigException('QueryMonitor::$app is required.');
        }
        if ($this->maxEntries < 1 || $this->maxBatchBytes < 1) {
            throw new InvalidConfigException('QueryMonitor::$maxEntries and $maxBatchBytes must be positive.');
        }
        if ($this->flushIntervalSeconds < 1) {
            throw new InvalidConfigException('QueryMonitor::$flushIntervalSeconds must be at least 1.');
        }
        // Checked here, not by the lazily created normaliser: a wrong setting disables the whole package (spec 01 §1).
        if ($this->maxQueryLength < strlen(SqlNormalizer::ELLIPSIS)) {
            throw new InvalidConfigException('QueryMonitor::$maxQueryLength must be at least ' . strlen(SqlNormalizer::ELLIPSIS) . ' bytes.');
        }
        $exclusions = RouteExclusions::fromConfig($this->excludedRoutes);
        $sampling = BatchSampling::fromConfig($this->sampling);
        // Its own guard: a source failing at every batch must not take the one log entry of the adapter, and it logs
        // the message of a rejected value only, never of an exception the source threw (spec 01 §5.7, §6).
        $user = UserSource::fromConfig($this->user, new Guard([UserValueException::class]));
        $this->batchAdapter = $this->resolveAdapter();
        $contexts = new ContextStack($guard, $this->batchAdapter, new ContextSettings(
            fn(BatchType $type, string $id, ?JobInfo $job): QueryCollector => $this->createCollector($type, $id, $job),
            $this->createClock(),
            $this->flushIntervalSeconds,
            $exclusions,
            $sampling,
            $user,
        ));
        $contexts->openRoot($app instanceof \yii\console\Application ? BatchType::Console : BatchType::Http);
        $sources = $this->createSources($contexts, $guard, CallerFrames::forProcess());
        foreach ($this->connections as $id) {
            $guard->run(fn() => $this->installOn($app, $id, $sources), "connection {$id}");
        }
        $this->contexts = $contexts;
        $this->captureEntryAction($app, $contexts, $guard);
        $app->on(Application::EVENT_AFTER_REQUEST, fn() => $this->finalize());
        // exit() in an action and exit(1) from the ErrorHandler skip EVENT_AFTER_REQUEST (ADR-0003).
        register_shutdown_function(fn() => $this->finalize());
    }

    /**
     * Stores the unique id of the first action of the request as `route` (spec 01 §4). The handler detaches
     * itself after the first action; an action run by the error handler (`errorAction`) is skipped and leaves
     * it attached, so a 404 before routing keeps `route` null.
     */
    private function captureEntryAction(Application $app, ContextStack $contexts, Guard $guard): void
    {
        // The parameter is a plain Event: a type error on a foreign trigger() must happen inside the guard.
        $handler = static function (Event $event) use (&$handler, $app, $contexts, $guard): void {
            $guard->run(static function () use ($event, $handler, $app, $contexts): void {
                if (!$event instanceof ActionEvent) {
                    return;
                }
                if ($app->has('errorHandler') && $app->getErrorHandler()->exception !== null) {
                    return;
                }
                $contexts->setRoute($event->action->getUniqueId());
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
        $defaults = [
            'path' => FileAdapter::DEFAULT_PATH,
            'maxSize' => FileAdapter::DEFAULT_MAX_SIZE,
            'maxFiles' => FileAdapter::DEFAULT_MAX_FILES,
            'fileMode' => FileAdapter::DEFAULT_FILE_MODE,
            'dirMode' => FileAdapter::DEFAULT_DIR_MODE,
        ];
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
        foreach (['fileMode', 'dirMode'] as $key) {
            if (!is_int($file[$key]) || $file[$key] < 0 || $file[$key] > 0o777) {
                throw new InvalidConfigException("QueryMonitor::\$file['{$key}'] must be an integer from 0 to 0777.");
            }
        }
        $path = \Yii::getAlias($file['path'], false);
        if ($path === false) {
            throw new InvalidConfigException("QueryMonitor::\$file['path'] uses an unknown alias: {$file['path']}.");
        }

        return new FileAdapter($path, $file['maxSize'], $file['maxFiles'], $file['fileMode'], $file['dirMode']);
    }
}
