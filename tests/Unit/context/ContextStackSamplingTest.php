<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\context;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\batch\Sample;
use mrstroz\querymonitoring\batch\SampleReason;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\context\BatchSampling;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use mrstroz\querymonitoring\tests\Unit\StackProbe;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-58, spec 01 §5.6, spec 02 §7: with sampling on, the stack decides for each finished batch before the adapter —
 * a batch meeting an enabled criterion goes whole with rate 1.0 and its reason, an ordinary one goes with the
 * configured rate when its draw is below it, and is otherwise never handed to the adapter. The draw is injected and
 * keyed by `seq`, so no test depends on chance.
 */
final class ContextStackSamplingTest extends LoggedTestCase
{
    public function testWithoutSamplingEveryBatchGoesWithANullSample(): void
    {
        $probe = $this->console(null, maxEntries: 2);

        $probe->stack->add($this->entry('q1'));
        $probe->stack->add($this->entry('q2'));
        $probe->stack->add($this->entry('q3'));
        $probe->stack->finalize();

        self::assertSame([['q1', 'q2'], ['q3']], $this->queries($probe));
        foreach ($probe->batches as $batch) {
            self::assertNull($batch->sample);
            self::assertSame(4, $batch->toArray()['v']);
            self::assertArrayHasKey('sample', $batch->toArray());
        }
    }

    public function testOrdinaryBatchWithDrawBelowTheRateGoesWithTheConfiguredRate(): void
    {
        $probe = $this->http(new BatchSampling(0.1, draw: static fn(QueryBatch $b): float => 0.0999));

        $probe->stack->add($this->entry('q1'));
        $probe->stack->finalize();

        self::assertCount(1, $probe->batches);
        $sample = $probe->batches[0]->sample;
        self::assertNotNull($sample);
        self::assertSame(0.1, $sample->rate);
        self::assertSame(SampleReason::Sample, $sample->reason);
        self::assertSame(4, $probe->batches[0]->toArray()['v']);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideSkippedDrawCases(): iterable
    {
        yield 'draw equal to the rate' => [0.1];
        yield 'draw above the rate' => [0.5];
        yield 'highest draw' => [0.9999999];
    }

    #[DataProvider('provideSkippedDrawCases')]
    public function testOrdinaryBatchWithDrawAtOrAboveTheRateNeverReachesTheAdapter(float $draw): void
    {
        $probe = $this->http(new BatchSampling(0.1, draw: static fn(QueryBatch $b): float => $draw));

        $probe->stack->add($this->entry('q1'));
        $probe->stack->finalize();

        self::assertSame(0, $probe->calls, 'the adapter is not called for a skipped batch');
        self::assertSame([], $this->logger->messages, 'a skipped batch logs nothing');
    }

    public function testRateOneSendsEveryOrdinaryBatchWithRateOne(): void
    {
        $probe = $this->console(new BatchSampling(1.0, draw: static fn(QueryBatch $b): float => 0.9999999), maxEntries: 1);

        $probe->stack->add($this->entry('q1'));
        $probe->stack->add($this->entry('q2'));
        $probe->stack->finalize();

        self::assertCount(2, $probe->batches);
        foreach ($probe->batches as $batch) {
            self::assertEquals(new Sample(1.0, SampleReason::Sample), $batch->sample);
        }
    }

    public function testRateZeroSendsOnlyDiagnosticBatches(): void
    {
        $probe = $this->console(new BatchSampling(0.0, keepErrors: true, draw: static fn(QueryBatch $b): float => 0.0), maxEntries: 1);

        $probe->stack->add($this->entry('q1'));
        $probe->stack->add($this->error('q2'));
        $probe->stack->add($this->entry('q3'));
        $probe->stack->finalize();

        self::assertSame([2], $this->seqs($probe));
        self::assertEquals(new Sample(1.0, SampleReason::Error), $probe->batches[0]->sample);
    }

    /**
     * @return iterable<string, array{BatchSampling, list<QueryEntry>, SampleReason}>
     */
    public static function provideCriterionCases(): iterable
    {
        $never = static fn(QueryBatch $b): float => 0.9999999;

        yield 'one error entry' => [
            new BatchSampling(0.1, keepErrors: true, draw: $never),
            [self::entryOf('q1', 1.0), self::errorOf('q2', 1.0)],
            SampleReason::Error,
        ];
        yield 'single query exactly at slowQueryMs' => [
            new BatchSampling(0.1, slowQueryMs: 200.0, draw: $never),
            [self::entryOf('q1', 1.0), self::entryOf('q2', 200.0)],
            SampleReason::SlowQuery,
        ];
        yield 'batch time exactly at slowBatchMs' => [
            new BatchSampling(0.1, slowBatchMs: 300.0, draw: $never),
            [self::entryOf('q1', 100.0), self::entryOf('q2', 150.0), self::entryOf('q3', 50.0)],
            SampleReason::SlowBatch,
        ];
        yield 'query count exactly at minQueries' => [
            new BatchSampling(0.1, minQueries: 3, draw: $never),
            [self::entryOf('q1', 1.0), self::entryOf('q2', 1.0), self::entryOf('q3', 1.0)],
            SampleReason::ManyQueries,
        ];
    }

    /**
     * @param list<QueryEntry> $entries
     */
    #[DataProvider('provideCriterionCases')]
    public function testEnabledCriterionSendsTheBatchWithRateOneAndItsReason(BatchSampling $sampling, array $entries, SampleReason $reason): void
    {
        $probe = $this->http($sampling);

        foreach ($entries as $entry) {
            $probe->stack->add($entry);
        }
        $probe->stack->finalize();

        self::assertCount(1, $probe->batches);
        self::assertEquals(new Sample(1.0, $reason), $probe->batches[0]->sample);
        self::assertSame($entries, $probe->batches[0]->queries, 'every entry, in order, unchanged');
    }

    /**
     * @return iterable<string, array{BatchSampling, list<QueryEntry>}>
     */
    public static function provideBelowCriterionCases(): iterable
    {
        $never = static fn(QueryBatch $b): float => 0.9999999;

        yield 'slowest query just below slowQueryMs' => [
            new BatchSampling(0.1, slowQueryMs: 200.0, draw: $never),
            [self::entryOf('q1', 199.999)],
        ];
        yield 'batch time just below slowBatchMs' => [
            new BatchSampling(0.1, slowBatchMs: 300.0, draw: $never),
            [self::entryOf('q1', 150.0), self::entryOf('q2', 149.999)],
        ];
        yield 'one query fewer than minQueries' => [
            new BatchSampling(0.1, minQueries: 3, draw: $never),
            [self::entryOf('q1', 1.0), self::entryOf('q2', 1.0)],
        ];
        yield 'error entry with keepErrors off' => [
            new BatchSampling(0.1, keepErrors: false, slowQueryMs: 1000.0, slowBatchMs: 1000.0, minQueries: 10, draw: $never),
            [self::errorOf('q1', 1.0)],
        ];
        yield 'slow query with slowQueryMs off' => [
            new BatchSampling(0.1, keepErrors: true, draw: $never),
            [self::entryOf('q1', 100000.0)],
        ];
        yield 'many queries with every threshold off' => [
            new BatchSampling(0.1, keepErrors: true, draw: $never),
            array_map(static fn(int $i): QueryEntry => self::entryOf("q{$i}", 1000.0), range(1, 50)),
        ];
    }

    /**
     * @param list<QueryEntry> $entries
     */
    #[DataProvider('provideBelowCriterionCases')]
    public function testBatchMeetingNoEnabledCriterionIsDecidedByTheDraw(BatchSampling $sampling, array $entries): void
    {
        $probe = $this->http($sampling);

        foreach ($entries as $entry) {
            $probe->stack->add($entry);
        }
        $probe->stack->finalize();

        self::assertSame(0, $probe->calls);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideMinQueriesWithDroppedCases(): iterable
    {
        yield 'two stored and three dropped reach five' => [5, true];
        yield 'two stored and two dropped stay below five' => [4, false];
    }

    /**
     * spec 01 §5.6: `minQueries` counts the queries the batch describes, its entries and `dropped`, so a request
     * cut at `maxEntries` is kept when the queries it really ran reach the threshold.
     */
    #[DataProvider('provideMinQueriesWithDroppedCases')]
    public function testMinQueriesCountsStoredAndDroppedQueriesOfAnHttpBatch(int $queries, bool $kept): void
    {
        $probe = $this->http(new BatchSampling(0.1, minQueries: 5, draw: static fn(QueryBatch $b): float => 0.9999999), maxEntries: 2);

        for ($i = 1; $i <= $queries; $i++) {
            if (!$probe->stack->dropIfFull()) {
                $probe->stack->add($this->entry("q{$i}"));
            }
        }
        $probe->stack->finalize();

        if (!$kept) {
            self::assertSame(0, $probe->calls, 'four queries are below five');

            return;
        }
        self::assertCount(1, $probe->batches);
        self::assertEquals(new Sample(1.0, SampleReason::ManyQueries), $probe->batches[0]->sample);
        self::assertSame(['q1', 'q2'], $this->queries($probe)[0]);
        self::assertSame(3, $probe->batches[0]->dropped, 'dropped is not changed by the decision');
    }

    public function testMinQueriesCountsAnEntryTooLargeForAConsoleBatch(): void
    {
        $probe = $this->console(new BatchSampling(0.1, minQueries: 3, draw: static fn(QueryBatch $b): float => 0.9999999), maxBatchBytes: 1000);

        $probe->stack->add($this->entry('q1'));
        $probe->stack->add($this->entry(str_repeat('x', 2000)));
        $probe->stack->add($this->entry('q2'));
        $probe->stack->finalize();

        self::assertCount(1, $probe->batches);
        self::assertSame(1, $probe->batches[0]->dropped);
        self::assertEquals(new Sample(1.0, SampleReason::ManyQueries), $probe->batches[0]->sample);
    }

    /**
     * @return iterable<string, array{list<QueryEntry>, SampleReason}>
     */
    public static function providePrecedenceCases(): iterable
    {
        yield 'error before slow query' => [[self::errorOf('q1', 500.0), self::entryOf('q2', 500.0), self::entryOf('q3', 500.0)], SampleReason::Error];
        yield 'slow query before slow batch' => [[self::entryOf('q1', 500.0), self::entryOf('q2', 500.0), self::entryOf('q3', 500.0)], SampleReason::SlowQuery];
        yield 'slow batch before many queries' => [[self::entryOf('q1', 100.0), self::entryOf('q2', 100.0), self::entryOf('q3', 100.0)], SampleReason::SlowBatch];
        yield 'error after a slow query' => [[self::entryOf('q1', 500.0), self::entryOf('q2', 500.0), self::errorOf('q3', 1.0)], SampleReason::Error];
        yield 'slow query after entries matching only the batch criteria' => [[self::entryOf('q1', 100.0), self::entryOf('q2', 100.0), self::entryOf('q3', 400.0)], SampleReason::SlowQuery];
        yield 'many queries alone' => [[self::entryOf('q1', 1.0), self::entryOf('q2', 1.0), self::entryOf('q3', 1.0)], SampleReason::ManyQueries];
    }

    /**
     * @param list<QueryEntry> $entries
     */
    #[DataProvider('providePrecedenceCases')]
    public function testFirstMatchingCriterionNamesTheReason(array $entries, SampleReason $reason): void
    {
        $probe = $this->http(new BatchSampling(0.1, keepErrors: true, slowQueryMs: 400.0, slowBatchMs: 300.0, minQueries: 3, draw: static fn(QueryBatch $b): float => 0.0));

        foreach ($entries as $entry) {
            $probe->stack->add($entry);
        }
        $probe->stack->finalize();

        self::assertEquals(new Sample(1.0, $reason), $probe->batches[0]->sample, 'a criterion wins over a winning draw');
    }

    public function testSelectedBatchIsTheFinishedBatchWithOnlyTheSampleAdded(): void
    {
        $seen = [];
        $probe = $this->console(new BatchSampling(0.5, draw: static function (QueryBatch $b) use (&$seen): float {
            $seen[] = $b;

            return 0.0;
        }), maxEntries: 3);

        $probe->stack->dropIfFull();
        foreach ([$this->entry('q1', 3.25), $this->entry('q2', 0.5), $this->entry('q3', 7.0)] as $entry) {
            $probe->stack->add($entry);
        }
        $probe->stack->finalize();

        self::assertCount(1, $seen, 'the draw sees the batch once, before the sample is set');
        self::assertNull($seen[0]->sample);
        $sent = $probe->batches[0]->toArray();
        $original = $seen[0]->toArray();
        self::assertSame(['rate' => 0.5, 'reason' => 'sample'], $sent['sample']);
        self::assertNull($original['sample']);
        unset($sent['sample'], $original['sample']);
        self::assertSame($original, $sent, 'entries, order, times, dropped, id, seq, route and ts unchanged');
    }

    public function testSkippedBatchLeavesAGapInSeqAndTheNextBatchHoldsOnlyNewEntries(): void
    {
        $probe = $this->console($this->drawBySeq(0.5, [1 => 0.0, 2 => 0.9, 3 => 0.0]), maxEntries: 2);

        foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $query) {
            $probe->stack->add($this->entry($query));
        }
        $probe->stack->finalize();

        self::assertSame([1, 3], $this->seqs($probe));
        self::assertSame([['q1', 'q2'], ['q5', 'q6']], $this->queries($probe));
        self::assertCount(1, array_unique(array_map(static fn(QueryBatch $b): string => $b->id, $probe->batches)));
        self::assertSame([], $this->logger->messages);
    }

    public function testSkippedBatchTakesItsDroppedWithItAndTheNextStartsFromZero(): void
    {
        $probe = $this->console($this->drawBySeq(0.5, [1 => 0.9, 2 => 0.0]), maxEntries: 2, maxBatchBytes: 700);

        $probe->stack->add($this->entry('q1'));
        $probe->stack->add($this->entry(str_repeat('x', 2000)));
        $probe->stack->add($this->entry('q2'));
        $probe->stack->add($this->entry('q3'));
        $probe->stack->finalize();

        self::assertSame([2], $this->seqs($probe));
        self::assertSame(0, $probe->batches[0]->dropped);
    }

    public function testLaterErrorDoesNotBringBackAnEarlierSkippedBatch(): void
    {
        $sampling = $this->drawBySeq(0.5, [1 => 0.9, 2 => 0.9], keepErrors: true);
        $probe = $this->console($sampling, maxEntries: 1);

        $probe->stack->add($this->entry('q1'));
        $probe->stack->add($this->error('q2'));
        $probe->stack->finalize();

        self::assertSame([2], $this->seqs($probe));
        self::assertSame([['q2']], $this->queries($probe));
    }

    public function testEachJobBatchIsDecidedOnItsOwnWithItsOwnIdAndSeq(): void
    {
        $probe = $this->console($this->drawBySeq(0.5, [1 => 0.9, 2 => 0.0, 3 => 0.9]), maxEntries: 1);

        $probe->stack->add($this->entry('root1'));
        $job = $probe->stack->beginJob(new JobInfo('job'), 'job');
        self::assertNotNull($job);
        $probe->stack->add($this->entry('job1'));
        $probe->stack->add($this->entry('job2'));
        $probe->stack->add($this->entry('job3'));
        $probe->stack->end($job);
        $probe->stack->add($this->entry('root2'));
        $probe->stack->finalize();

        self::assertSame([['job2'], ['root2']], $this->queries($probe));
        self::assertSame([BatchType::Job, BatchType::Console], array_map(static fn(QueryBatch $b): BatchType => $b->type, $probe->batches));
        self::assertSame([2, 2], $this->seqs($probe), 'original seq of each context, gaps allowed');
        self::assertNotSame($probe->batches[0]->id, $probe->batches[1]->id);
    }

    public function testSkippedBatchDoesNotUseUpTheOneErrorLogOfTheProcess(): void
    {
        $probe = $this->console($this->drawBySeq(0.5, [1 => 0.9, 2 => 0.0]), maxEntries: 1);
        $probe->failOnCall = 1;

        $probe->stack->add($this->entry('q1'));
        $probe->stack->add($this->entry('q2'));
        $probe->stack->finalize();

        self::assertSame(1, $probe->calls, 'only the selected batch reaches the adapter');
        self::assertCount(1, $this->errors(), 'the failure of the selected batch is still logged');
    }

    /**
     * spec 02 §5: the stack sets `sample` after the context filled the batch, so the context reserves its width.
     * Consecutive limits over the width of one entry make some batch end within a byte of the limit.
     */
    public function testSampledBatchStaysWithinMaxBatchBytesAtEveryLimit(): void
    {
        $entryBytes = strlen(json_encode($this->entry('q0000')->toArray(), QueryBatch::JSON_FLAGS)) + 1;
        for ($max = 1500; $max <= 1500 + $entryBytes; $max++) {
            $probe = $this->console(new BatchSampling(0.12345678901234568, draw: static fn(QueryBatch $b): float => 0.0), maxBatchBytes: $max);

            for ($i = 0; $i < 60; $i++) {
                $probe->stack->add($this->entry(sprintf('q%04d', $i)));
            }
            $probe->stack->finalize();

            self::assertGreaterThan(1, count($probe->batches), "limit {$max} splits");
            foreach ($probe->batches as $batch) {
                self::assertLessThanOrEqual($max, strlen($batch->toJson()), "limit {$max}, seq {$batch->seq}");
            }
            self::assertSame(60, count($probe->entries()), "limit {$max}: nothing lost to the reservation");
        }
    }

    private function http(?BatchSampling $sampling, int $maxEntries = QueryCollector::DEFAULT_MAX_ENTRIES): StackProbe
    {
        $probe = new StackProbe($maxEntries, sampling: $sampling);
        $probe->stack->setRoute('site/index');

        return $probe;
    }

    private function console(
        ?BatchSampling $sampling,
        int $maxEntries = QueryCollector::DEFAULT_MAX_ENTRIES,
        int $maxBatchBytes = QueryCollector::DEFAULT_MAX_BATCH_BYTES,
    ): StackProbe {
        $probe = new StackProbe($maxEntries, $maxBatchBytes, BatchType::Console, sampling: $sampling);
        $probe->stack->setRoute('scenario/run');

        return $probe;
    }

    /**
     * @param array<int, float> $draws draw of the batch with that `seq`
     */
    private function drawBySeq(float $rate, array $draws, bool $keepErrors = false): BatchSampling
    {
        return new BatchSampling($rate, keepErrors: $keepErrors, draw: static fn(QueryBatch $b): float => $draws[$b->seq]);
    }

    /**
     * @return list<int>
     */
    private function seqs(StackProbe $probe): array
    {
        return array_map(static fn(QueryBatch $b): int => $b->seq, $probe->batches);
    }

    /**
     * @return list<list<string>>
     */
    private function queries(StackProbe $probe): array
    {
        return array_map(
            static fn(QueryBatch $batch): array => array_map(static fn(QueryEntry $e): string => (string) $e->query, $batch->queries),
            $probe->batches,
        );
    }

    private function entry(string $query, float $timeMs = 1.0): QueryEntry
    {
        return self::entryOf($query, $timeMs);
    }

    private function error(string $query): QueryEntry
    {
        return self::errorOf($query, 1.0);
    }

    private static function entryOf(string $query, float $timeMs): QueryEntry
    {
        return QueryEntry::success('mysql', 'db', 'select', $query, $timeMs, []);
    }

    private static function errorOf(string $query, float $timeMs): QueryEntry
    {
        return QueryEntry::error('mysql', 'db', 'select', $query, $timeMs, '42S02', []);
    }
}
