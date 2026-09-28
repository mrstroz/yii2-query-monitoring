<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\tests\app\console\ClockQueryMonitor;
use mrstroz\querymonitoring\tests\app\RunResult;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-48, spec 01 §5 and ADR-0012: a console command sends its entries in batches while it runs —
 * after the entry that reaches `maxEntries`, before the entry that does not fit in `maxBatchBytes`,
 * before the entry that comes after `flushIntervalSeconds` — and the rest when it ends.
 */
final class ConsoleBatchingTest extends IntegrationTestCase
{
    #[DataProvider('provideDatabaseCases')]
    public function testLongCommandSendsFullBatchesWhileRunningAndTheRestAtTheEnd(string $db): void
    {
        $result = $this->queries($db, 1200);

        $output = $this->scenarioOutput($result);
        self::assertSame(['500' => 1, '1000' => 2], $output['calls'], 'the 500th and 1000th query call the adapter while the command runs');
        self::assertSame([1, 2, 3], array_column($result->batches, 'seq'));
        self::assertCount(1, array_unique(array_column($result->batches, 'id')), 'one id for the whole run');
        self::assertSame(['console'], array_values(array_unique(array_column($result->batches, 'type'))));
        self::assertSame([range(1, 500), range(501, 1000), range(1001, 1200)], $this->queryNumbers($result));
        self::assertSame([0, 0, 0], array_column($result->batches, 'dropped'));
        $this->assertNoErrors($result);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testShortCommandSendsOneBatchWhenItEnds(string $db): void
    {
        $result = $this->queries($db, 3);

        self::assertSame([], $this->scenarioOutput($result)['calls'], 'no batch while the command runs');
        $batch = $this->singleBatch($result);
        self::assertSame(1, $batch['seq']);
        self::assertSame('scenario/run', $batch['route']);
        self::assertSame([range(1, 3)], $this->queryNumbers($result));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testByteLimitSendsTheBufferBeforeTheEntryThatDoesNotFit(string $db): void
    {
        $result = $this->queries($db, 60, ['maxBatchBytes' => 4096]);

        $numbers = $this->queryNumbers($result);
        self::assertGreaterThan(1, count($numbers), 'the byte limit splits the run');
        self::assertSame(range(1, 60), array_merge(...$numbers), 'every entry once, in order, none lost at a split');
        foreach ($result->batches as $batch) {
            self::assertLessThanOrEqual(4096, strlen((string) json_encode($batch, QueryBatch::JSON_FLAGS)), "batch seq {$batch['seq']}");
            self::assertSame(0, $batch['dropped']);
        }
        $calls = $this->scenarioOutput($result)['calls'];
        self::assertSame(array_map(static fn(array $batch): int => $batch[0], array_slice($numbers, 1)), array_map('intval', array_keys($calls)), 'each split happens at the query that opens the next batch');
    }

    #[DataProvider('provideDatabaseCases')]
    public function testEntryLargerThanAnEmptyBatchIsDroppedWithoutASend(string $db): void
    {
        $result = $this->queries($db, 6, ['maxBatchBytes' => 3000], ['QM_BIG' => '[4]', 'QM_BIG_COLUMNS' => '300']);

        self::assertSame([], $this->scenarioOutput($result)['calls'], 'the oversized entry does not flush the buffer');
        $batch = $this->singleBatch($result);
        self::assertSame(1, $batch['dropped'], 'the oversized entry counts in dropped of the batch it fell into');
        self::assertSame([[1, 2, 3, 5, 6]], $this->queryNumbers($result));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testDroppedIsCountedInTheBatchThatWasOpenAndResetAfterTheSend(string $db): void
    {
        $result = $this->queries($db, 8, ['maxEntries' => 5, 'maxBatchBytes' => 3000], ['QM_BIG' => '[2]', 'QM_BIG_COLUMNS' => '300']);

        self::assertSame([1, 2], array_column($result->batches, 'seq'));
        self::assertSame([1, 0], array_column($result->batches, 'dropped'));
        self::assertSame([[1, 3, 4, 5, 6], [7, 8]], $this->queryNumbers($result));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testIntervalSendsTheBufferAtTheNextQuery(string $db): void
    {
        $result = $this->queries($db, 6, ['class' => ClockQueryMonitor::class, 'flushIntervalSeconds' => 30], ['QM_CLOCK' => '{"1": 0, "4": 31}']);

        self::assertSame(['4' => 1], $this->scenarioOutput($result)['calls'], 'the buffer goes at the first query after the interval');
        self::assertSame([[1, 2, 3], [4, 5, 6]], $this->queryNumbers($result));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testIdleCommandDoesNotSendWhenTheIntervalPasses(string $db): void
    {
        $result = $this->queries($db, 3, ['class' => ClockQueryMonitor::class, 'flushIntervalSeconds' => 30], ['QM_CLOCK' => '{"1": 0}', 'QM_IDLE_CLOCK' => '1000']);

        $output = $this->scenarioOutput($result);
        self::assertSame(0, $output['idleCalls'], 'no timer: nothing is sent without another operation');
        self::assertSame([[1, 2, 3]], $this->queryNumbers($result));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testLostBatchLeavesAGapInSeqAndCollectingGoesOn(string $db): void
    {
        $result = $this->queries($db, 1200, [], ['QM_ADAPTER' => 'throw-on-2']);

        self::assertCount(3, $result->adapterCalls, 'the adapter is still called after its failure');
        self::assertSame([1, 3], array_column($result->batches, 'seq'));
        self::assertSame([range(1, 500), range(1001, 1200)], $this->queryNumbers($result), 'no entry of the lost batch moves to the next one');
        $this->assertOnePackageError($result);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testBufferFullBeforeTheRouteIsKnownGoesAsSoonAsTheRouteIsNotExcluded(string $db): void
    {
        $result = $this->queries($db, 2, ['maxEntries' => 1], ['QM_BOOTSTRAP_QUERY' => '1']);

        self::assertSame(['1' => 2, '2' => 3], $this->scenarioOutput($result)['calls'], 'the bootstrap batch went before the first query of the action');
        self::assertSame([1, 2, 3], array_column($result->batches, 'seq'));
        self::assertNotSame([], $this->entriesWith($result->batches[0], 'qm_bootstrap'));
        self::assertSame('scenario/run', $result->batches[0]['route']);
        self::assertSame([[], [1], [2]], $this->queryNumbers($result));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testFlushIntervalBelowOneDisablesThePackage(string $db): void
    {
        $result = $this->queries($db, 3, ['flushIntervalSeconds' => 0]);

        self::assertSame([], $result->batches);
        $this->assertOnePackageError($result);
    }

    /**
     * @param array<string, mixed> $component
     * @param array<string, string> $env
     */
    private function queries(string $db, int $count, array $component = [], array $env = []): RunResult
    {
        $clock = (string) tempnam(sys_get_temp_dir(), 'qm-clock-');
        try {
            $result = $this->consoleScenario($db, 'console-queries', $component, ['QM_QUERIES' => (string) $count, 'QM_CLOCK_FILE' => $clock] + $env);
        } finally {
            @unlink($clock);
        }
        $this->assertProcessOk($result);

        return $result;
    }

    /**
     * The numbers i of `SELECT ? AS qm_c_<i>` entries, one list per batch.
     *
     * @return list<list<int>>
     */
    private function queryNumbers(RunResult $result): array
    {
        return array_map(static function (array $batch): array {
            $numbers = [];
            foreach ($batch['queries'] as $entry) {
                if (is_string($entry['query']) && preg_match('/qm_c_(\d+)$/', $entry['query'], $match) === 1) {
                    $numbers[] = (int) $match[1];
                }
            }

            return $numbers;
        }, $result->batches);
    }
}
