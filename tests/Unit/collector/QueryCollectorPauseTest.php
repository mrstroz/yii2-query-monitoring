<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\collector;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use PHPUnit\Framework\TestCase;

/**
 * YQM-9, spec 01 §6: the re-entry flag. While the collector is paused (the adapter's send()),
 * entries are ignored and not counted as dropped; resume() takes them again.
 */
final class QueryCollectorPauseTest extends TestCase
{
    public function testNewCollectorAccepts(): void
    {
        self::assertTrue($this->collector()->isAccepting());
    }

    public function testPausedOpenCollectorIgnoresEntries(): void
    {
        $collector = $this->collector();
        $collector->add($this->entry('before'));
        $collector->pause();

        self::assertFalse($collector->isAccepting());
        $collector->add($this->entry('paused'));

        self::assertSame(1, $collector->count());
        self::assertSame(0, $collector->dropped(), 'an ignored entry is not a dropped one');
    }

    public function testResumeAcceptsAgain(): void
    {
        $collector = $this->collector();
        $collector->pause();
        $collector->add($this->entry('paused'));
        $collector->resume();

        self::assertTrue($collector->isAccepting());
        $collector->add($this->entry('after'));

        $queries = $collector->close(new \DateTimeImmutable())->queries;
        self::assertSame(['after'], array_map(static fn(QueryEntry $e): ?string => $e->query, $queries));
    }

    public function testPausedCollectorIgnoresEntriesAtTheLimitWithoutDropping(): void
    {
        $collector = $this->collector(maxEntries: 1);
        $collector->add($this->entry('first'));
        $collector->pause();
        $collector->add($this->entry('paused'));

        self::assertSame(0, $collector->dropped());
    }

    public function testClosedCollectorDoesNotAcceptEvenAfterResume(): void
    {
        $collector = $this->collector();
        $collector->pause();
        $collector->close(new \DateTimeImmutable());
        $collector->resume();

        self::assertFalse($collector->isAccepting());
    }

    private function collector(int $maxEntries = QueryCollector::DEFAULT_MAX_ENTRIES): QueryCollector
    {
        return new QueryCollector('app', BatchType::Http, 'req_1', 'host', $maxEntries);
    }

    private function entry(string $query): QueryEntry
    {
        return QueryEntry::success('mysql', 'db', 'select', $query, 1.25);
    }
}
