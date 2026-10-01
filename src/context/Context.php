<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\context;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;

/**
 * One context: an HTTP request, a console run or one job attempt, with its own `id` and `seq` (spec 01 §5.1).
 *
 * The context owns the buffer of its current batch, a {@see QueryCollector}. A splitting context (console and job)
 * builds a batch whenever a limit of spec 01 §5.2 is reached and hands it to `$emit` at once, before it creates the
 * next buffer, so a failure in {@see \mrstroz\querymonitoring\QueryMonitor::createCollector()} never loses a batch already built; the next
 * entry tries again. Before its route is known a root context does not split: a limit stops intake as in HTTP
 * (spec 01 §5.4). An excluded context takes nothing and sends nothing; the buffer it held before its route was
 * known is dropped uncounted. The context never calls the adapter itself. Once ended it keeps no buffer.
 *
 * @internal used by {@see ContextStack}; not an extension point
 */
final class Context
{
    private ?QueryCollector $collector = null;
    private int $seq = 1;
    private bool $ended = false;
    private float $lastSend;

    /**
     * @param bool $split whether limits send a batch (console, job) instead of truncating (http)
     * @param bool $routed whether the route is decided; a root context gets it from the entry action
     * @param bool $excluded whether the context is excluded from the start (a job, by its name)
     * @param \WeakReference<object>|null $scope a job whose scope object is released is over (spec 01 §5.3)
     */
    public function __construct(
        public readonly BatchType $type,
        public readonly string $id,
        public readonly ?JobInfo $job,
        private readonly ContextSettings $settings,
        private readonly bool $split,
        private bool $routed,
        private ?string $route = null,
        private bool $excluded = false,
        private readonly ?\WeakReference $scope = null,
    ) {
        $this->lastSend = ($this->settings->clock)();
        if (!$this->excluded) {
            $this->collector = $this->newCollector();
        }
    }

    public function isAccepting(): bool
    {
        return !$this->ended && !$this->excluded;
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    /** Whether the context has a scope object and that object was released. */
    public function isReleased(): bool
    {
        return $this->scope !== null && $this->scope->get() === null;
    }

    /**
     * Sets `route` of the current and every later batch (spec 02 §1). In a splitting context a header grown past
     * `maxBatchBytes` moves entries to the next batch instead of dropping them.
     *
     * @param \Closure(QueryBatch): void $emit
     */
    public function setRoute(?string $route, \Closure $emit): void
    {
        $this->route = $route;
        $this->collector?->setRoute($route);
        if ($this->isAccepting() && $this->splits()) {
            $this->rebalance($emit);
        }
    }

    /**
     * Marks the route as decided. An excluded context drops its buffer, `dropped` included, without sending
     * (spec 01 §5.4); a splitting context whose buffer reached a limit before sends it now.
     *
     * @param \Closure(QueryBatch): void $emit
     */
    public function markRouted(bool $excluded, \Closure $emit): void
    {
        if ($this->routed) {
            return;
        }
        $this->routed = true;
        if ($excluded) {
            $this->excluded = true;
            $this->collector = null;

            return;
        }
        if ($this->split) {
            $this->rebalance($emit);
            if ($this->collector !== null && $this->collector->isFull()) {
                $emit($this->closeBatch());
            }
        }
    }

    /**
     * Sends the batch when `flushIntervalSeconds` passed and it is not empty; {@see ContextStack::beginJob()} calls
     * this for the context that becomes a parent (spec 01 §5.2).
     *
     * @param \Closure(QueryBatch): void $emit
     */
    public function flushIfDue(\Closure $emit): void
    {
        if ($this->isAccepting() && $this->splits() && $this->isDue()) {
            $emit($this->closeBatch());
        }
    }

    /**
     * Counts one entry in `dropped` when the current batch stopped intake. Only a truncating buffer stops:
     * a splitting context decides in {@see self::add()}, where the entry's length is known.
     */
    public function dropIfFull(): bool
    {
        if ($this->splits()) {
            return false;
        }

        return $this->buffer()->dropIfFull();
    }

    /**
     * Stores the entry and hands every batch it completes to `$emit`, in order (spec 01 §5.2).
     *
     * @param \Closure(QueryBatch): void $emit
     */
    public function add(QueryEntry $entry, \Closure $emit): void
    {
        if (!$this->isAccepting()) {
            return;
        }
        if (!$this->splits()) {
            $this->buffer()->add($entry);

            return;
        }
        if ($this->isDue()) {
            $emit($this->closeBatch());
        }
        $collector = $this->buffer();
        $bytes = $collector->measure($entry);
        if (!$collector->fitsEmpty($bytes)) {
            $collector->countDropped();

            return;
        }
        if (!$collector->fits($bytes)) {
            $emit($this->closeBatch());
            $collector = $this->buffer();
        }
        $collector->append($entry, $bytes);
        if ($collector->isFull()) {
            $emit($this->closeBatch());
        }
    }

    /**
     * Ends the context: no later entry is taken and the buffer is released. A non-empty remainder goes to `$emit`.
     *
     * @param \Closure(QueryBatch): void $emit
     */
    public function end(\Closure $emit): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $collector = $this->collector;
        $this->collector = null;
        if ($collector !== null && !$collector->isEmpty()) {
            $emit($collector->close(new \DateTimeImmutable(), $this->seq++));
        }
    }

    /**
     * Sends the entries that still fit with the current header and adds the rest, in order, to the next batch.
     * Nothing is sent when no entry fits; the entries then go through {@see self::add()}, which counts one too
     * large even for an empty batch in `dropped`.
     *
     * @param \Closure(QueryBatch): void $emit
     */
    private function rebalance(\Closure $emit): void
    {
        if ($this->collector === null) {
            return;
        }
        $spilled = $this->collector->spill();
        if ($spilled === []) {
            return;
        }
        if ($this->collector->count() > 0) {
            $emit($this->closeBatch());
        }
        foreach ($spilled as $entry) {
            $this->add($entry, $emit);
        }
    }

    /**
     * Whether `flushIntervalSeconds` passed since the last batch and the buffer holds something to send.
     */
    private function isDue(): bool
    {
        return $this->collector !== null && !$this->collector->isEmpty()
            && ($this->settings->clock)() - $this->lastSend >= $this->settings->flushIntervalSeconds;
    }

    private function splits(): bool
    {
        return $this->split && $this->routed;
    }

    /**
     * Builds the batch of the current buffer and leaves the context without one until {@see self::buffer()}.
     */
    private function closeBatch(): QueryBatch
    {
        $collector = $this->buffer();
        $this->collector = null;
        $this->lastSend = ($this->settings->clock)();

        return $collector->close(new \DateTimeImmutable(), $this->seq++);
    }

    /**
     * The current buffer, created when the previous batch left none.
     */
    private function buffer(): QueryCollector
    {
        return $this->collector ??= $this->newCollector();
    }

    private function newCollector(): QueryCollector
    {
        $collector = ($this->settings->createCollector)($this->type, $this->id, $this->job);
        // Here rather than in createCollector(): a subclass overriding it cannot skip the reservation (spec 02 §5).
        if ($this->settings->sampling !== null) {
            $collector->reserveSample($this->settings->sampling->widest());
        }
        if ($this->route !== null) {
            $collector->setRoute($this->route);
        }

        return $collector;
    }
}
