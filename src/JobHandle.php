<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring;

use mrstroz\querymonitoring\context\Context;
use mrstroz\querymonitoring\context\ContextStack;

/**
 * The handle of one job context, returned by {@see QueryMonitor::beginJob()} and passed to {@see QueryMonitor::endJob()}
 * (spec 01 §5.3).
 *
 * Opaque to the application. An inert handle, returned when there is nothing to open, ends nothing.
 */
final class JobHandle
{
    /**
     * @internal created by the package only
     */
    public function __construct(
        private readonly ?ContextStack $stack = null,
        private readonly ?Context $context = null,
    ) {}

    /** False for a handle that opened no context. */
    public function isActive(): bool
    {
        return $this->context !== null && !$this->context->isEnded();
    }

    /**
     * @internal
     */
    public function belongsTo(ContextStack $stack): bool
    {
        return $this->stack === $stack;
    }

    /**
     * @internal
     */
    public function context(): ?Context
    {
        return $this->context;
    }
}
