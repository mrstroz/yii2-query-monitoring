<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\sql;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\sql\Recorder;
use mrstroz\querymonitoring\sql\SqlNormalizer;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-9, spec 01 §6: a recorder whose collector does not accept does nothing, not even normalise;
 * a full collector only counts the query (spec 01 §2); a failure inside it is swallowed with one package error.
 */
final class RecorderTest extends LoggedTestCase
{
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

    /**
     * spec 01 §2: after a limit stops intake, a query only increases `dropped`.
     *
     * @return iterable<string, array{QueryCollector}>
     */
    public static function provideFullCollectorCases(): iterable
    {
        $byEntries = new QueryCollector('app', BatchType::Http, 'req_1', 'host', maxEntries: 1);
        $byEntries->add(QueryEntry::success('mysql', 'db', 'select', 'SELECT ?', 1.0, []));
        yield 'entry limit' => [$byEntries];

        $byBytes = new QueryCollector('app', BatchType::Http, 'req_1', 'host', maxBatchBytes: 600);
        $byBytes->add(QueryEntry::success('mysql', 'db', 'select', str_repeat('x', 1000), 1.0, []));
        yield 'byte limit' => [$byBytes];
    }

    #[DataProvider('provideFullCollectorCases')]
    public function testFullCollectorStopsRecorderBeforeNormalising(QueryCollector $collector): void
    {
        $count = $collector->count();
        $dropped = $collector->dropped();

        $recorder = $this->recorder($this->brokenNormalizer(), $collector);
        $recorder->record('SELECT 1', 1.0, null);
        $recorder->record('SELECT 2', 1.0, '42000');

        self::assertSame([], $this->errors(), 'the broken normaliser was not reached');
        self::assertSame($dropped + 2, $collector->dropped(), 'each query is counted as dropped');
        self::assertSame($count, $collector->count());
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
        $root = dirname(__DIR__, 3);

        return new Recorder('db', 'mysql', $normalizer, $collector, new Guard(), new CallerFrames($root, $root . '/vendor', $root . '/src', null));
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
}
