<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\collector;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use PHPUnit\Framework\TestCase;

/**
 * spec 01 §2, spec 02 §5: once a limit stops intake, the collector counts the next query in `dropped`
 * without an entry, so the recorder can skip the trace and the normaliser. `dropIfFull()` answers true only
 * when {@see QueryCollector::add()} would drop any entry, and then has counted it already.
 */
final class QueryCollectorDropIfFullTest extends TestCase
{
    public function testCollectorWithRoomDoesNotDrop(): void
    {
        $collector = $this->collector(maxEntries: 2);
        $collector->add($this->entry('first'));

        self::assertFalse($collector->dropIfFull());
        self::assertSame(0, $collector->dropped());
        self::assertSame(1, $collector->count());
    }

    public function testEntryLimitReachedDropsBeforeAnyEntryIsRejected(): void
    {
        $collector = $this->collector(maxEntries: 1);
        $collector->add($this->entry('first'));

        self::assertTrue($collector->dropIfFull(), 'the next entry would not be stored');
        self::assertSame(1, $collector->dropped());
        self::assertSame(1, $collector->count());
        self::assertTrue($collector->dropIfFull());
        self::assertSame(2, $collector->dropped());
    }

    public function testByteLimitExceededDropsEveryLaterQuery(): void
    {
        $collector = $this->collector(maxBatchBytes: 600);
        $collector->add($this->entry(str_repeat('x', 1000)));
        self::assertSame(1, $collector->dropped(), 'add() found the byte limit');

        self::assertTrue($collector->dropIfFull(), 'a shorter entry would not be stored either');
        self::assertSame(2, $collector->dropped());
        self::assertSame(0, $collector->count());
    }

    /**
     * Below the byte limit the size of the next entry is unknown; only add() can decide.
     */
    public function testNearlyFullByBytesIsNotFullYet(): void
    {
        $collector = $this->collector(maxBatchBytes: 600);
        $collector->add($this->entry('short'));

        self::assertFalse($collector->dropIfFull());
        self::assertSame(0, $collector->dropped());
    }

    public function testPausedFullCollectorNeitherDropsNorCounts(): void
    {
        $collector = $this->collector(maxEntries: 1);
        $collector->add($this->entry('first'));
        $collector->pause();

        self::assertFalse($collector->dropIfFull(), 'a query of the adapter is not an entry');
        self::assertSame(0, $collector->dropped());
    }

    public function testClosedFullCollectorNeitherDropsNorCounts(): void
    {
        $collector = $this->collector(maxEntries: 1);
        $collector->add($this->entry('first'));
        $collector->close($this->ts());

        self::assertFalse($collector->dropIfFull());
        self::assertSame(0, $collector->dropped());
        self::assertSame(0, $collector->close($this->ts())->dropped);
    }

    /**
     * The recorder calls dropIfFull() before every entry and add() only when it answered false; the batch
     * is the one add() alone gives for the same queries.
     */
    public function testBatchEqualsTheOneFromAddAlone(): void
    {
        $queries = ['a', str_repeat('b', 300), 'c', 'd', str_repeat('e', 400), 'f', 'g'];
        foreach ([[3, 262144], [100, 1200]] as [$maxEntries, $maxBatchBytes]) {
            $plain = $this->collector($maxEntries, $maxBatchBytes);
            $guarded = $this->collector($maxEntries, $maxBatchBytes);
            foreach ($queries as $query) {
                $plain->add($this->entry($query));
                if (!$guarded->dropIfFull()) {
                    $guarded->add($this->entry($query));
                }
            }

            $expected = $plain->close($this->ts());
            self::assertGreaterThan(0, $expected->dropped, "a limit was reached with {$maxEntries} entries, {$maxBatchBytes} bytes");
            self::assertSame($expected->toJson(), $guarded->close($this->ts())->toJson());
        }
    }

    private function collector(int $maxEntries = QueryCollector::DEFAULT_MAX_ENTRIES, int $maxBatchBytes = QueryCollector::DEFAULT_MAX_BATCH_BYTES): QueryCollector
    {
        return new QueryCollector('app', BatchType::Http, 'req_1', 'host', $maxEntries, $maxBatchBytes);
    }

    private function entry(string $query): QueryEntry
    {
        return QueryEntry::success('mysql', 'db', 'select', $query, 1.25, ['controllers/SiteController.php:12']);
    }

    private function ts(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-22T09:41:05.312Z');
    }
}
