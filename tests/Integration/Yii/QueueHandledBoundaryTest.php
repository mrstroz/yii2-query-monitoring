<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\RunResult;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * spec 01 §5.3: a job another handler marked `handled` gets no end event, and a queue without a worker loop
 * (`yii\queue\sync\Queue::run()`) gives no later chance to end it. The job still ends at the boundary of its
 * execution: what the caller runs after `run()` — the next job, the caller's own queries — does not belong to it.
 */
final class QueueHandledBoundaryTest extends IntegrationTestCase
{
    public function testQueriesAfterSyncRunBelongToTheCaller(): void
    {
        $result = $this->sync([['handled', 'taken']]);

        self::assertSame([['job', ['qm_taken']], ['console', ['qm_root_before', 'qm_root_after']]], $this->summary($result));
    }

    public function testNextJobOfTheSameRunIsNotNestedInTheHandledOne(): void
    {
        $result = $this->sync([['handled', 'taken'], ['ok', 'next']]);

        self::assertSame(
            [['job', ['qm_taken']], ['job', ['qm_next']], ['console', ['qm_root_before', 'qm_root_after']]],
            $this->summary($result),
            'the handled job was sent before the next one ran',
        );
    }

    public function testManyHandledJobsNeverNestAndNeverReachTheDepthLimit(): void
    {
        $jobs = array_map(static fn(int $i): array => ['handled', "h{$i}"], range(1, 50));

        $result = $this->sync($jobs);

        $summary = $this->summary($result);
        self::assertCount(51, $summary);
        self::assertSame(array_map(static fn(int $i): array => ['job', ["qm_h{$i}"]], range(1, 50)), array_slice($summary, 0, 50), 'each handled job in its own batch, in order');
        self::assertSame(['console', ['qm_root_before', 'qm_root_after']], $summary[50]);
    }

    /**
     * yii2-queue's db driver deletes the message after `handleMessage()` returns, in the worker, whose route is
     * excluded. That query belongs to the worker, not to the handled job, and the handled job goes before the next.
     */
    #[DataProvider('provideDatabaseCases')]
    public function testQueueQueriesAfterAHandledJobDoNotBelongToIt(string $db): void
    {
        $env = ['QM_QUEUE_DRIVER' => 'db', 'QM_QUEUE_CHANNEL' => 'handled-' . bin2hex(random_bytes(4))];
        $push = $this->consoleScenario($db, 'queue-push', ['enabled' => false], $env + ['QM_JOBS' => '[["handled","taken"],["ok","next"]]']);
        $this->assertProcessOk($push);

        $result = $this->command($db, 'queue/run', ['--isolate=0'], ['excludedRoutes' => ['console' => ['queue/*']]], $env);

        $this->assertProcessOk($result);
        $this->assertNoErrors($result);
        self::assertSame([['job', ['qm_taken']], ['job', ['qm_next']]], $this->summary($result));
        foreach ($result->batches as $batch) {
            self::assertSame([], $this->entriesWith($batch, 'qm_queue'), "no query of the queue table in the batch of {$batch['job']['name']}");
        }
    }

    /**
     * @param list<array{string, string}> $jobs
     */
    private function sync(array $jobs): RunResult
    {
        $result = $this->consoleScenario(self::ANY_DB, 'queue-sync', [], ['QM_JOBS' => (string) json_encode($jobs)]);
        $this->assertProcessOk($result);
        $this->assertNoErrors($result);

        return $result;
    }

    /**
     * `[type, qm_… markers]` of each batch, in order of sending.
     *
     * @return list<array{string, list<string>}>
     */
    private function summary(RunResult $result): array
    {
        return array_map(static function (array $batch): array {
            $markers = [];
            foreach ($batch['queries'] as $entry) {
                if (is_string($entry['query']) && preg_match('/AS (qm_\w+)$/', $entry['query'], $match) === 1) {
                    $markers[] = $match[1];
                }
            }

            return [$batch['type'], $markers];
        }, $result->batches);
    }
}
