<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration;

use mrstroz\querymonitoring\tests\app\ProcessGroup;
use mrstroz\querymonitoring\tests\app\ProcessResult;
use PHPUnit\Framework\TestCase;

/**
 * YQM-16: the runner of parallel PHP processes behind a common barrier. Needs no database.
 */
final class ProcessGroupTest extends TestCase
{
    private const PROBE = __DIR__ . '/workers/process-probe.php';

    public function testEightProcessesWorkAtTheSameTime(): void
    {
        $results = ProcessGroup::run(self::PROBE, 8, 20, ['QM_PROBE' => 'interval']);

        self::assertCount(8, $results);
        $lastStart = -INF;
        $firstEnd = INF;
        foreach ($results as $i => $result) {
            $this->assertFinished($result, $i);
            $interval = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($interval);
            $lastStart = max($lastStart, (float) $interval['start']);
            $firstEnd = min($firstEnd, (float) $interval['end']);
        }
        // Every interval contains the moment the last process started: all eight worked at once.
        self::assertLessThan($firstEnd, $lastStart);
    }

    public function testOutputErrorsAndExitCodeOfEveryProcessAreCollected(): void
    {
        $results = ProcessGroup::run(self::PROBE, 3, 20, ['QM_PROBE' => 'exit']);

        foreach ($results as $i => $result) {
            self::assertSame($i, $result->index);
            self::assertSame(3, $result->exitCode);
            self::assertFalse($result->timedOut);
            self::assertSame("out {$i}", $result->stdout);
            self::assertSame("err {$i}", $result->stderr);
        }
    }

    public function testDeadlineKillsProcessesThatAreStillWorking(): void
    {
        $marker = sys_get_temp_dir() . '/qm-group-' . bin2hex(random_bytes(6));

        $started = microtime(true);
        $results = ProcessGroup::run(self::PROBE, 3, 0.5, ['QM_PROBE' => 'sleep', 'QM_SLEEP' => '1.5', 'QM_MARKER' => $marker]);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(1.5, $elapsed);
        foreach ($results as $result) {
            self::assertTrue($result->timedOut);
            self::assertSame(-1, $result->exitCode);
        }
        // A process that survived the kill would create its marker after 1.5 s of sleep.
        usleep(1_500_000);
        self::assertSame([], glob($marker . '.*'));
    }

    public function testDeadlineKillsEveryProcessWhenOneNeverReportsReady(): void
    {
        $started = microtime(true);
        $results = ProcessGroup::run(self::PROBE, 3, 0.5, ['QM_PROBE' => 'not-ready', 'QM_NOT_READY' => '1', 'QM_SLEEP' => '10']);

        self::assertLessThan(1.5, microtime(true) - $started);
        foreach ($results as $result) {
            self::assertTrue($result->timedOut, "process {$result->index}");
            self::assertSame('', $result->stdout, 'nobody passed the barrier');
        }
    }

    public function testProcessThatExitsBeforeReadyDoesNotHoldTheOthersAtTheBarrier(): void
    {
        $started = microtime(true);
        $results = ProcessGroup::run(self::PROBE, 3, 10, ['QM_PROBE' => 'crash', 'QM_CRASH' => '0']);

        self::assertLessThan(5, microtime(true) - $started, 'the barrier opened without waiting for the deadline');
        self::assertSame(5, $results[0]->exitCode);
        self::assertSame('crashed', $results[0]->stderr);
        self::assertFalse($results[0]->timedOut);
        foreach ([1, 2] as $i) {
            $this->assertFinished($results[$i], $i);
            self::assertSame('passed', $results[$i]->stdout);
        }
    }

    private function assertFinished(ProcessResult $result, int $index): void
    {
        self::assertSame($index, $result->index);
        self::assertFalse($result->timedOut, "process {$index} timed out");
        self::assertSame(0, $result->exitCode, "process {$index}\nstdout: {$result->stdout}\nstderr: {$result->stderr}");
        self::assertSame('', $result->stderr);
    }
}
