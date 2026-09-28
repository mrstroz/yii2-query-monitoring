<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\sql;

use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\sql\Recorder;
use mrstroz\querymonitoring\sql\SqlNormalizer;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use mrstroz\querymonitoring\tests\Unit\StackProbe;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-9 and YQM-47, spec 01 §6: a recorder whose context stack does not accept does nothing, not even normalise;
 * a full batch only counts the query (spec 01 §2); a failure inside it is swallowed with one package error.
 */
final class RecorderTest extends LoggedTestCase
{
    public function testRecordsWhileAccepting(): void
    {
        $probe = new StackProbe();
        $this->recorder(new SqlNormalizer(), $probe)->record('SELECT 1', 1.23456, null);

        $queries = $probe->finish();
        self::assertCount(1, $queries);
        self::assertSame('SELECT ?', $queries[0]->query);
        self::assertSame(1.235, $queries[0]->timeMs);
    }

    public function testQueryRunByTheAdapterIsNeitherNormalisedNorCounted(): void
    {
        $probe = new StackProbe();
        $recorder = $this->recorder($this->brokenNormalizer(), $probe);
        $probe->stack->add(QueryEntry::success('mysql', 'db', 'select', 'SELECT ?', 1.0, []));
        $probe->onSend = static fn() => $recorder->record('SELECT 1', 1.0, null);

        $queries = $probe->finish();

        self::assertCount(1, $queries, 'only the entry from before the send');
        self::assertSame(0, $probe->dropped());
        self::assertSame([], $this->errors(), 'the broken normaliser was not reached');
    }

    public function testFinalisedStackStopsRecorderBeforeNormalising(): void
    {
        $probe = new StackProbe();
        $probe->stack->finalize();

        $this->recorder($this->brokenNormalizer(), $probe)->record('SELECT 1', 1.0, null);

        self::assertSame([], $this->errors());
        self::assertSame([], $probe->batches);
    }

    /**
     * spec 01 §2: after a limit stops intake, a query only increases `dropped`.
     *
     * The first entry fills the batch by count, or by bytes it does not fit and is dropped itself.
     *
     * @return iterable<string, array{int, int, string, int}>
     */
    public static function provideFullBatchCases(): iterable
    {
        yield 'entry limit' => [1, QueryCollector::DEFAULT_MAX_BATCH_BYTES, 'SELECT ?', 1];
        yield 'byte limit' => [QueryCollector::DEFAULT_MAX_ENTRIES, 600, str_repeat('x', 1000), 0];
    }

    #[DataProvider('provideFullBatchCases')]
    public function testFullBatchStopsRecorderBeforeNormalising(int $maxEntries, int $maxBatchBytes, string $first, int $kept): void
    {
        $probe = new StackProbe($maxEntries, $maxBatchBytes);
        $probe->stack->add(QueryEntry::success('mysql', 'db', 'select', $first, 1.0, []));
        $recorder = $this->recorder($this->brokenNormalizer(), $probe);

        $recorder->record('SELECT 1', 1.0, null);
        $recorder->record('SELECT 2', 1.0, '42000');

        self::assertSame([], $this->errors(), 'the broken normaliser was not reached');
        self::assertCount($kept, $probe->finish());
        self::assertSame(3 - $kept, $probe->dropped(), 'each query is counted as dropped');
    }

    public function testFailureIsSwallowedWithOnePackageError(): void
    {
        $probe = new StackProbe();
        $recorder = $this->recorder($this->brokenNormalizer(), $probe);

        $recorder->record('SELECT 1', 1.0, null);
        $recorder->record('SELECT 2', 1.0, '42000');

        self::assertSame([], $probe->finish());
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame(Guard::LOG_CATEGORY, $errors[0][2]);
        self::assertStringNotContainsString('SELECT', (string) $errors[0][0]);
    }

    private function recorder(SqlNormalizer $normalizer, StackProbe $probe): Recorder
    {
        $root = dirname(__DIR__, 3);

        return new Recorder('db', 'mysql', $normalizer, $probe->stack, new Guard(), new CallerFrames($root, $root . '/vendor', $root . '/src', null));
    }

    private function brokenNormalizer(): SqlNormalizer
    {
        // uninitialised readonly maxQueryLength: normalize() throws \Error, as FaultyQueryMonitor does
        return (new \ReflectionClass(SqlNormalizer::class))->newInstanceWithoutConstructor();
    }
}
