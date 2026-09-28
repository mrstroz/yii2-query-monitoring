<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\collector;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;

/**
 * In-memory buffer for one batch with the entry and byte limits of spec 02 §5.
 *
 * Invariant: `strlen($this->close(...)->toJson()) <= $maxBatchBytes` whenever the header alone fits.
 * The byte counter holds the header with its current values, `route` and `job` included (with
 * {@see self::SEQ_DIGITS} and {@see self::DROPPED_DIGITS} digits reserved for `seq` and `dropped`, and the
 * 24-character `ts`)
 * plus each entry serialised with {@see QueryBatch::JSON_FLAGS} and its separating comma.
 * The first entry that does not fit in `maxBatchBytes` or `maxEntries` stops intake: it and every
 * later entry, shorter ones included, are not stored and increase `dropped`.
 * When {@see self::setRoute()} makes the header too long for the entries already stored,
 * {@see self::close()} removes entries from the end and counts them in `dropped`.
 *
 * In the console and job contexts the owning context splits instead of truncating: it asks {@see self::fits()}
 * and {@see self::fitsEmpty()}, stores with {@see self::append()} and counts an entry too large even for an
 * empty batch with {@see self::countDropped()} (spec 01 §5.2).
 *
 * One collector is one batch. The context that owns it ({@see \mrstroz\querymonitoring\context\Context})
 * replaces it after each batch; the re-entry flag of spec 01 §6 belongs to the context stack, not here.
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
    private ?string $route = null;

    public function __construct(
        private readonly string $app,
        private readonly BatchType $type,
        private readonly string $id,
        private readonly string $host,
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        private readonly int $maxBatchBytes = self::DEFAULT_MAX_BATCH_BYTES,
        private readonly ?JobInfo $job = null,
    ) {
        $this->headerBytes = $this->measureHeader();
    }

    /**
     * Sets `route`, the unique id of the entry action. May be called before or after entries are added.
     * Ignored after {@see self::close()}.
     */
    public function setRoute(?string $route): void
    {
        if ($this->batch !== null) {
            return;
        }
        $this->route = $route;
        $this->headerBytes = $this->measureHeader();
    }

    /**
     * Stores the entry, or counts it in `dropped` when a limit is reached.
     * While {@see self::isAccepting()} is false the entry is ignored and `dropped` does not change.
     */
    public function add(QueryEntry $entry): void
    {
        if (!$this->isAccepting() || $this->dropIfFull()) {
            return;
        }
        $bytes = $this->measure($entry);
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
     * Counts one entry in `dropped` when the collector would not store any further entry, so the
     * caller can skip building it. True means the entry would not be stored and `dropped` already
     * counts it; false means {@see self::add()} decides, the byte limit included, because that
     * depends on the entry's length. False without counting while {@see self::isAccepting()} is false.
     */
    public function dropIfFull(): bool
    {
        if (!$this->isAccepting()) {
            return false;
        }
        if ($this->full || count($this->entries) >= $this->maxEntries) {
            $this->full = true;
            $this->drop();

            return true;
        }

        return false;
    }

    /**
     * Serialised length of `$entry`, the bytes it adds to the batch without its separating comma.
     */
    public function measure(QueryEntry $entry): int
    {
        return strlen(json_encode($entry->toArray(), QueryBatch::JSON_FLAGS));
    }

    /**
     * Whether an entry of `$bytes` fits next to the stored ones, under both limits.
     */
    public function fits(int $bytes): bool
    {
        return count($this->entries) < $this->maxEntries
            && $this->totalBytes($bytes, count($this->entries) + 1) <= $this->maxBatchBytes;
    }

    /**
     * Whether an entry of `$bytes` would fit in an empty batch with the current header.
     */
    public function fitsEmpty(int $bytes): bool
    {
        return $this->headerBytes + $bytes <= $this->maxBatchBytes;
    }

    /**
     * Stores the entry without checking the limits; the caller checked {@see self::fits()}. Ignored after close.
     *
     * @param int $bytes the value of {@see self::measure()} for `$entry`
     */
    public function append(QueryEntry $entry, int $bytes): void
    {
        if ($this->batch !== null) {
            return;
        }
        $this->entries[] = $entry;
        $this->entryLengths[] = $bytes;
        $this->entryBytes += $bytes;
    }

    /**
     * Counts one entry in `dropped` without storing it. Ignored after close.
     */
    public function countDropped(): void
    {
        if ($this->batch === null) {
            $this->drop();
        }
    }

    /**
     * True once the batch holds `maxEntries` entries or {@see self::add()} stopped intake.
     */
    public function isFull(): bool
    {
        return $this->full || count($this->entries) >= $this->maxEntries;
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
            route: $this->route,
            ts: $ts,
            host: $this->host,
            dropped: $this->dropped,
            queries: $this->entries,
            job: $this->job,
        );
    }

    public function isClosed(): bool
    {
        return $this->batch !== null;
    }

    /** False after {@see self::close()}; {@see self::add()} then ignores entries. */
    public function isAccepting(): bool
    {
        return $this->batch === null;
    }

    /** True when the batch would hold no entry and `dropped` is 0: there is nothing to send. */
    public function isEmpty(): bool
    {
        return $this->entries === [] && $this->dropped === 0;
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
            route: $this->route,
            ts: new \DateTimeImmutable('@0'),
            host: $this->host,
            dropped: self::RESERVED_NUMBER,
            queries: [],
            job: $this->job,
        );

        return strlen($header->toJson());
    }
}
