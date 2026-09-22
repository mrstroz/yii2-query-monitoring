<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit;

use PHPUnit\Framework\TestCase;
use yii\log\Logger;

/**
 * A unit test that reads what the package logged: each test gets its own Yii logger, which keeps every
 * message (no flush) and is removed afterwards.
 */
abstract class LoggedTestCase extends TestCase
{
    protected Logger $logger;

    protected function setUp(): void
    {
        if (!class_exists('Yii', false)) {
            require_once __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';
        }
        $this->logger = new Logger();
        $this->logger->flushInterval = PHP_INT_MAX;
        \yii\BaseYii::setLogger($this->logger);
    }

    protected function tearDown(): void
    {
        \yii\BaseYii::setLogger(null);
    }

    /**
     * Logged messages of level error, as `[message, level, category, time, ...]`.
     *
     * @return list<array<int, mixed>>
     */
    protected function errors(): array
    {
        return array_values(array_filter($this->logger->messages, static fn(array $m): bool => $m[1] === Logger::LEVEL_ERROR));
    }
}
