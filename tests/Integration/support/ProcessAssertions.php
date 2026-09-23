<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\support;

use mrstroz\querymonitoring\tests\app\ProcessResult;

/**
 * The precondition of every test running a group of processes: the process finished on its own and said
 * nothing on stderr. A test whose subject is something else asserts this first and then stops caring.
 */
trait ProcessAssertions
{
    protected function assertFinished(ProcessResult $result, int $index): void
    {
        self::assertSame($index, $result->index);
        self::assertFalse($result->timedOut, "process {$index} timed out");
        self::assertSame(0, $result->exitCode, "process {$index}\nstdout: {$result->stdout}\nstderr: {$result->stderr}");
        self::assertSame('', $result->stderr);
    }
}
