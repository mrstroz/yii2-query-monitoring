<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\context\BatchSampling;
use mrstroz\querymonitoring\context\ContextSettings;
use mrstroz\querymonitoring\context\ContextStack;
use mrstroz\querymonitoring\context\RouteExclusions;
use mrstroz\querymonitoring\context\UserSource;
use mrstroz\querymonitoring\support\Guard;

/**
 * A {@see ContextStack} with an open root and itself as the adapter: it keeps every batch it is sent, and runs
 * {@see self::$onSend} inside `send()`, where the stack does not take entries (spec 01 §6). The clock reads
 * {@see self::$now}; {@see self::$failOnCall} makes that call of `send()` throw after counting it, {@see self::$failCollectorOn}
 * that request for a new buffer.
 */
final class StackProbe implements BatchAdapterInterface
{
    public readonly ContextStack $stack;

    /** @var list<QueryBatch> */
    public array $batches = [];

    /** @var (\Closure(QueryBatch): void)|null */
    public ?\Closure $onSend = null;

    /** Seconds the stack's clock reads. */
    public float $now = 0.0;

    /** Number of `send()` calls, a failed one included. */
    public int $calls = 0;

    public ?int $failOnCall = null;

    /** Number of buffers the stack asked for, a failed one included. */
    public int $collectors = 0;

    /** That request for a buffer throws. */
    public ?int $failCollectorOn = null;

    public function __construct(
        int $maxEntries = QueryCollector::DEFAULT_MAX_ENTRIES,
        int $maxBatchBytes = QueryCollector::DEFAULT_MAX_BATCH_BYTES,
        BatchType $root = BatchType::Http,
        int $flushIntervalSeconds = 30,
        ?RouteExclusions $exclusions = null,
        ?BatchSampling $sampling = null,
        ?UserSource $user = null,
    ) {
        $this->stack = new ContextStack(new Guard(), $this, new ContextSettings(
            function (BatchType $type, string $id, ?JobInfo $job) use ($maxEntries, $maxBatchBytes): QueryCollector {
                if (++$this->collectors === $this->failCollectorOn) {
                    throw new \RuntimeException('StackProbe buffer failed on purpose.');
                }

                return new QueryCollector('app', $type, $id, 'host', $maxEntries, $maxBatchBytes, $job);
            },
            fn(): float => $this->now,
            $flushIntervalSeconds,
            $exclusions ?? RouteExclusions::none(),
            $sampling,
            $user,
        ));
        $this->stack->openRoot($root);
    }

    public function send(QueryBatch $batch): void
    {
        if (++$this->calls === $this->failOnCall) {
            throw new \RuntimeException('StackProbe failed on purpose.');
        }
        $this->batches[] = $batch;
        if ($this->onSend !== null) {
            ($this->onSend)($batch);
        }
    }

    /**
     * Finalises the stack and returns the entries of every batch it sent, in order.
     *
     * @return list<QueryEntry>
     */
    public function finish(): array
    {
        $this->stack->finalize();

        return $this->entries();
    }

    /**
     * @return list<QueryEntry>
     */
    public function entries(): array
    {
        return array_merge([], ...array_map(static fn(QueryBatch $batch): array => $batch->queries, $this->batches));
    }

    /** Sum of `dropped` of every batch sent. */
    public function dropped(): int
    {
        return array_sum(array_map(static fn(QueryBatch $batch): int => $batch->dropped, $this->batches));
    }
}
