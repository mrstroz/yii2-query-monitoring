<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-58, spec 01 §1 and §5.6, spec 02 §1: the `sampling` setting of the component decides before the adapter.
 * The draw is not injectable here, so only rates whose outcome does not depend on it are used: `0` sends only
 * batches kept by a criterion, `1` sends every batch. A wrong setting disables the package in bootstrap, before any
 * connection is measured, so that case runs on one database.
 */
final class SamplingComponentTest extends IntegrationTestCase
{
    #[DataProvider('provideDatabaseCases')]
    public function testWithoutSamplingTheBatchHasNoSample(string $db): void
    {
        $result = $this->scenario($db, 'per-connection');

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertSame(4, $batch['v']);
        self::assertArrayHasKey('sample', $batch);
        self::assertNull($batch['sample']);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testRateOneSendsTheBatchWithRateOneAndReasonSample(string $db): void
    {
        $plain = $this->singleBatch($this->scenario($db, 'per-connection'));
        $result = $this->scenario($db, 'per-connection', ['sampling' => ['rate' => 1]]);

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertSame(4, $batch['v']);
        self::assertEquals(['rate' => 1.0, 'reason' => 'sample'], $batch['sample']);
        self::assertSame($this->shape($plain), $this->shape($batch), 'the same entries in the same order');
    }

    #[DataProvider('provideDatabaseCases')]
    public function testBatchWithAnErrorIsKeptAtRateZero(string $db): void
    {
        $result = $this->scenario($db, 'execute-error', ['sampling' => ['rate' => 0, 'keepErrors' => true]]);

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertEquals(['rate' => 1.0, 'reason' => 'error'], $batch['sample']);
        $entries = $this->entriesWith($batch, 'INSERT INTO qm_t1_item');
        self::assertCount(1, $entries);
        self::assertSame('error', $entries[0]['result']);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testOrdinaryBatchAtRateZeroNeverReachesTheAdapter(string $db): void
    {
        $without = $this->scenarioOutput($this->scenario($db, 'per-connection', ['enabled' => false]));
        $result = $this->scenario($db, 'per-connection', ['sampling' => ['rate' => 0, 'keepErrors' => true]]);

        self::assertSame($without, $this->scenarioOutput($result), 'the application answers as without the package');
        self::assertSame([], $result->adapterCalls, 'the adapter is not called');
        self::assertSame([], $result->batches);
        $this->assertNoErrors($result);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideWrongSamplingCases(): iterable
    {
        yield 'false' => [false];
        yield 'a rate given alone' => [0.1];
        yield 'a string' => ['x'];
        yield 'rate above one' => [['rate' => 2]];
        yield 'rate as text' => [['rate' => '0.1']];
        yield 'no rate' => [['keepErrors' => true]];
        yield 'rate zero without a criterion' => [['rate' => 0]];
        yield 'unknown key' => [['rate' => 0.1, 'slowQueriesMs' => 100]];
    }

    #[DataProvider('provideWrongSamplingCases')]
    public function testWrongSamplingDisablesThePackage(mixed $sampling): void
    {
        $without = $this->scenarioOutput($this->scenario(self::ANY_DB, 'per-connection', ['enabled' => false]));
        $result = $this->scenario(self::ANY_DB, 'per-connection', ['sampling' => $sampling]);

        self::assertSame($without, $this->scenarioOutput($result));
        self::assertSame([], $result->batches);
        $this->assertOnePackageError($result);
    }

    /**
     * Entries without the values that differ between two runs.
     *
     * @param array<string, mixed> $batch
     *
     * @return array<mixed>
     */
    private function shape(array $batch): array
    {
        self::assertIsArray($batch['queries']);

        return array_map(static function (array $entry): array {
            unset($entry['time_ms']);

            return $entry;
        }, $batch['queries']);
    }
}
