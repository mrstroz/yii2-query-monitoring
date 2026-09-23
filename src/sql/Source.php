<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\sql;

use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\SourceInterface;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;
use yii\base\InvalidConfigException;
use yii\db\Connection;

/**
 * The SQL source: the measured {@see Command} in `commandMap` of each listed `yii\db\Connection` (spec 01 §1, §2).
 *
 * The normaliser comes from a factory called on the first SQL connection installed and shared by all of them.
 */
final class Source implements SourceInterface
{
    private ?SqlNormalizer $normalizer = null;

    /**
     * @param \Closure(): SqlNormalizer $createNormalizer
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
            throw new InvalidConfigException("Connection {$id} is not a yii\\db\\Connection.");
        }
        if (!Command::supportsInstalledYii()) {
            throw new InvalidConfigException('The installed yii\\db\\Command lacks the private fields the measured Command reads; see ADR-0001.');
        }
        // Without a DSN prefix getDriverName() would open a connection; with one it only reads the DSN
        // (or an explicit driverName) and returns the key createCommand() looks up in commandMap.
        if (!str_contains((string) $component->dsn, ':')) {
            throw new InvalidConfigException("Connection {$id} has no DSN to read the driver from.");
        }
        $driver = (string) $component->getDriverName();
        if (Dialect::tryFrom($driver) === null) {
            throw new InvalidConfigException("Connection {$id} uses driver {$driver}, which is not monitored.");
        }
        if ($component->commandClass !== \yii\db\Command::class) {
            throw new InvalidConfigException("Connection {$id} sets its own commandClass.");
        }
        $current = $component->commandMap[$driver] ?? \yii\db\Command::class;
        if (is_array($current) && ($current['class'] ?? null) === Command::class) {
            throw new InvalidConfigException("Connection {$id} is already monitored under another id.");
        }
        if ($current !== \yii\db\Command::class) {
            throw new InvalidConfigException("Connection {$id} sets its own commandMap for {$driver}.");
        }
        $this->normalizer ??= ($this->createNormalizer)();
        $component->commandMap[$driver] = [
            'class' => Command::class,
            'recorder' => new Recorder($id, $driver, $this->normalizer, $this->collector, $this->guard, $this->callers),
        ];
    }
}
