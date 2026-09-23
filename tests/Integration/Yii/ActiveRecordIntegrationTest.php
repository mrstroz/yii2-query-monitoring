<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-10, spec 00 §6 (criteria 2 and 3): Active Record, the query cache, two connections and savepoints
 * go through the replaced Command, with Yii profiling and logging switched off.
 */
final class ActiveRecordIntegrationTest extends IntegrationTestCase
{
    #[DataProvider('databases')]
    public function testActiveRecordWithDifferentValuesGivesSameQueryWithoutValues(string $db): void
    {
        $this->requireDatabase($db);
        Schema::create(Schema::connection($db));

        $result = $this->scenario($db, 'ar-params');
        $out = $this->scenarioOutput($result);
        self::assertSame([['17.25'], ['42.75']], $out['found']);
        $this->assertProfilingAndLoggingOff($out);

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        $selects = array_values(array_filter(
            $this->entriesWith($batch, 'qm_order'),
            static fn(array $e): bool => $e['op'] === 'select' && str_contains((string) $e['query'], 'customer'),
        ));
        self::assertCount(2, $selects, 'one entry per find()->all()');
        self::assertSame($selects[0]['query'], $selects[1]['query'], 'the same query for both values');
        foreach ($selects as $entry) {
            self::assertSame('db', $entry['conn']);
            self::assertSame('success', $entry['result']);
        }

        $json = (string) json_encode($batch);
        foreach (['qm-t1-first', 'qm-t1-second', '17.25', '42.75'] as $value) {
            self::assertStringNotContainsString($value, $json);
        }
    }

    #[DataProvider('databases')]
    public function testSecondCallInQueryCacheGivesNoEntry(string $db): void
    {
        $result = $this->scenario($db, 'query-cache');
        $out = $this->scenarioOutput($result);

        self::assertSame(['2', '2'], $out['results']);
        $this->assertProfilingAndLoggingOff($out);
        self::assertCount(1, $this->entriesWith($this->singleBatch($result), 'qm_cached'), 'the cache hit is not an entry');
        $this->assertNoErrors($result);
    }

    #[DataProvider('databases')]
    public function testWithoutQueryCacheBothCallsAreEntries(string $db): void
    {
        // control for the cache case: the same scenario without Connection::cache()
        $result = $this->scenario($db, 'query-cache', [], ['QM_T1_NO_CACHE' => '1']);

        self::assertSame(['2', '2'], $this->scenarioOutput($result)['results']);
        self::assertCount(2, $this->entriesWith($this->singleBatch($result), 'qm_cached'));
    }

    #[DataProvider('databases')]
    public function testTwoConnectionsGiveEntriesInOrderOfCompletion(string $db): void
    {
        $result = $this->scenario($db, 'two-connections');
        $this->assertProfilingAndLoggingOff($this->scenarioOutput($result));

        $sequence = array_map(
            static fn(array $e): array => [preg_match('/qm_seq_(\d)/', (string) $e['query'], $m) === 1 ? (int) $m[1] : 0, $e['conn'], $e['result']],
            $this->entriesWith($this->singleBatch($result), 'qm_seq_'),
        );

        self::assertSame([
            [1, 'db', 'success'],
            [2, 'admin/db', 'success'],
            [3, 'db', 'error'],
            [4, 'admin/db', 'success'],
            [5, 'db', 'success'],
        ], $sequence);
    }

    #[DataProvider('databases')]
    public function testNestedTransactionsGiveSavepointEntries(string $db): void
    {
        $result = $this->scenario($db, 'savepoints');
        $out = $this->scenarioOutput($result);
        self::assertSame([1, 2, 4], $out['rows'], 'kept savepoint committed, the others rolled back, the outer transaction went on');
        $this->assertProfilingAndLoggingOff($out);
        $batch = $this->singleBatch($result);

        $transactional = array_values(array_filter(
            $batch['queries'],
            static fn(array $e): bool => in_array($e['op'], ['savepoint', 'release', 'rollback', 'begin', 'start', 'commit'], true),
        ));
        self::assertSame([
            ['savepoint', 'SAVEPOINT LEVEL1'],
            ['release', 'RELEASE SAVEPOINT LEVEL1'],
            ['savepoint', 'SAVEPOINT LEVEL1'],
            ['rollback', 'ROLLBACK TO SAVEPOINT LEVEL1'],
            ['savepoint', 'SAVEPOINT LEVEL1'],
            ['rollback', 'ROLLBACK TO SAVEPOINT LEVEL1'],
        ], array_map(static fn(array $e): array => [$e['op'], $e['query']], $transactional), 'savepoints only: the outer BEGIN, COMMIT go through PDO and are not entries');

        $inserts = array_values(array_filter($batch['queries'], static fn(array $e): bool => $e['op'] === 'insert' && str_contains((string) $e['query'], 'qm_t1_item')));
        self::assertSame(['success', 'success', 'success', 'error', 'success'], array_column($inserts, 'result'));
    }

    /**
     * @param mixed $out scenario output with `flags` from scenarios/_flags.php
     */
    private function assertProfilingAndLoggingOff(mixed $out): void
    {
        self::assertIsArray($out);
        $off = ['profiling' => false, 'logging' => false];
        self::assertSame(['db' => $off, 'admin/db' => $off], $out['flags'], 'Yii profiling and logging are off in the test (plan/01 YQM-10)');
    }
}
