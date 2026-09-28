<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\console\jobs\QueryJob;
use mrstroz\querymonitoring\tests\app\RunResult;
use mrstroz\querymonitoring\tests\Integration\support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-53, spec 01 §5.3: `queue\JobMonitorBehavior` on a real yii2-queue file queue, run by `queue/run` in the
 * process that executes the job — the worker itself, or its `queue/exec` child with `--isolate=1`. The worker's
 * own route is excluded (`queue/*`), so every batch is a job batch.
 */
final class QueueBehaviorTest extends IntegrationTestCase
{
    use TemporaryDirectory;

    private const JOB = QueryJob::class;

    protected function setUp(): void
    {
        $this->dir = self::temporaryPath('queue');
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideIsolateCases(): iterable
    {
        yield 'in the worker' => ['0'];
        yield 'in a child process' => ['1'];
    }

    #[DataProvider('provideIsolateCases')]
    public function testSuccessfulJobGivesOneJobBatch(string $isolate): void
    {
        $ids = $this->push([['ok', 'done']]);

        $result = $this->work($isolate);

        self::assertCount(1, $result->batches);
        $batch = $result->batches[0];
        self::assertSame('job', $batch['type']);
        self::assertSame(['name' => self::JOB, 'queue' => 'queue', 'message_id' => (string) $ids[0], 'attempt' => 1], $batch['job']);
        self::assertSame($isolate === '1' ? 'queue/exec' : 'queue/run', $batch['route']);
        self::assertSame(['qm_done'], $this->markers($batch));
        $this->assertNoErrors($result);
    }

    #[DataProvider('provideIsolateCases')]
    public function testFailedJobAndItsRetryAreTwoAttempts(string $isolate): void
    {
        $this->push([['fail-once', 'flaky']]);

        $first = $this->work($isolate);
        // The file queue hands a failed message out again after its ttr (1 s) has passed.
        usleep(2_100_000);
        $second = $this->work($isolate);

        self::assertCount(1, $first->batches, 'the failed attempt is sent too');
        self::assertCount(1, $second->batches);
        [$a, $b] = [$first->batches[0], $second->batches[0]];
        self::assertNotSame($a['id'], $b['id'], 'the retry is a new context');
        self::assertSame([1, 1], [$a['seq'], $b['seq']]);
        self::assertSame([1, 2], [$a['job']['attempt'], $b['job']['attempt']]);
        self::assertSame($a['job']['message_id'], $b['job']['message_id']);
        self::assertSame([['qm_flaky'], ['qm_flaky']], [$this->markers($a), $this->markers($b)]);
        self::assertStringNotContainsString('failed on purpose', (string) json_encode($a), 'no exception in the batch');
        $this->assertNoErrors($first);
        $this->assertNoErrors($second);
    }

    #[DataProvider('provideIsolateCases')]
    public function testHandledJobIsSentBeforeTheNextJob(string $isolate): void
    {
        $this->push([['handled', 'taken'], ['ok', 'next']]);

        $result = $this->work($isolate);

        self::assertSame([['qm_taken'], ['qm_next']], array_map(fn(array $b): array => $this->markers($b), $result->batches), 'the handled job ended before the next one began; no entry of one in the other');
        self::assertCount(2, $result->adapterCalls, 'each job sent once');
        self::assertNotSame($result->batches[0]['id'], $result->batches[1]['id']);
        $this->assertNoErrors($result);
    }

    public function testHandledJobIsSentWhileTheWorkerStillRuns(): void
    {
        $this->push([['handled', 'taken'], ['ok', 'slow']]);

        $result = $this->work('0', ['QM_JOB_SEES_CALLS' => '1']);

        self::assertSame(['qm_taken'], $this->markers($result->batches[0] ?? []));
        self::assertStringContainsString('calls-before-slow=1', $result->stderr, 'the handled job was already sent when the next job ran');
    }

    #[DataProvider('provideIsolateCases')]
    public function testJobThatExitsIsSentOnceByItsOwnProcess(string $isolate): void
    {
        $this->push([['exit', 'dying']]);

        $result = $this->work($isolate, [], expectOk: false);

        self::assertCount(1, $result->batches, 'the job batch goes in shutdown of the process that ran it, and nothing from the worker');
        self::assertSame(['qm_dying'], $this->markers($result->batches[0]));
        self::assertSame(self::JOB, $result->batches[0]['job']['name']);
        $this->assertNoErrors($result);
    }

    /**
     * @param list<array{string, string}> $jobs `[mode, marker]`
     *
     * @return list<int|string> ids of the pushed messages
     */
    private function push(array $jobs): array
    {
        $result = $this->consoleScenario(self::ANY_DB, 'queue-push', ['enabled' => false], ['QM_QUEUE_PATH' => $this->dir, 'QM_JOBS' => (string) json_encode($jobs)]);

        $ids = $this->scenarioOutput($result)['ids'];
        self::assertCount(count($jobs), $ids);

        return $ids;
    }

    /**
     * @param array<string, string> $env
     */
    private function work(string $isolate, array $env = [], bool $expectOk = true): RunResult
    {
        $result = $this->command(self::ANY_DB, 'queue/run', ["--isolate={$isolate}"], ['excludedRoutes' => ['console' => ['queue/*']]], ['QM_QUEUE_PATH' => $this->dir] + $env);
        if ($expectOk) {
            $this->assertProcessOk($result);
        }

        return $result;
    }

    /**
     * The `qm_…` aliases of the SQL entries of a batch, in order.
     *
     * @param array<string, mixed> $batch
     *
     * @return list<string>
     */
    private function markers(array $batch): array
    {
        $markers = [];
        foreach ($batch['queries'] ?? [] as $entry) {
            if (is_string($entry['query']) && preg_match('/AS (qm_\w+)$/', $entry['query'], $match) === 1) {
                $markers[] = $match[1];
            }
        }

        return $markers;
    }
}
