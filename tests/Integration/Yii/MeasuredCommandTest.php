<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-5: spec 01 §2 (SQL source), ADR-0001. Behaviour is compared with the same scenario run
 * without the package; timing uses the TestPdo switches.
 */
final class MeasuredCommandTest extends IntegrationTestCase
{
    private const SLOW_MS = 300;

    private const DUPLICATE = ['mysql' => '23000', 'pgsql' => '23505'];

    #[DataProvider('databases')]
    public function testBindingWorksAsInYiiCommand(string $db): void
    {
        $without = $this->scenarioOutput($this->scenario($db, 'binding', ['enabled' => false]));
        $result = $this->scenario($db, 'binding');

        self::assertSame($without, $this->scenarioOutput($result));
        $this->assertNoErrors($result);

        $inserts = array_values(array_filter(
            $this->singleBatch($result)['queries'],
            static fn(array $e): bool => $e['op'] === 'insert' && $e['conn'] === 'db',
        ));
        self::assertCount(4, $inserts, 'bindValue, two executions with bindParam, builder insert');
        foreach ($this->singleBatch($result)['queries'] as $entry) {
            self::assertStringNotContainsString("O'Brien", (string) $entry['query']);
            self::assertStringNotContainsString('second', (string) $entry['query']);
        }
    }

    #[DataProvider('databases')]
    public function testExecuteErrorGivesErrorEntryAndUnchangedException(string $db): void
    {
        $without = $this->scenarioOutput($this->scenario($db, 'execute-error', ['enabled' => false]));
        $result = $this->scenario($db, 'execute-error');
        $with = $this->scenarioOutput($result);

        self::assertNotNull($with['thrown']);
        self::assertSame($without, $with, 'the application sees the same exception');
        $this->assertNoErrors($result);

        $entries = $this->entriesWith($this->singleBatch($result), 'INSERT INTO qm_t1_item');
        self::assertCount(1, $entries);
        self::assertSame('error', $entries[0]['result']);
        self::assertSame(self::DUPLICATE[$db], $entries[0]['error']);
        self::assertSame('INSERT INTO qm_t1_item (id, name, flag) VALUES (:id, :name, ?)', $entries[0]['query']);
    }

    #[DataProvider('databases')]
    public function testPrepareErrorGivesErrorEntryAndUnchangedException(string $db): void
    {
        $env = ['QM_FAIL_PREPARE' => '42000'];
        $without = $this->scenarioOutput($this->scenario($db, 'prepare-error', ['enabled' => false], $env));
        $result = $this->scenario($db, 'prepare-error', [], $env);
        $with = $this->scenarioOutput($result);

        self::assertNotNull($with['thrown']);
        self::assertSame($without, $with);

        $entries = $this->entriesWith($this->singleBatch($result), 'qm_prep_fail');
        self::assertCount(1, $entries);
        self::assertSame('error', $entries[0]['result']);
        self::assertSame('42000', $entries[0]['error']);
    }

    #[DataProvider('databases')]
    public function testEachRetryAttemptIsOneEntry(string $db): void
    {
        $without = $this->scenarioOutput($this->scenario($db, 'retry', ['enabled' => false]));
        $result = $this->scenario($db, 'retry');
        $with = $this->scenarioOutput($result);

        self::assertSame(['affected' => 1, 'attempts' => [1], 'name' => 'new'], $with);
        self::assertSame($without, $with);

        $entries = $this->entriesWith($this->singleBatch($result), 'INSERT INTO qm_t1_item');
        self::assertCount(2, $entries);
        self::assertSame(['error', 'success'], array_column($entries, 'result'));
        self::assertSame(self::DUPLICATE[$db], $entries[0]['error']);
    }

    #[DataProvider('databases')]
    public function testRequiredTransactionSendsTheStatementOnce(string $db): void
    {
        $without = $this->scenarioOutput($this->scenario($db, 'isolation', ['enabled' => false]));
        $result = $this->scenario($db, 'isolation');
        $with = $this->scenarioOutput($result);

        self::assertSame(['affected' => 1, 'name' => 'isolated'], $with);
        self::assertSame($without, $with);
        $this->assertNoErrors($result);

        $entries = $this->entriesWith($this->singleBatch($result), 'INSERT INTO qm_t1_item');
        self::assertCount(1, $entries, 'the recursion inside db->transaction() gives one entry');
        self::assertSame('success', $entries[0]['result']);
    }

    #[DataProvider('databases')]
    public function testConnectionOpenIsNotTimed(string $db): void
    {
        $batch = $this->singleBatch($this->scenario($db, 'timing', [], ['QM_SLOW_CONNECT_MS' => (string) self::SLOW_MS]));

        $entry = $this->only($batch, 'qm_time_open');
        self::assertLessThan(self::SLOW_MS, $entry['time_ms']);
    }

    #[DataProvider('databases')]
    public function testSecondExecutionDoesNotCountPrepareAgain(string $db): void
    {
        $batch = $this->singleBatch($this->scenario($db, 'timing', [], ['QM_SLOW_PREPARE_MS' => (string) self::SLOW_MS]));

        $twice = $this->entriesWith($batch, 'qm_time_twice');
        self::assertCount(2, $twice);
        self::assertGreaterThanOrEqual(self::SLOW_MS, $twice[0]['time_ms'], 'first execution includes PDO::prepare()');
        self::assertLessThan(self::SLOW_MS, $twice[1]['time_ms'], 'second execution reuses the statement');
    }

    #[DataProvider('databases')]
    public function testFetchingRowsIsNotTimed(string $db): void
    {
        $batch = $this->singleBatch($this->scenario($db, 'timing', [], ['QM_SLOW_FETCH_MS' => (string) self::SLOW_MS]));

        $entry = $this->only($batch, 'qm_time_fetch');
        self::assertLessThan(self::SLOW_MS, $entry['time_ms']);
    }

    #[DataProvider('databases')]
    public function testTimeIsAFloatInMilliseconds(string $db): void
    {
        $batch = $this->singleBatch($this->scenario($db, 'timing'));

        foreach ($batch['queries'] as $entry) {
            self::assertIsFloat($entry['time_ms']);
            self::assertGreaterThanOrEqual(0.0, $entry['time_ms']);
            self::assertSame(round($entry['time_ms'], 3), $entry['time_ms'], 'rounded to three decimals (01 §2)');
            self::assertLessThan(self::SLOW_MS, $entry['time_ms']);
        }
    }

    /**
     * @param array<string, mixed> $batch
     *
     * @return array<string, mixed>
     */
    private function only(array $batch, string $marker): array
    {
        $entries = $this->entriesWith($batch, $marker);
        self::assertCount(1, $entries, "one entry with {$marker}");

        return $entries[0];
    }
}
