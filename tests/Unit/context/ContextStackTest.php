<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\context;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\context\ContextSettings;
use mrstroz\querymonitoring\context\ContextStack;
use mrstroz\querymonitoring\context\RouteExclusions;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use mrstroz\querymonitoring\tests\Unit\StackProbe;

/**
 * YQM-47, spec 01 §5.1, §5.5 and §6: the context stack between the sources and the batch. It hands entries to
 * the deepest open context, holds the re-entry flag while the adapter sends and finalises once.
 */
final class ContextStackTest extends LoggedTestCase
{
    public function testStackWithoutARootTakesNothing(): void
    {
        $stack = new ContextStack(new Guard(), new StackProbe(), new ContextSettings(static fn(BatchType $type, string $id, ?JobInfo $job): QueryCollector => new QueryCollector('app', $type, $id, 'host'), static fn(): float => 0.0, 30, RouteExclusions::none()));

        self::assertFalse($stack->isAccepting());
        self::assertFalse($stack->dropIfFull());
        self::assertSame(0, $stack->depth());
    }

    public function testRootTakesEntriesAndFinalisationSendsThemOnce(): void
    {
        $probe = new StackProbe();
        $probe->stack->setRoute('site/index');
        $probe->stack->add($this->entry('a'));
        $probe->stack->add($this->entry('b'));

        $probe->stack->finalize();
        $probe->stack->finalize();

        self::assertCount(1, $probe->batches, 'a second finalisation sends nothing');
        $batch = $probe->batches[0];
        self::assertSame([BatchType::Http, 1, 'site/index'], [$batch->type, $batch->seq, $batch->route]);
        self::assertSame(['a', 'b'], $this->queries($batch));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $batch->id);
        self::assertSame(0, $probe->stack->depth());
    }

    public function testConsoleRootGivesAConsoleBatch(): void
    {
        $probe = new StackProbe(root: BatchType::Console);
        $probe->stack->add($this->entry('a'));

        $probe->stack->finalize();

        self::assertSame(BatchType::Console, $probe->batches[0]->type);
    }

    public function testEmptyRootSendsNothing(): void
    {
        $probe = new StackProbe();

        $probe->stack->finalize();

        self::assertSame([], $probe->batches);
    }

    public function testRootWithOnlyDroppedIsSent(): void
    {
        $probe = new StackProbe(maxBatchBytes: 300);
        $probe->stack->add($this->entry(str_repeat('x', 400)));

        $probe->stack->finalize();

        self::assertCount(1, $probe->batches);
        self::assertSame([[], 1], [$probe->batches[0]->queries, $probe->batches[0]->dropped]);
    }

    public function testNothingIsTakenAfterFinalisation(): void
    {
        $probe = new StackProbe(maxEntries: 1);
        $probe->stack->add($this->entry('a'));
        $probe->stack->finalize();

        $probe->stack->add($this->entry('late'));

        self::assertFalse($probe->stack->isAccepting());
        self::assertFalse($probe->stack->dropIfFull(), 'a late query is not a dropped one');
        self::assertSame(['a'], $this->queries($probe->batches[0]));
        self::assertSame(0, $probe->batches[0]->dropped);
    }

    public function testStackTakesNoEntryWhileTheAdapterSends(): void
    {
        $probe = new StackProbe(maxEntries: 1);
        $probe->stack->add($this->entry('a'));
        $seen = [];
        $probe->onSend = function () use ($probe, &$seen): void {
            $seen[] = [$probe->stack->isAccepting(), $probe->stack->dropIfFull()];
            $probe->stack->add($this->entry('from the adapter'));
        };

        $probe->stack->finalize();

        self::assertSame([[false, false]], $seen, 'the adapter runs with intake paused');
        self::assertSame(['a'], $this->queries($probe->batches[0]));
        self::assertSame(0, $probe->batches[0]->dropped);
    }

    public function testAdapterFailureIsLoggedOnceAndDoesNotEscape(): void
    {
        $stack = new ContextStack(new Guard(), new class implements BatchAdapterInterface {
            public function send(QueryBatch $batch): void
            {
                throw new \RuntimeException('adapter down');
            }
        }, new ContextSettings(static fn(BatchType $type, string $id, ?JobInfo $job): QueryCollector => new QueryCollector('app', $type, $id, 'host'), static fn(): float => 0.0, 30, RouteExclusions::none()));
        $stack->openRoot(BatchType::Http);
        $stack->add($this->entry('a'));

        $stack->finalize();

        self::assertCount(1, $this->errors());
        self::assertSame(Guard::LOG_CATEGORY, $this->errors()[0][2]);
        self::assertFalse($stack->isAccepting());
    }

    public function testSecondRootIsIgnored(): void
    {
        $probe = new StackProbe();

        $probe->stack->openRoot(BatchType::Console);

        self::assertSame(1, $probe->stack->depth());
        $probe->stack->add($this->entry('a'));
        $probe->stack->finalize();
        self::assertSame(BatchType::Http, $probe->batches[0]->type);
    }

    /**
     * @return list<?string>
     */
    private function queries(QueryBatch $batch): array
    {
        return array_map(static fn(QueryEntry $entry): ?string => $entry->query, $batch->queries);
    }

    private function entry(string $query): QueryEntry
    {
        return QueryEntry::success('mysql', 'db', 'select', $query, 1.0, []);
    }
}
