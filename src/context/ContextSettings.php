<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\context;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\collector\QueryCollector;

/**
 * What every context of the process shares: the buffer factory, the clock and the send interval.
 *
 * @internal
 */
final class ContextSettings
{
    /**
     * @param \Closure(BatchType, string, ?JobInfo): QueryCollector $createCollector buffer of one batch
     * @param \Closure(): float $clock monotonic seconds
     */
    public function __construct(
        public readonly \Closure $createCollector,
        public readonly \Closure $clock,
        public readonly int $flushIntervalSeconds,
        public readonly RouteExclusions $exclusions,
    ) {}
}
