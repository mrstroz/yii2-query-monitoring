<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

/**
 * What one process of a {@see ProcessGroup} left behind.
 */
final class ProcessResult
{
    /**
     * @param int $index the child's `QM_WORKER`
     * @param int $exitCode -1 when the process was killed on the deadline
     * @param string $stdout without the ready line
     */
    public function __construct(
        public readonly int $index,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
    ) {}
}
