<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\collector;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use PHPUnit\Framework\TestCase;

/**
 * YQM-3: spec 02 §5 (HTTP column), including the size invariant.
 */
final class QueryCollectorTest extends TestCase
{
    public function testSixHundredEntriesGiveFiveHundredAndDroppedHundred(): void
    {
        $collector = $this->collector();
        for ($i = 0; $i < 600; $i++) {
            $collector->add($this->entry("SELECT * FROM t WHERE id = :qp{$i}"));
        }

        $batch = $collector->close($this->ts());

        self::assertSame(500, $collector->count());
        self::assertSame(100, $collector->dropped());
        self::assertCount(500, $batch->queries);
        self::assertSame(100, $batch->dropped);
        self::assertSame(100, json_decode($batch->toJson(), true)['dropped']);
    }

    public function testEntriesKeepOrderOfAdding(): void
    {
        $collector = $this->collector();
        for ($i = 0; $i < 600; $i++) {
            $collector->add($this->entry("q{$i}"));
        }

        $queries = $collector->close($this->ts())->queries;

        self::assertSame('q0', $queries[0]->query);
        self::assertSame('q499', $queries[499]->query);
    }

    public function testErrorAfterEntryLimitIsDroppedToo(): void
    {
        $collector = $this->collector();
        for ($i = 0; $i < 500; $i++) {
            $collector->add($this->entry('SELECT ?'));
        }
        $collector->add(QueryEntry::error('mysql', 'db', 'insert', 'INSERT INTO t VALUES (?)', 1.0, '23000', []));

        $batch = $collector->close($this->ts());

        self::assertSame(1, $batch->dropped);
        self::assertCount(500, $batch->queries);
        foreach ($batch->queries as $entry) {
            self::assertSame(QueryEntry::RESULT_SUCCESS, $entry->result);
        }
    }

    public function testErrorAfterByteLimitIsDroppedToo(): void
    {
        $collector = $this->collector(maxBatchBytes: 1500);
        while ($collector->dropped() === 0) {
            $collector->add($this->entry(str_repeat('x', 100)));
        }
        $droppedBefore = $collector->dropped();
        $collector->add(QueryEntry::error('mysql', 'db', 'insert', str_repeat('y', 100), 1.0, '23000', []));

        $batch = $collector->close($this->ts());

        self::assertSame($droppedBefore + 1, $batch->dropped);
        self::assertLessThanOrEqual(1500, strlen($batch->toJson()));
    }

    public function testDefaultLimitsAreFromSpec(): void
    {
        self::assertSame(500, QueryCollector::DEFAULT_MAX_ENTRIES);
        self::assertSame(262144, QueryCollector::DEFAULT_MAX_BATCH_BYTES);
    }

    public function testDefaultByteLimitHoldsWithLongQueries(): void
    {
        $collector = $this->collector();
        for ($i = 0; $i < 400; $i++) {
            $collector->add($this->entry(str_repeat('a', 2045) . '…'));
        }

        $batch = $collector->close($this->ts());
        $json = $batch->toJson();

        self::assertGreaterThan(0, $batch->dropped);
        self::assertLessThan(500, count($batch->queries));
        self::assertLessThanOrEqual(262144, strlen($json));
        $this->assertTight($batch, $this->entry(str_repeat('a', 2045) . '…'), 262144);
    }

    /**
     * The limit is counted for the final JSON with header and `dropped`, and it is not
     * arbitrarily cautious: the first rejected entry would really not have fitted,
     * allowing only for the reserved digits of `seq`/`dropped` and one comma.
     */
    public function testByteLimitIsCountedForFinalJsonAndIsTight(): void
    {
        foreach ([600, 1000, 2500, 4096] as $max) {
            $collector = $this->collector(maxBatchBytes: $max);
            $rejected = null;
            for ($i = 0; $i < 400 && $rejected === null; $i++) {
                $entry = $this->entry(sprintf('SELECT * FROM t%d WHERE a = :qp%d', $i, $i));
                $collector->add($entry);
                if ($collector->dropped() > 0) {
                    $rejected = $entry;
                }
            }
            self::assertNotNull($rejected, "limit {$max} never reached");

            $batch = $collector->close($this->ts());

            self::assertLessThanOrEqual($max, strlen($batch->toJson()), "limit {$max}");
            $this->assertTight($batch, $rejected, $max);
        }
    }

    public function testInvariantHoldsWithLargeSeq(): void
    {
        $collector = $this->collector(maxBatchBytes: 2000);
        for ($i = 0; $i < 100; $i++) {
            $collector->add($this->entry("SELECT {$i}"));
        }

        $batch = $collector->close($this->ts(), 2147483647);

        self::assertSame(2147483647, $batch->seq);
        self::assertLessThanOrEqual(2000, strlen($batch->toJson()));
    }

    /**
     * Sweeps consecutive limits so that for some of them the batch ends within a few bytes
     * of the limit; a wide `seq` and a four-digit `dropped` must still fit there.
     */
    public function testInvariantHoldsAtEveryLimitWithWideSeqAndDropped(): void
    {
        $entryBytes = strlen(json_encode($this->entry('SELECT 0000')->toArray(), QueryBatch::JSON_FLAGS)) + 1;
        for ($max = 3000; $max < 3000 + $entryBytes; $max++) {
            $collector = $this->collector(maxBatchBytes: $max);
            for ($i = 0; $i < 1200; $i++) {
                $collector->add($this->entry(sprintf('SELECT %04d', $i)));
            }

            $batch = $collector->close($this->ts(), 2147483647);

            self::assertGreaterThanOrEqual(1000, $batch->dropped);
            self::assertLessThanOrEqual($max, strlen($batch->toJson()), "limit {$max}");
        }
    }

    public function testInvariantCountsEscapedBytesOfHeaderAndEntries(): void
    {
        $nasty = "a\"b\\c\u{1}d\u{1F}zażółć🙂";
        $collector = new QueryCollector($nasty, BatchType::Http, $nasty, $nasty, 500, 3000);
        $collector->setRoute($nasty);
        for ($i = 0; $i < 200; $i++) {
            $collector->add(QueryEntry::success('mysql', $nasty, 'select', "SELECT `x` FROM \"{$nasty}\"\n", 1.0, ["{$nasty}.php:1"]));
        }

        $batch = $collector->close($this->ts());

        self::assertGreaterThan(0, $batch->dropped);
        self::assertLessThanOrEqual(3000, strlen($batch->toJson()));
    }

    public function testSingleEntryLargerThanLimitIsDropped(): void
    {
        $collector = $this->collector(maxBatchBytes: 1000);
        $collector->add($this->entry(str_repeat('x', 2000)));

        $batch = $collector->close($this->ts());

        self::assertSame(0, $collector->count());
        self::assertSame(1, $batch->dropped);
        self::assertSame([], $batch->queries);
        self::assertLessThanOrEqual(1000, strlen($batch->toJson()));
    }

    public function testShorterEntryAfterFirstByteOverflowIsOnlyCounted(): void
    {
        $collector = $this->collector(maxBatchBytes: 1000);
        $collector->add($this->entry(str_repeat('x', 2000)));
        for ($i = 0; $i < 10; $i++) {
            $collector->add($this->entry('SELECT ?'));
        }

        $batch = $collector->close($this->ts());

        self::assertSame([], $batch->queries);
        self::assertSame(11, $batch->dropped);
    }

    public function testEntryAfterEntryLimitStopsIntakeForGood(): void
    {
        $collector = $this->collector(maxEntries: 3);
        for ($i = 0; $i < 5; $i++) {
            $collector->add($this->entry("q{$i}"));
        }

        $batch = $collector->close($this->ts());

        self::assertSame(['q0', 'q1', 'q2'], array_map(static fn(QueryEntry $e): ?string => $e->query, $batch->queries));
        self::assertSame(2, $batch->dropped);
    }

    public function testSetRouteAfterCloseIsIgnored(): void
    {
        $collector = $this->collector();
        $collector->setRoute('m/c/a');
        $batch = $collector->close($this->ts());

        $collector->setRoute('x/y/z');

        self::assertSame($batch, $collector->close($this->ts()));
        self::assertSame('m/c/a', $batch->route);
    }

    public function testRouteSetBeforeEntriesReducesCapacity(): void
    {
        $long = str_repeat('m', 1200);
        $collector = $this->collector(maxBatchBytes: 2000);
        $collector->setRoute($long);
        for ($i = 0; $i < 100; $i++) {
            $collector->add($this->entry("SELECT {$i}"));
        }

        $batch = $collector->close($this->ts());

        self::assertSame($long, $batch->route);
        self::assertLessThanOrEqual(2000, strlen($batch->toJson()));
    }

    public function testRouteSetAfterEntriesRemovesEntriesFromTheEnd(): void
    {
        $collector = $this->collector(maxBatchBytes: 3000);
        $added = [];
        for ($i = 0; $i < 200; $i++) {
            $entry = $this->entry("SELECT * FROM t WHERE id = :qp{$i}");
            $added[] = $entry;
            $collector->add($entry);
        }
        $long = str_repeat('m', 900);
        $collector->setRoute($long);

        $batch = $collector->close($this->ts());

        self::assertLessThanOrEqual(3000, strlen($batch->toJson()));
        self::assertSame($long, $batch->route);
        self::assertSame(array_slice($added, 0, count($batch->queries)), $batch->queries, 'kept entries are a prefix of the added ones');
        self::assertSame(200, count($batch->queries) + $batch->dropped);
    }

    public function testBatchCarriesCollectorHeader(): void
    {
        $collector = new QueryCollector('shop-api', BatchType::Http, 'req_1', 'web-03');
        $collector->setRoute('admin/orders/order/view');
        $collector->add($this->entry('SELECT ?'));

        $batch = $collector->close(new \DateTimeImmutable('2026-09-22T09:41:05.312Z'));

        self::assertSame('shop-api', $batch->app);
        self::assertSame(BatchType::Http, $batch->type);
        self::assertSame('req_1', $batch->id);
        self::assertSame('web-03', $batch->host);
        self::assertSame(1, $batch->seq);
        self::assertSame('admin/orders/order/view', $batch->route);
        self::assertSame('2026-09-22T09:41:05.312Z', $batch->toArray()['ts']);
    }

    public function testRouteDefaultsToNull(): void
    {
        $batch = $this->collector()->close($this->ts());

        self::assertNull($batch->route);
    }

    /**
     * spec 02 §5: `caller` counts towards the batch bytes. Three paths of `PATH_MAX` each, with a `query`
     * of `maxQueryLength`, still fit the default batch limit, but fewer of them than without `caller`.
     */
    public function testLongCallerCountsTowardsBatchBytes(): void
    {
        $caller = [str_repeat('a', 4090) . '.php:1', str_repeat('b', 4090) . '.php:2', str_repeat('c', 4090) . '.php:3'];

        $with = $this->filledWith($caller);
        $without = $this->filledWith([]);

        self::assertNotSame([], $with->queries, 'an entry with the longest caller and query fits the default limit');
        self::assertSame($caller, $with->queries[0]->caller);
        self::assertLessThan(count($without->queries), count($with->queries), 'caller bytes are counted, so fewer entries fit');
        self::assertLessThanOrEqual(QueryCollector::DEFAULT_MAX_BATCH_BYTES, strlen($with->toJson()));
    }

    public function testEntryThatFitsOnlyWithoutCallerIsDropped(): void
    {
        $collector = $this->collector(maxBatchBytes: 1000);
        $collector->add(QueryEntry::success('mysql', 'db', 'select', 'SELECT ?', 1.25, [str_repeat('p', 1000) . '.php:1']));

        $batch = $collector->close($this->ts());

        self::assertSame([], $batch->queries);
        self::assertSame(1, $batch->dropped);
        self::assertLessThanOrEqual(1000, strlen($batch->toJson()));
    }

    public function testSecondCloseReturnsSameBatchAndIgnoresArguments(): void
    {
        $collector = $this->collector();
        $collector->add($this->entry('SELECT ?'));

        $first = $collector->close($this->ts(), 1);
        $second = $collector->close(new \DateTimeImmutable('2030-01-01T00:00:00Z'), 9);

        self::assertTrue($collector->isClosed());
        self::assertSame($first, $second);
        self::assertSame(1, $second->seq);
    }

    public function testAddAfterCloseIsIgnoredAndNotCounted(): void
    {
        $collector = $this->collector();
        $collector->add($this->entry('SELECT ?'));
        self::assertFalse($collector->isClosed());
        $batch = $collector->close($this->ts());

        $collector->add($this->entry('SELECT late'));

        self::assertSame(1, $collector->count());
        self::assertSame(0, $collector->dropped());
        self::assertSame($batch, $collector->close($this->ts()));
        self::assertCount(1, $batch->queries);
    }

    public function testEmptyCollectorGivesValidEmptyBatch(): void
    {
        $batch = $this->collector()->close($this->ts());

        self::assertSame([], $batch->queries);
        self::assertSame(0, $batch->dropped);
        self::assertIsArray(json_decode($batch->toJson(), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * A collector with default limits, offered 100 entries of a `maxQueryLength` query and the given caller.
     *
     * @param list<string> $caller
     */
    private function filledWith(array $caller): QueryBatch
    {
        $collector = $this->collector();
        for ($i = 0; $i < 100; $i++) {
            $collector->add(QueryEntry::success('mysql', 'db', 'select', str_repeat('x', 8192), 1.25, $caller));
        }

        return $collector->close($this->ts());
    }

    private function assertTight(QueryBatch $batch, QueryEntry $rejected, int $max): void
    {
        $unusedReserve = (QueryCollector::SEQ_DIGITS - strlen((string) $batch->seq))
            + (QueryCollector::DROPPED_DIGITS - strlen((string) $batch->dropped));
        $rejectedBytes = strlen(json_encode($rejected->toArray(), QueryBatch::JSON_FLAGS));
        $comma = 1;
        $slack = 1;

        self::assertGreaterThan(
            $max,
            strlen($batch->toJson()) + $unusedReserve + $rejectedBytes + $comma + $slack,
            "limit {$max}: rejected entry would have fitted, the counter is too cautious",
        );
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
