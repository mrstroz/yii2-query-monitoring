<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\sql;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\sql\Recorder;
use mrstroz\querymonitoring\sql\SqlNormalizer;
use mrstroz\querymonitoring\support\Guard;
use PHPUnit\Framework\TestCase;
use yii\log\Logger;

/**
 * YQM-9, spec 01 §6: a recorder whose collector does not accept does nothing, not even normalise;
 * a failure inside it is swallowed with one package error.
 */
final class RecorderTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        if (!class_exists('Yii', false)) {
            require_once __DIR__ . '/../../../vendor/yiisoft/yii2/Yii.php';
        }
        $this->logger = new Logger();
        $this->logger->flushInterval = PHP_INT_MAX;
        \yii\BaseYii::setLogger($this->logger);
    }

    protected function tearDown(): void
    {
        \yii\BaseYii::setLogger(null);
    }

    public function testRecordsWhileAccepting(): void
    {
        $collector = $this->collector();
        $this->recorder(new SqlNormalizer(), $collector)->record('SELECT 1', 1.23456, null);

        $queries = $collector->close(new \DateTimeImmutable())->queries;
        self::assertCount(1, $queries);
        self::assertSame('SELECT ?', $queries[0]->query);
        self::assertSame(1.235, $queries[0]->timeMs);
    }

    public function testPausedCollectorStopsRecorderBeforeNormalising(): void
    {
        $collector = $this->collector();
        $collector->pause();

        $this->recorder($this->brokenNormalizer(), $collector)->record('SELECT 1', 1.0, null);

        self::assertSame(0, $collector->count());
        self::assertSame([], $this->errors(), 'the broken normaliser was not reached');
    }

    public function testClosedCollectorStopsRecorderBeforeNormalising(): void
    {
        $collector = $this->collector();
        $collector->close(new \DateTimeImmutable());

        $this->recorder($this->brokenNormalizer(), $collector)->record('SELECT 1', 1.0, null);

        self::assertSame([], $this->errors());
    }

    public function testFailureIsSwallowedWithOnePackageError(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($this->brokenNormalizer(), $collector);

        $recorder->record('SELECT 1', 1.0, null);
        $recorder->record('SELECT 2', 1.0, '42000');

        self::assertSame(0, $collector->count());
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame(Guard::LOG_CATEGORY, $errors[0][2]);
        self::assertStringNotContainsString('SELECT', (string) $errors[0][0]);
    }

    private function recorder(SqlNormalizer $normalizer, QueryCollector $collector): Recorder
    {
        return new Recorder('db', 'mysql', $normalizer, $collector, new Guard());
    }

    private function brokenNormalizer(): SqlNormalizer
    {
        // uninitialised readonly maxQueryLength: normalize() throws \Error, as FaultyQueryMonitor does
        return (new \ReflectionClass(SqlNormalizer::class))->newInstanceWithoutConstructor();
    }

    private function collector(): QueryCollector
    {
        return new QueryCollector('app', BatchType::Http, 'req_1', 'host');
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function errors(): array
    {
        return array_values(array_filter($this->logger->messages, static fn(array $m): bool => $m[1] === Logger::LEVEL_ERROR));
    }
}
