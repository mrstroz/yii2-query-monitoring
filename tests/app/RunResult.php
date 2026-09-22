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
     * @param list<array<string, mixed>> $adapterCalls one line per call of the capturing adapter's `send()`, in order
     * @param array<string, string> $runtimeFiles contents of the files the run left in its `@runtime`, by relative path
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly array $batches,
        public readonly array $logs,
        public readonly array $adapterCalls = [],
        public readonly array $runtimeFiles = [],
    ) {}
}
