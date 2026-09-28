<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\RunResult;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-50 and YQM-51, spec 01 §5 and ADR-0012: `beginJob()`/`endJob()` open and close a job context with its
 * own `id` and `seq`; an entry belongs only to the deepest open context; ending sends the rest.
 */
final class JobContextTest extends IntegrationTestCase
{
    #[DataProvider('provideDatabaseCases')]
    public function testTwoSequentialJobsDoNotMixTheirEntries(string $db): void
    {
        $result = $this->consoleJobs($db, [
            ['q', 'qm_root_1'],
            ['begin', 'app\jobs\A', 'default', '11', 1], ['q', 'qm_a_1'], ['end', 0],
            ['begin', 'app\jobs\B', 'default', '12', 1], ['q', 'qm_b_1'], ['end', 1],
            ['q', 'qm_root_2'],
        ]);

        self::assertSame([['job', 'app\jobs\A'], ['job', 'app\jobs\B'], ['console', null]], $this->kinds($result));
        self::assertSame([['qm_a_1'], ['qm_b_1'], ['qm_root_1', 'qm_root_2']], $this->markers($result));
        self::assertCount(3, array_unique(array_column($result->batches, 'id')), 'each context has its own id');
        self::assertSame([1, 1, 1], array_column($result->batches, 'seq'));
        $this->assertNoErrors($result);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testEndingAJobSendsItsRestAtOnce(string $db): void
    {
        $result = $this->consoleJobs($db, [['begin', 'app\jobs\Short'], ['q', 'qm_short'], ['end', 0], ['q', 'qm_idle_after']]);

        self::assertSame([0, 0, 1, 1], $this->scenarioOutput($result)['calls'], 'the job batch goes in endJob(), not when the worker is next busy');
    }

    #[DataProvider('provideDatabaseCases')]
    public function testLargeJobSendsSeveralBatchesWithOneId(string $db): void
    {
        $result = $this->consoleJobs($db, [['begin', 'app\jobs\Big'], ['qn', 'qm_big', 1200], ['end', 0]]);

        $jobs = $this->batchesOfJob($result, 'app\jobs\Big');
        self::assertSame([1, 2, 3], array_column($jobs, 'seq'));
        self::assertCount(1, array_unique(array_column($jobs, 'id')));
        self::assertSame([500, 500, 200], array_map(static fn(array $batch): int => count($batch['queries']), $jobs));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testJobHeaderCarriesMetadataAndTheRootRoute(string $db): void
    {
        $result = $this->consoleJobs($db, [['begin', 'app\jobs\Mail', 'mail', 42, 3], ['q', 'qm_mail'], ['end', 0]]);

        $jobs = $this->batchesOfJob($result, 'app\jobs\Mail');
        self::assertCount(1, $jobs);
        $batch = $jobs[0];
        self::assertSame(3, $batch['v']);
        self::assertSame('scenario/run', $batch['route'], 'route stays the controller route of the process');
        self::assertSame(['name' => 'app\jobs\Mail', 'queue' => 'mail', 'message_id' => '42', 'attempt' => 3], $batch['job']);
        self::assertSame(['v', 'app', 'type', 'id', 'seq', 'route', 'job', 'ts', 'host', 'dropped', 'queries'], array_keys($batch));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testJobThatThrowsIsSentAndItsExceptionReachesTheCaller(string $db): void
    {
        $result = $this->consoleJobs($db, [['begin', 'app\jobs\Failing'], ['throw', 0], ['q', 'qm_after_failure']]);

        self::assertSame(['qm job failed'], $this->scenarioOutput($result)['caught'], 'the exception is unchanged');
        self::assertSame([['qm_job_before_throw'], ['qm_after_failure']], $this->markers($result));
        self::assertSame([['job', 'app\jobs\Failing'], ['console', null]], $this->kinds($result));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testRetryIsANewContext(string $db): void
    {
        $result = $this->consoleJobs($db, [
            ['begin', 'app\jobs\Retry', 'default', 'm-7', 1], ['q', 'qm_try_1'], ['end', 0],
            ['begin', 'app\jobs\Retry', 'default', 'm-7', 2], ['q', 'qm_try_2'], ['end', 1],
        ]);

        $jobs = $this->batchesOfJob($result, 'app\jobs\Retry');
        self::assertCount(2, $jobs);
        self::assertNotSame($jobs[0]['id'], $jobs[1]['id'], 'id identifies the attempt, not the message');
        self::assertSame([1, 1], array_column($jobs, 'seq'));
        self::assertSame([1, 2], array_column(array_column($jobs, 'job'), 'attempt'));
        self::assertSame(['m-7', 'm-7'], array_column(array_column($jobs, 'job'), 'message_id'));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testSecondAndLateEndDoNothing(string $db): void
    {
        $result = $this->consoleJobs($db, [
            ['begin', 'app\jobs\A'], ['q', 'qm_a'], ['end', 0], ['end', 0],
            ['begin', 'app\jobs\B'], ['q', 'qm_b_1'], ['end', 0], ['q', 'qm_b_2'], ['end', 1],
        ]);

        self::assertSame([['qm_a'], ['qm_b_1', 'qm_b_2']], $this->markers($result), 'a late end of A does not close B');
        $this->assertNoErrors($result);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testExitInsideAJobSendsItOnceInShutdown(string $db): void
    {
        $result = $this->consoleJobs($db, [['q', 'qm_root'], ['begin', 'app\jobs\Exiting'], ['q', 'qm_exiting'], ['exit']]);

        $this->assertProcessOk($result);
        self::assertSame([['job', 'app\jobs\Exiting'], ['console', null]], $this->kinds($result), 'deepest first, each once');
        self::assertSame([['qm_exiting'], ['qm_root']], $this->markers($result));
        self::assertCount(2, $result->adapterCalls);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function provideInertHandleCases(): iterable
    {
        yield 'disabled' => [['enabled' => false], 0];
        yield 'wrong configuration' => [['maxEntries' => 0], 1];
    }

    /**
     * @param array<string, mixed> $component
     */
    #[DataProvider('provideInertHandleCases')]
    public function testInertHandleWhenThePackageIsOff(array $component, int $errors): void
    {
        $result = $this->consoleJobs(self::ANY_DB, [['begin', 'app\jobs\A'], ['q', 'qm_a'], ['end', 0], ['end', 0]], $component);

        self::assertSame([], $result->batches);
        self::assertCount($errors, $this->errors($result));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testNewBatchAndNewJobKeepSqlAndMongoDbInTheRightContext(string $db): void
    {
        $this->requireDatabase('mongodb');

        $result = $this->consoleJobs($db, [
            ['begin', 'app\jobs\A'], ['q', 'qm_a_1'], ['mongo', 'qm_a_m1'], ['q', 'qm_a_2'], ['mongo', 'qm_a_m2'], ['end', 0],
            ['begin', 'app\jobs\B'], ['mongo', 'qm_b_m1'], ['q', 'qm_b_1'], ['end', 1],
        ], ['connections' => ['db', 'mongodb'], 'maxEntries' => 3]);

        self::assertSame(
            [['app\jobs\A', 1, [$db, 'mongodb', $db]], ['app\jobs\A', 2, ['mongodb']], ['app\jobs\B', 1, ['mongodb', $db]]],
            array_map(static fn(array $b): array => [$b['job']['name'] ?? null, $b['seq'], array_column($b['queries'], 'db')], $result->batches),
            'each entry in the batch open when it ended, whichever source recorded it',
        );
    }

    #[DataProvider('provideDatabaseCases')]
    public function testNestedJobInHttpReturnsToTheRequest(string $db): void
    {
        $result = $this->scenario($db, 'jobs', [], ['QM_OPS' => (string) json_encode([
            ['q', 'qm_h_1'],
            ['begin', 'app\jobs\Outer'], ['q', 'qm_o_1'],
            ['begin', 'app\jobs\Inner'], ['q', 'qm_i_1'], ['end', 1],
            ['q', 'qm_o_2'], ['end', 0],
            ['q', 'qm_h_2'],
        ])]);
        $this->assertProcessOk($result);

        self::assertSame([['job', 'app\jobs\Inner'], ['job', 'app\jobs\Outer'], ['http', null]], $this->kinds($result));
        self::assertSame([['qm_i_1'], ['qm_o_1', 'qm_o_2'], ['qm_h_1', 'qm_h_2']], $this->markers($result));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testEndingTheParentFirstEndsTheChildBeforeIt(string $db): void
    {
        $result = $this->consoleJobs($db, [
            ['begin', 'app\jobs\Outer'], ['q', 'qm_o'], ['begin', 'app\jobs\Inner'], ['q', 'qm_i'], ['end', 0],
            ['q', 'qm_root'], ['end', 1],
        ]);

        self::assertSame([['job', 'app\jobs\Inner'], ['job', 'app\jobs\Outer'], ['console', null]], $this->kinds($result));
        self::assertSame([['qm_i'], ['qm_o'], ['qm_root']], $this->markers($result));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testSixteenthNestedJobIsInertAndItsEntriesGoToTheDeepest(string $db): void
    {
        $ops = [];
        for ($i = 0; $i < 16; $i++) {
            $ops[] = ['begin', "app\\jobs\\Level{$i}"];
        }
        $ops[] = ['q', 'qm_deep'];
        for ($i = 15; $i >= 0; $i--) {
            $ops[] = ['end', $i];
        }

        $result = $this->consoleJobs($db, $ops);

        self::assertCount(1, $result->batches, 'one batch: the 15th job; the 16th never opened');
        self::assertSame('app\jobs\Level14', $result->batches[0]['job']['name'] ?? null);
        self::assertSame([['qm_deep']], $this->markers($result));
        $this->assertOnePackageError($result);
        $message = $this->errors($result)[0]['message'];
        self::assertStringContainsString('16', $message, 'the error names the limit');
        self::assertStringNotContainsString('Level15', $message, 'the error does not name the job');
    }

    /**
     * @param list<list<mixed>> $ops
     * @param array<string, mixed> $component
     */
    private function consoleJobs(string $db, array $ops, array $component = []): RunResult
    {
        $result = $this->consoleScenario($db, 'jobs', $component, ['QM_OPS' => (string) json_encode($ops)]);
        $this->assertProcessOk($result);

        return $result;
    }

    /**
     * `[type, job name]` of each batch, in order of sending.
     *
     * @return list<array{mixed, mixed}>
     */
    private function kinds(RunResult $result): array
    {
        return array_map(static fn(array $batch): array => [$batch['type'], $batch['job']['name'] ?? null], $result->batches);
    }

    /**
     * The `qm_…` aliases of the SQL entries of each batch, in order.
     *
     * @return list<list<string>>
     */
    private function markers(RunResult $result): array
    {
        return array_map(static function (array $batch): array {
            $markers = [];
            foreach ($batch['queries'] as $entry) {
                if (is_string($entry['query']) && preg_match('/AS (qm_\w+)$/', $entry['query'], $match) === 1) {
                    $markers[] = $match[1];
                }
            }

            return $markers;
        }, $result->batches);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function batchesOfJob(RunResult $result, string $name): array
    {
        return array_values(array_filter($result->batches, static fn(array $batch): bool => ($batch['job']['name'] ?? null) === $name));
    }
}
