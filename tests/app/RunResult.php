<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

/**
 * What one run of the test application left behind.
 */
final class RunResult
{
    /**
     * @param list<array<string, mixed>> $batches batches received by the capturing adapter, decoded, in order
     * @param list<array{level: string, category: string, message: string}> $logs Yii log messages of level error and warning
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly array $batches,
        public readonly array $logs,
    ) {}
}
