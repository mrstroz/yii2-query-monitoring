<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\context;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\collector\QueryCollector;

/**
 * What every context of the process shares: the buffer factory, the clock, the send interval, the exclusions, the
 * sampling of finished batches and the source of their `user`.
 *
 * @internal
 */
final class ContextSettings
{
    /**
     * @param \Closure(BatchType, string, ?JobInfo): QueryCollector $createCollector buffer of one batch
     * @param \Closure(): float $clock monotonic seconds
     * @param BatchSampling|null $sampling null sends every batch (spec 01 §5.6)
     * @param UserSource|null $user null leaves `user` null in every batch (spec 01 §5.7)
     */
    public function __construct(
        public readonly \Closure $createCollector,
        public readonly \Closure $clock,
        public readonly int $flushIntervalSeconds,
        public readonly RouteExclusions $exclusions,
        public readonly ?BatchSampling $sampling = null,
        public readonly ?UserSource $user = null,
    ) {}
}
