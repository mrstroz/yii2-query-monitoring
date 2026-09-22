<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\collector;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;

/**
 * In-memory buffer for one batch with the entry and byte limits of spec 02 §5.
 *
 * Invariant: `strlen($this->close(...)->toJson()) <= $maxBatchBytes` whenever the header alone fits.
 * The byte counter holds the header with its current values (with {@see self::SEQ_DIGITS} and
 * {@see self::DROPPED_DIGITS} digits reserved for `seq` and `dropped`, and the 24-character `ts`)
 * plus each entry serialised with {@see QueryBatch::JSON_FLAGS} and its separating comma.
 * The first entry that does not fit in `maxBatchBytes` or `maxEntries` stops intake: it and every
 * later entry, shorter ones included, are not stored and increase `dropped`.
 * When {@see self::setAction()} makes the header too long for the entries already stored,
 * {@see self::close()} removes entries from the end and counts them in `dropped`.
 */
final class QueryCollector
{
    public const DEFAULT_MAX_ENTRIES = 500;
    public const DEFAULT_MAX_BATCH_BYTES = 262144;
    public const SEQ_DIGITS = 10;
    public const DROPPED_DIGITS = 10;

    /** Largest value that fits in the reserved digits of `seq` and `dropped`. */
    private const RESERVED_NUMBER = 9_999_999_999;

    /** @var list<QueryEntry> */
    private array $entries = [];

    /** @var list<int> serialised length of each stored entry, parallel to $entries */
    private array $entryLengths = [];

    /** Sum of $entryLengths, without separators. */
    private int $entryBytes = 0;

    private int $headerBytes;
    private int $dropped = 0;
    private bool $full = false;
    private ?QueryBatch $batch = null;
    private bool $paused = false;
    private ?string $module = null;
    private ?string $controller = null;
    private ?string $action = null;

    public function __construct(
        private readonly string $app,
        private readonly BatchType $type,
        private readonly string $id,
        private readonly string $host,
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        private readonly int $maxBatchBytes = self::DEFAULT_MAX_BATCH_BYTES,
    ) {
        $this->headerBytes = $this->measureHeader();
    }

    /**
     * Sets the entry action for the header. May be called before or after entries are added.
     * Ignored after {@see self::close()}.
     */
    public function setAction(?string $module, ?string $controller, ?string $action): void
    {
        if ($this->batch !== null) {
            return;
        }
        $this->module = $module;
        $this->controller = $controller;
        $this->action = $action;
        $this->headerBytes = $this->measureHeader();
    }

    /**
     * Stores the entry, or counts it in `dropped` when a limit is reached.
     * While {@see self::isAccepting()} is false the entry is ignored and `dropped` does not change.
     */
    public function add(QueryEntry $entry): void
    {
        if (!$this->isAccepting()) {
            return;
        }
        if ($this->full || count($this->entries) >= $this->maxEntries) {
            $this->full = true;
            $this->drop();

            return;
        }
        $bytes = strlen(json_encode($entry->toArray(), QueryBatch::JSON_FLAGS));
        if ($this->totalBytes($bytes, count($this->entries) + 1) > $this->maxBatchBytes) {
            $this->full = true;
            $this->drop();

            return;
        }
        $this->entries[] = $entry;
        $this->entryLengths[] = $bytes;
        $this->entryBytes += $bytes;
    }

    /**
     * Closes the collector and builds the batch. A second call returns the same batch and ignores its arguments.
     */
    public function close(\DateTimeImmutable $ts, int $seq = 1): QueryBatch
    {
        if ($this->batch !== null) {
            return $this->batch;
        }
        while ($this->entries !== [] && $this->totalBytes(0, count($this->entries)) > $this->maxBatchBytes) {
            array_pop($this->entries);
            $this->entryBytes -= (int) array_pop($this->entryLengths);
            $this->drop();
        }

        return $this->batch = new QueryBatch(
            app: $this->app,
            type: $this->type,
            id: $this->id,
            seq: $seq,
            module: $this->module,
            controller: $this->controller,
            action: $this->action,
            ts: $ts,
            host: $this->host,
            dropped: $this->dropped,
            queries: $this->entries,
        );
    }

    public function isClosed(): bool
    {
        return $this->batch !== null;
    }

    /**
     * Stops intake until {@see self::resume()}: the re-entry flag of spec 01 §6, set around the
     * adapter's `send()` so that queries the adapter runs do not become entries.
     */
    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    /** False after {@see self::close()} and while paused; {@see self::add()} then ignores entries. */
    public function isAccepting(): bool
    {
        return $this->batch === null && !$this->paused;
    }

    /** Number of stored entries. */
    public function count(): int
    {
        return count($this->entries);
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    private function drop(): void
    {
        $this->dropped++;
    }

    /**
     * Bytes of the batch JSON with `$extraBytes` more entry bytes and `$entryCount` entries in total.
     */
    private function totalBytes(int $extraBytes, int $entryCount): int
    {
        return $this->headerBytes + $this->entryBytes + $extraBytes + max(0, $entryCount - 1);
    }

    /**
     * Bytes of the header with an empty entry list and the widest reserved `seq`, `dropped` and `ts`.
     */
    private function measureHeader(): int
    {
        $header = new QueryBatch(
            app: $this->app,
            type: $this->type,
            id: $this->id,
            seq: self::RESERVED_NUMBER,
            module: $this->module,
            controller: $this->controller,
            action: $this->action,
            ts: new \DateTimeImmutable('@0'),
            host: $this->host,
            dropped: self::RESERVED_NUMBER,
            queries: [],
        );

        return strlen($header->toJson());
    }
}
