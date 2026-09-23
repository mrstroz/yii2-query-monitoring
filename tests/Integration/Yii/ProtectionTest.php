<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\FaultyQueryMonitor;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-9, spec 01 §6 and ADR-0006: a failure of the adapter or of the monitoring never reaches the
 * application, is logged once per process without query text, and queries of the adapter are not listed.
 */
final class ProtectionTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, string>, string, int}>
     */
    public static function provideFaultPerDatabaseCases(): iterable
    {
        foreach (self::provideDatabaseCases() as $name => [$db]) {
            foreach (self::faults() as $fault => [$component, $env, $pattern, $calls]) {
                yield "{$name} {$fault}" => [$db, $component, $env, $pattern, $calls];
            }
        }
    }

    /**
     * @param array<string, mixed> $component
     * @param array<string, string> $env
     */
    #[DataProvider('provideFaultPerDatabaseCases')]
    public function testFailureDoesNotChangeWhatTheApplicationGets(string $db, array $component, array $env, string $pattern, int $calls): void
    {
        $off = $this->scenario($db, 'protection', ['enabled' => false]);
        $on = $this->scenario($db, 'protection', $component, $env);

        $this->assertProcessOk($on);
        self::assertSame($off->stdout, $on->stdout, 'same response with the package failing and the package off');
        $output = $this->scenarioOutput($on);
        self::assertIsArray($output['thrown']);
        self::assertStringStartsWith('yii\db\\', $output['thrown']['class'], 'the application gets the original Yii exception');

        self::assertCount($calls, $on->adapterCalls, 'the adapter was reached as the fault allows');
        $this->assertOnePackageError($on);
        $message = $this->errors($on)[0]['message'];
        self::assertMatchesRegularExpression($pattern, $message, 'the forced fault is the one logged');
        self::assertStringNotContainsStringIgnoringCase('qm_', $message, 'no query text in the log');
        self::assertStringNotContainsStringIgnoringCase('select', $message, 'no query text in the log');
    }

    #[DataProvider('provideDatabaseCases')]
    public function testThrowingAdapterIsCalledOnce(string $db): void
    {
        $result = $this->scenario($db, 'protection', [], ['QM_ADAPTER' => 'throw']);

        self::assertCount(1, $result->adapterCalls);
        self::assertSame([], $result->batches);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testQueryInsideAdapterGivesNoEntry(string $db): void
    {
        $result = $this->scenario($db, 'protection', [], ['QM_ADAPTER' => 'query']);

        $this->assertProcessOk($result);
        self::assertCount(1, $result->adapterCalls);
        self::assertSame('4242', (string) ($result->adapterCalls[0]['query'] ?? null), 'the adapter query ran');
        $batch = $this->singleBatch($result);
        self::assertCount(1, $this->entriesWith($batch, 'qm_prot_select'));
        self::assertSame([], $this->entriesWith($batch, 'qm_in_adapter'));
        $this->assertNoErrors($result);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testLogTargetQueryGoesThroughMonitoredConnection(string $db): void
    {
        // control for the loop cases: without a fault, the log target's query is listed
        $result = $this->scenario($db, 'protection', [], ['QM_LOG_QUERY' => '1', 'QM_T1_APP_ERROR' => '1']);

        $this->assertProcessOk($result);
        self::assertNotEmpty($this->entriesWith($this->singleBatch($result), 'qm_in_log'));
        self::assertSame(['qm-t1 application error'], array_column($this->errors($result), 'message'));
    }

    public function testDisabledPackageLeavesNoTrace(): void
    {
        $result = $this->scenario(self::ANY_DB, 'protection', ['enabled' => false], ['QM_ADAPTER' => 'throw']);

        $this->assertProcessOk($result);
        self::assertSame([], $result->adapterCalls);
        $this->assertNoErrors($result);
    }

    /**
     * Fault => [component, env, pattern of the one package error, calls of the adapter].
     * Faults in the normaliser or the collector leave no entry, so the adapter is not called.
     *
     * @return array<string, array{array<string, mixed>, array<string, string>, string, int}>
     */
    private static function faults(): array
    {
        $faulty = ['class' => FaultyQueryMonitor::class];

        return [
            'adapter throws' => [[], ['QM_ADAPTER' => 'throw'], '/ failed in send with /', 1],
            'serialisation fails' => [[], ['QM_ADAPTER' => 'json-fail'], '/ failed in send with JsonException$/', 1],
            'normaliser fails' => [$faulty, ['QM_FAULT' => 'normalizer'], '/ failed in record with Error$/', 0],
            // the uninitialised collector throws first in setAction() on EVENT_BEFORE_ACTION; its later
            // calls in record and send are silenced by the guard
            'collector fails' => [$faulty, ['QM_FAULT' => 'collector'], '/ failed in action with Error$/', 0],
            'log target queries while logging the normaliser failure' => [$faulty, ['QM_FAULT' => 'normalizer', 'QM_LOG_QUERY' => '1'], '/ failed in record with Error$/', 0],
            // the adapter's failure is logged while the collector is closed and paused: the log target's query is no entry
            'log target queries while logging the adapter failure' => [[], ['QM_ADAPTER' => 'throw', 'QM_LOG_QUERY' => '1'], '/ failed in send with /', 1],
        ];
    }
}
