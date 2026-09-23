<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\mongodb;

use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\SourceInterface;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;
use yii\base\InvalidConfigException;
use yii\mongodb\Connection;

/**
 * The MongoDB source: a driver subscriber on the `Manager` of each listed `yii\mongodb\Connection`
 * (spec 01 §1, §3, ADR-0010).
 *
 * Managers with the same client key share one libmongoc client and its subscribers, so there is one
 * {@see Subscriber} per key, added to every Manager of every listed connection with that key, immediately when
 * the connection is open and again in each `EVENT_AFTER_OPEN`. Its entries carry the first listed id whose
 * connection has that key when the subscriber is created. This class loads without `ext-mongodb` and
 * `yii2-mongodb`: `instanceof` does not autoload, and {@see Subscriber} is created only after the check.
 */
final class Source implements SourceInterface
{
    private ?MongoDbNormalizer $normalizer = null;

    /** @var list<array{string, Connection}> installed connections in the order of the list */
    private array $installed = [];

    /** @var array<string, Subscriber> by client key */
    private array $subscribers = [];

    /**
     * @param \Closure(): MongoDbNormalizer $createNormalizer
     */
    public function __construct(
        private readonly QueryCollector $collector,
        private readonly Guard $guard,
        private readonly CallerFrames $callers,
        private readonly \Closure $createNormalizer,
    ) {}

    public function supports(object $component): bool
    {
        return $component instanceof Connection;
    }

    public function install(string $id, object $component): void
    {
        if (!$component instanceof Connection) {
            throw new InvalidConfigException("Connection {$id} is not a yii\\mongodb\\Connection.");
        }
        if (!interface_exists(\MongoDB\Driver\Monitoring\CommandSubscriber::class)) {
            throw new InvalidConfigException("Connection {$id} needs ext-mongodb, which is not loaded.");
        }
        foreach ($this->installed as [, $connection]) {
            if ($connection === $component) {
                throw new InvalidConfigException("Connection {$id} is already monitored under another id.");
            }
        }
        $this->installed[] = [$id, $component];
        try {
            // Opened before the package's bootstrap, e.g. by a component listed earlier in `bootstrap`.
            $this->subscribe($component);
        } catch (\Throwable $e) {
            // Skipped as a whole: no later open() subscribes it either.
            array_pop($this->installed);

            throw $e;
        }
        $component->on(Connection::EVENT_AFTER_OPEN, function () use ($component): void {
            $this->guard->run(fn() => $this->subscribe($component), 'mongodb open');
        });
    }

    private function subscribe(Connection $connection): void
    {
        /** @var \MongoDB\Driver\Manager|null $manager null until open() and after close(), whatever the PHPDoc of yii2-mongodb says */
        $manager = $connection->manager;
        if ($manager === null) {
            return;
        }
        $key = $this->clientKey($connection);
        $subscriber = $this->subscribers[$key] ??= new Subscriber(new Recorder(
            $this->firstId($key),
            $this->normalizer ??= ($this->createNormalizer)(),
            $this->collector,
            $this->guard,
            $this->callers,
        ));
        // A second addSubscriber() of the same object on one Manager has no effect (YQM-32).
        $manager->addSubscriber($subscriber);
    }

    /**
     * The first listed id whose connection has `$key` now; the connection being opened is always among them.
     */
    private function firstId(string $key): string
    {
        foreach ($this->installed as [$id, $connection]) {
            if ($this->clientKey($connection) === $key) {
                return $id;
            }
        }

        throw new \LogicException('The connection being opened is not installed.');
    }

    /**
     * The key the driver shares its client by: the exact DSN, options and driver options, without
     * normalising. A connection without client persistence, or with options that cannot be serialised, has
     * a key of its own.
     */
    private function clientKey(Connection $connection): string
    {
        if (($connection->driverOptions['disableClientPersistence'] ?? false) === true) {
            return 'own:' . spl_object_id($connection);
        }
        try {
            return 'shared:' . serialize([$connection->dsn, $connection->options, $connection->driverOptions]);
        } catch (\Throwable) {
            return 'own:' . spl_object_id($connection);
        }
    }
}
