<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\context;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use mrstroz\querymonitoring\tests\Unit\StackProbe;

/**
 * YQM-48, spec 01 §5.2 and §5.4, spec 02 §5: a console root splits its entries into batches once its route is
 * known — after the entry that reaches `maxEntries`, before the entry that does not fit in `maxBatchBytes`, before
 * the entry that comes after `flushIntervalSeconds` — and sends the rest when it ends. An `http` root never splits.
 */
final class ContextStackBatchingTest extends LoggedTestCase
{
    public function testFullBatchIsSentRightAfterTheEntryThatFillsIt(): void
    {
        $probe = $this->console(maxEntries: 3);

        $sentAfter = [];
        for ($i = 1; $i <= 7; $i++) {
            $probe->stack->add($this->entry("q{$i}"));
            $sentAfter[$i] = count($probe->batches);
        }
        $probe->stack->finalize();

        self::assertSame([1 => 0, 2 => 0, 3 => 1, 4 => 1, 5 => 1, 6 => 2, 7 => 2], $sentAfter);
        self::assertSame([['q1', 'q2', 'q3'], ['q4', 'q5', 'q6'], ['q7']], $this->queries($probe));
        self::assertSame([1, 2, 3], array_map(static fn(QueryBatch $b): int => $b->seq, $probe->batches));
        self::assertCount(1, array_unique(array_map(static fn(QueryBatch $b): string => $b->id, $probe->batches)));
        self::assertSame(['scenario/run'], array_values(array_unique(array_map(static fn(QueryBatch $b): ?string => $b->route, $probe->batches))));
    }

    public function testEntryThatDoesNotFitOpensTheNextBatch(): void
    {
        $limit = 1200;
        $probe = $this->console(maxBatchBytes: $limit);

        $sentAt = [];
        for ($i = 1; $i <= 20; $i++) {
            $before = count($probe->batches);
            $probe->stack->add($this->entry(sprintf('q%02d %s', $i, str_repeat('x', 100))));
            if (count($probe->batches) > $before) {
                $sentAt[] = $i;
            }
        }
        $probe->stack->finalize();

        $queries = $this->queries($probe);
        self::assertGreaterThan(2, count($queries));
        self::assertSame(range(1, 20), array_map(static fn(string $q): int => (int) substr($q, 1, 2), array_merge(...$queries)), 'every entry once, in order');
        self::assertSame(array_map(static fn(array $batch): int => (int) substr($batch[0], 1, 2), array_slice($queries, 1)), $sentAt, 'the buffer goes when the entry that opens the next batch arrives');
        foreach ($probe->batches as $batch) {
            self::assertLessThanOrEqual($limit, strlen($batch->toJson()));
            self::assertSame(0, $batch->dropped);
        }
    }

    public function testEntryLargerThanAnEmptyBatchCountsInTheOpenBufferWithoutASend(): void
    {
        $probe = $this->console(maxEntries: 3, maxBatchBytes: 1000);

        $probe->stack->add($this->entry('q1'));
        $probe->stack->add($this->entry(str_repeat('x', 2000)));

        self::assertCount(0, $probe->batches, 'no send for the oversized entry');
        $probe->stack->add($this->entry('q2'));
        $probe->stack->add($this->entry('q3'));
        $probe->stack->add($this->entry('q4'));
        $probe->stack->finalize();

        self::assertSame([['q1', 'q2', 'q3'], ['q4']], $this->queries($probe));
        self::assertSame([1, 0], array_map(static fn(QueryBatch $b): int => $b->dropped, $probe->batches), 'dropped belongs to one batch and starts at 0 after the send');
    }

    public function testBufferWithOnlyDroppedIsSentAtTheEnd(): void
    {
        $probe = $this->console(maxEntries: 1, maxBatchBytes: 1000);
        $probe->stack->add($this->entry('q1'));

        $probe->stack->add($this->entry(str_repeat('x', 2000)));
        $probe->stack->finalize();

        self::assertCount(2, $probe->batches);
        self::assertSame([[], 1], [$probe->batches[1]->queries, $probe->batches[1]->dropped]);
    }

    public function testIntervalSendsTheBufferBeforeTheNextEntry(): void
    {
        $probe = $this->console(flushIntervalSeconds: 30);

        $probe->stack->add($this->entry('q1'));
        $probe->now = 29.9;
        $probe->stack->add($this->entry('q2'));
        self::assertCount(0, $probe->batches, 'the interval has not passed');
        $probe->now = 30.0;
        $probe->stack->add($this->entry('q3'));
        self::assertSame([['q1', 'q2']], $this->queries($probe), 'the buffer goes before the entry that comes after the interval');
        $probe->now = 59.9;
        $probe->stack->add($this->entry('q4'));
        $probe->now = 60.0;
        $probe->stack->add($this->entry('q5'));

        self::assertSame([['q1', 'q2'], ['q3', 'q4']], $this->queries($probe), 'the next period counts from the last send');
    }

    public function testIntervalCountsFromTheSendOfAFullBatch(): void
    {
        $probe = $this->console(maxEntries: 2, flushIntervalSeconds: 30);

        $probe->stack->add($this->entry('q1'));
        $probe->now = 20.0;
        $probe->stack->add($this->entry('q2'));
        $probe->now = 45.0;
        $probe->stack->add($this->entry('q3'));

        self::assertSame([['q1', 'q2']], $this->queries($probe), '25 s since the last send, not 45 since the start');
    }

    public function testIdleProcessSendsNothingWhenTheIntervalPasses(): void
    {
        $probe = $this->console(flushIntervalSeconds: 30);
        $probe->stack->add($this->entry('q1'));

        $probe->now = 1000.0;

        self::assertCount(0, $probe->batches, 'no timer');
        self::assertTrue($probe->stack->isAccepting());
    }

    public function testIntervalDoesNotSendAnEmptyBuffer(): void
    {
        $probe = $this->console(maxEntries: 1, flushIntervalSeconds: 30);
        $probe->stack->add($this->entry('q1'));

        $probe->now = 100.0;
        $probe->stack->add($this->entry('q2'));

        self::assertSame([['q1'], ['q2']], $this->queries($probe));
    }

    public function testEntryOfTheAdapterIsIgnoredAndCollectingGoesOnAfterTheSend(): void
    {
        $probe = $this->console(maxEntries: 1);
        $ran = 0;
        $probe->onSend = function () use ($probe, &$ran): void {
            $ran++;
            $probe->stack->add($this->entry('from the adapter'));
        };

        $probe->stack->add($this->entry('q1'));
        $probe->stack->add($this->entry('q2'));

        self::assertSame(2, $ran, 'the adapter ran twice while the command was running');
        self::assertTrue($probe->stack->isAccepting(), 'intake resumes after the send');
        self::assertSame([['q1'], ['q2']], $this->queries($probe));
        self::assertSame([0, 0], array_map(static fn(QueryBatch $b): int => $b->dropped, $probe->batches));
    }

    public function testLostBatchLeavesAGapInSeqAndItsEntriesDoNotMoveOn(): void
    {
        $probe = $this->console(maxEntries: 2);
        $probe->failOnCall = 2;

        for ($i = 1; $i <= 5; $i++) {
            $probe->stack->add($this->entry("q{$i}"));
        }
        $probe->stack->finalize();

        self::assertSame(3, $probe->calls);
        self::assertSame([1, 3], array_map(static fn(QueryBatch $b): int => $b->seq, $probe->batches));
        self::assertSame([['q1', 'q2'], ['q5']], $this->queries($probe));
        self::assertCount(1, $this->errors());
    }

    public function testFailureToCreateTheNextBufferIsLoggedOnceAndCollectingGoesOn(): void
    {
        $probe = $this->console(maxEntries: 2);
        $probe->failCollectorOn = 2;
        // A source calls the stack inside its own guard (spec 01 §6), as sql\Recorder does.
        $guard = new Guard();

        for ($i = 1; $i <= 6; $i++) {
            $guard->run(fn() => $probe->stack->add($this->entry("q{$i}")), 'sql');
        }
        $probe->stack->finalize();

        $queries = $this->queries($probe);
        self::assertSame(['q1', 'q2'], $queries[0], 'the batch before the failure is intact');
        self::assertSame([['q1', 'q2'], ['q4', 'q5'], ['q6']], $queries, 'only the entry that met the failure is lost');
        self::assertContains('q6', array_merge(...$queries), 'a later entry is still collected');
        self::assertCount(1, $this->errors());
        $seqs = array_map(static fn(QueryBatch $b): int => $b->seq, $probe->batches);
        self::assertSame($seqs, array_values(array_unique($seqs)), 'no seq is used twice');
    }

    public function testRootBeforeItsRouteTruncatesAndSendsTheFullBufferOnceRouted(): void
    {
        $probe = new StackProbe(maxEntries: 2, root: BatchType::Console);

        $probe->stack->add($this->entry('q1'));
        $probe->stack->add($this->entry('q2'));
        $probe->stack->add($this->entry('q3'));
        self::assertCount(0, $probe->batches, 'nothing is sent before the route is known');

        $probe->stack->setRoute('scenario/run');
        self::assertCount(1, $probe->batches, 'the full buffer goes at once');
        $probe->stack->add($this->entry('q4'));
        $probe->stack->finalize();

        self::assertSame([['q1', 'q2'], ['q4']], $this->queries($probe));
        self::assertSame([1, 0], array_map(static fn(QueryBatch $b): int => $b->dropped, $probe->batches));
        self::assertSame('scenario/run', $probe->batches[0]->route);
    }

    public function testRootWhoseRouteIsNeverKnownSendsOneTruncatedBatchAtTheEnd(): void
    {
        $probe = new StackProbe(maxEntries: 2, root: BatchType::Console);

        for ($i = 1; $i <= 4; $i++) {
            $probe->stack->add($this->entry("q{$i}"));
        }
        $probe->stack->finalize();

        self::assertSame([['q1', 'q2']], $this->queries($probe));
        self::assertSame([2, null], [$probe->batches[0]->dropped, $probe->batches[0]->route]);
    }

    public function testHttpRootNeverSplits(): void
    {
        $probe = new StackProbe(maxEntries: 2, flushIntervalSeconds: 1);
        $probe->stack->setRoute('site/index');

        for ($i = 1; $i <= 4; $i++) {
            $probe->now = (float) $i * 10;
            $probe->stack->add($this->entry("q{$i}"));
        }
        self::assertCount(0, $probe->batches);
        $probe->stack->finalize();

        self::assertSame([['q1', 'q2']], $this->queries($probe));
        self::assertSame(2, $probe->batches[0]->dropped);
    }

    private function console(
        int $maxEntries = QueryCollector::DEFAULT_MAX_ENTRIES,
        int $maxBatchBytes = QueryCollector::DEFAULT_MAX_BATCH_BYTES,
        int $flushIntervalSeconds = 30,
    ): StackProbe {
        $probe = new StackProbe($maxEntries, $maxBatchBytes, BatchType::Console, $flushIntervalSeconds);
        $probe->stack->setRoute('scenario/run');

        return $probe;
    }

    /**
     * @return list<list<string>>
     */
    private function queries(StackProbe $probe): array
    {
        return array_map(
            static fn(QueryBatch $batch): array => array_map(static fn(QueryEntry $e): string => (string) $e->query, $batch->queries),
            $probe->batches,
        );
    }

    private function entry(string $query): QueryEntry
    {
        return QueryEntry::success('mysql', 'db', 'select', $query, 1.0, []);
    }
}
