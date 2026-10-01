<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\collector;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\batch\Sample;
use mrstroz\querymonitoring\batch\SampleReason;
use mrstroz\querymonitoring\collector\QueryCollector;
use PHPUnit\Framework\TestCase;

/**
 * YQM-58, spec 02 §5: with sampling on, the collector counts the header with the widest `sample` the batch can get,
 * so the batch still fits in `maxBatchBytes` after the stack sets its `sample`.
 */
final class QueryCollectorSampleTest extends TestCase
{
    public function testBatchWithTheWidestSampleFitsAtEveryLimit(): void
    {
        $widest = new Sample(0.12345678901234568, SampleReason::ManyQueries);
        foreach ($this->limits() as $max) {
            $collector = $this->collector($max);
            $collector->reserveSample($widest);
            $this->fill($collector);

            $batch = $collector->close($this->ts(), 2147483647)->withSample($widest);

            self::assertGreaterThan(0, $batch->dropped, "limit {$max} reached");
            self::assertLessThanOrEqual($max, strlen($batch->toJson()), "limit {$max}");
        }
    }

    /**
     * Guards the test above: without the reservation some limit of the sweep ends so close to the limit that the
     * sample would not fit, so the sweep does reach the case the reservation exists for.
     */
    public function testWithoutTheReservationTheSampleWouldNotFitAtSomeLimit(): void
    {
        $widest = new Sample(0.12345678901234568, SampleReason::ManyQueries);
        $overflows = 0;
        foreach ($this->limits() as $max) {
            $collector = $this->collector($max);
            $this->fill($collector);

            $batch = $collector->close($this->ts(), 2147483647)->withSample($widest);

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

        $collector->reserveSample(new Sample(1.0, SampleReason::ManyQueries));

        self::assertSame($batch, $collector->close($this->ts()));
        self::assertNull($batch->sample, 'the collector builds the batch without a sample; the stack sets it');
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
