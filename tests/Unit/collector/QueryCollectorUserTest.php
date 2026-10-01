<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\collector;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\batch\Sample;
use mrstroz\querymonitoring\batch\SampleReason;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\context\UserSource;
use PHPUnit\Framework\TestCase;

/**
 * YQM-60, spec 02 §5: with the `user` setting on, the collector counts the header with the widest `user` the source
 * may give, so the batch still fits in `maxBatchBytes` after the stack sets it.
 */
final class QueryCollectorUserTest extends TestCase
{
    public function testBatchWithTheWidestUserFitsAtEveryLimit(): void
    {
        foreach ($this->limits() as $max) {
            $collector = $this->collector($max);
            $collector->reserveUser(UserSource::widest());
            $this->fill($collector);

            $batch = $collector->close($this->ts(), 2147483647)->withUser(UserSource::widest());

            self::assertGreaterThan(0, $batch->dropped, "limit {$max} reached");
            self::assertLessThanOrEqual($max, strlen($batch->toJson()), "limit {$max}");
        }
    }

    public function testBatchWithTheWidestUserAndSampleFitsAtEveryLimit(): void
    {
        $widest = new Sample(0.12345678901234568, SampleReason::ManyQueries);
        foreach ($this->limits() as $max) {
            $collector = $this->collector($max);
            $collector->reserveSample($widest);
            $collector->reserveUser(UserSource::widest());
            $this->fill($collector);

            $batch = $collector->close($this->ts(), 2147483647)->withSample($widest)->withUser(UserSource::widest());

            self::assertLessThanOrEqual($max, strlen($batch->toJson()), "limit {$max}");
        }
    }

    /**
     * Guards the tests above: without the reservation some limit of the sweep ends so close to the limit that the
     * widest user would not fit, so the sweep does reach the case the reservation exists for.
     */
    public function testWithoutTheReservationTheWidestUserWouldNotFitAtSomeLimit(): void
    {
        $overflows = 0;
        foreach ($this->limits() as $max) {
            $collector = $this->collector($max);
            $this->fill($collector);

            $batch = $collector->close($this->ts(), 2147483647)->withUser(UserSource::widest());

            if (strlen($batch->toJson()) > $max) {
                $overflows++;
            }
        }

        self::assertGreaterThan(0, $overflows);
    }

    public function testReservationAfterCloseChangesNothing(): void
    {
        $collector = $this->collector(3000);
        $this->fill($collector);
        $batch = $collector->close($this->ts());

        $collector->reserveUser(UserSource::widest());

        self::assertSame($batch, $collector->close($this->ts()));
        self::assertNull($batch->user, 'the collector builds the batch without a user; the stack sets it');
    }

    /**
     * Consecutive limits over the width of one entry, so some batch ends within a byte of the limit.
     *
     * @return list<int>
     */
    private function limits(): array
    {
        $entryBytes = strlen(json_encode($this->entry(0)->toArray(), QueryBatch::JSON_FLAGS)) + 1;

        return range(3000, 3000 + $entryBytes);
    }

    private function fill(QueryCollector $collector): void
    {
        for ($i = 0; $i < 1200; $i++) {
            $collector->add($this->entry($i));
        }
    }

    private function collector(int $maxBatchBytes): QueryCollector
    {
        return new QueryCollector('app', BatchType::Console, 'req_1', 'host', QueryCollector::DEFAULT_MAX_ENTRIES, $maxBatchBytes);
    }

    private function entry(int $i): QueryEntry
    {
        return QueryEntry::success('mysql', 'db', 'select', sprintf('SELECT %04d', $i), 1.25, []);
    }

    private function ts(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-22T09:41:05.312Z');
    }
}
