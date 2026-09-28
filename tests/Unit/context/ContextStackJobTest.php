<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\context;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use mrstroz\querymonitoring\tests\Unit\StackProbe;

/**
 * YQM-50 and YQM-51, spec 01 §5.1, §5.3 and §5.5: job contexts on the stack. An entry belongs to the deepest open
 * context only; ending a job sends its rest and returns to the parent; an ended context keeps nothing.
 */
final class ContextStackJobTest extends LoggedTestCase
{
    public function testSequentialJobsHaveTheirOwnIdSeqAndEntries(): void
    {
        $probe = $this->console();

        $a = $probe->stack->beginJob(JobInfo::create('A'), 'A');
        $probe->stack->add($this->entry('a1'));
        self::assertNotNull($a);
        $probe->stack->end($a);
        $b = $probe->stack->beginJob(JobInfo::create('B'), 'B');
        $probe->stack->add($this->entry('b1'));
        self::assertNotNull($b);
        $probe->stack->end($b);

        self::assertSame([['A', 1, ['a1']], ['B', 1, ['b1']]], $this->summary($probe));
        self::assertNotSame($probe->batches[0]->id, $probe->batches[1]->id);
        self::assertSame(BatchType::Job, $probe->batches[0]->type);
        self::assertSame('worker/run', $probe->batches[0]->route, 'route of the root in a job batch');
    }

    public function testLargeJobSplitsWithOneIdAndTheRootKeepsItsOwnBuffer(): void
    {
        $probe = $this->console(maxEntries: 2);
        $probe->stack->add($this->entry('r1'));

        $job = $probe->stack->beginJob(JobInfo::create('Big'), 'Big');
        self::assertNotNull($job);
        for ($i = 1; $i <= 5; $i++) {
            $probe->stack->add($this->entry("j{$i}"));
        }
        $probe->stack->end($job);
        $probe->stack->add($this->entry('r2'));
        $probe->stack->finalize();

        self::assertSame([['Big', 1, ['j1', 'j2']], ['Big', 2, ['j3', 'j4']], ['Big', 3, ['j5']], [null, 1, ['r1', 'r2']]], $this->summary($probe));
        self::assertCount(1, array_unique(array_map(static fn(QueryBatch $b): string => $b->id, array_slice($probe->batches, 0, 3))));
    }

    public function testNestedJobReturnsToItsParent(): void
    {
        $probe = $this->console();
        $probe->stack->add($this->entry('r1'));
        $outer = $probe->stack->beginJob(JobInfo::create('Outer'), 'Outer');
        $probe->stack->add($this->entry('o1'));
        $inner = $probe->stack->beginJob(JobInfo::create('Inner'), 'Inner');
        $probe->stack->add($this->entry('i1'));
        self::assertNotNull($inner);
        self::assertNotNull($outer);

        $probe->stack->end($inner);
        $probe->stack->add($this->entry('o2'));
        $probe->stack->end($outer);
        $probe->stack->add($this->entry('r2'));
        $probe->stack->finalize();

        self::assertSame([['Inner', 1, ['i1']], ['Outer', 1, ['o1', 'o2']], [null, 1, ['r1', 'r2']]], $this->summary($probe));
    }

    public function testEndingTheParentEndsItsOpenChildrenDeepestFirst(): void
    {
        $probe = $this->console();
        $outer = $probe->stack->beginJob(JobInfo::create('Outer'), 'Outer');
        $probe->stack->add($this->entry('o1'));
        $middle = $probe->stack->beginJob(JobInfo::create('Middle'), 'Middle');
        $probe->stack->add($this->entry('m1'));
        $inner = $probe->stack->beginJob(JobInfo::create('Inner'), 'Inner');
        $probe->stack->add($this->entry('i1'));
        self::assertNotNull($outer);
        self::assertNotNull($inner);

        $probe->stack->end($outer);
        $probe->stack->end($inner);

        self::assertSame([['Inner', 1, ['i1']], ['Middle', 1, ['m1']], ['Outer', 1, ['o1']]], $this->summary($probe));
        self::assertSame(1, $probe->stack->depth(), 'back at the root');
    }

    public function testSecondEndAndEndOfTheRootDoNothing(): void
    {
        $probe = $this->console();
        $a = $probe->stack->beginJob(JobInfo::create('A'), 'A');
        $probe->stack->add($this->entry('a1'));
        self::assertNotNull($a);
        $probe->stack->end($a);

        $b = $probe->stack->beginJob(JobInfo::create('B'), 'B');
        $probe->stack->add($this->entry('b1'));
        $probe->stack->end($a);
        $probe->stack->add($this->entry('b2'));

        self::assertSame(2, $probe->stack->depth(), 'a late end of A does not close B');
        self::assertNotNull($b);
        $probe->stack->end($b);
        self::assertSame([['A', 1, ['a1']], ['B', 1, ['b1', 'b2']]], $this->summary($probe));
    }

    public function testContextOfAnotherStackEndsNothing(): void
    {
        $probe = $this->console();
        $other = $this->console();
        $foreign = $other->stack->beginJob(JobInfo::create('Foreign'), 'Foreign');
        $mine = $probe->stack->beginJob(JobInfo::create('Mine'), 'Mine');
        $probe->stack->add($this->entry('m1'));
        self::assertNotNull($foreign);

        $probe->stack->end($foreign);

        self::assertSame(2, $probe->stack->depth());
        self::assertSame([], $probe->batches);
        self::assertNotNull($mine);
    }

    public function testFinalisationEndsOpenJobsDeepestFirstAndThenRefusesNewOnes(): void
    {
        $probe = $this->console();
        $probe->stack->add($this->entry('r1'));
        $job = $probe->stack->beginJob(JobInfo::create('Open'), 'Open');
        $probe->stack->add($this->entry('j1'));

        $probe->stack->finalize();
        self::assertNotNull($job);
        $probe->stack->end($job);

        self::assertSame([['Open', 1, ['j1']], [null, 1, ['r1']]], $this->summary($probe), 'each context sent once');
        self::assertNull($probe->stack->beginJob(JobInfo::create('Late'), 'Late'));
    }

    public function testBeginJobChecksTheIntervalOfTheParent(): void
    {
        $probe = $this->console(flushIntervalSeconds: 30);
        $probe->stack->add($this->entry('r1'));
        $probe->now = 31.0;

        $job = $probe->stack->beginJob(JobInfo::create('A'), 'A');

        self::assertSame([[null, 1, ['r1']]], $this->summary($probe), 'the parent sent its due buffer before the job opened');
        self::assertNotNull($job);
    }

    public function testManyJobsLeaveOnlyTheRootOnTheStack(): void
    {
        $probe = $this->console(maxEntries: 3);

        for ($i = 0; $i < 200; $i++) {
            $job = $probe->stack->beginJob(JobInfo::create("J{$i}"), "J{$i}");
            $probe->stack->add($this->entry("q{$i}"));
            self::assertNotNull($job);
            $probe->stack->end($job);
        }

        self::assertSame(1, $probe->stack->depth());
        self::assertCount(200, $probe->batches);
    }

    public function testDepthIsLimitedToSixteenContextsWithTheRoot(): void
    {
        $probe = $this->console();
        $opened = 0;
        for ($i = 0; $i < 15; $i++) {
            $probe->stack->beginJob(JobInfo::create("L{$i}"), "L{$i}") !== null && $opened++;
        }

        self::assertSame([15, 16], [$opened, $probe->stack->depth()]);
        try {
            $probe->stack->beginJob(JobInfo::create('L15'), 'L15');
        } catch (\Throwable) {
            // spec 01 §5.1: the component turns the refusal into an inert handle and one error
        }
        $probe->stack->add($this->entry('deep'));

        self::assertSame(16, $probe->stack->depth());
        $probe->stack->finalize();
        self::assertSame(['L14', 1, ['deep']], $this->summary($probe)[0]);
    }

    private function console(int $maxEntries = 500, int $flushIntervalSeconds = 30): StackProbe
    {
        $probe = new StackProbe($maxEntries, root: BatchType::Console, flushIntervalSeconds: $flushIntervalSeconds);
        $probe->stack->setRoute('worker/run');

        return $probe;
    }

    /**
     * `[job name, seq, queries]` of each batch sent, in order.
     *
     * @return list<array{?string, int, list<string>}>
     */
    private function summary(StackProbe $probe): array
    {
        return array_map(static fn(QueryBatch $b): array => [
            $b->job?->name,
            $b->seq,
            array_map(static fn(QueryEntry $e): string => (string) $e->query, $b->queries),
        ], $probe->batches);
    }

    private function entry(string $query): QueryEntry
    {
        return QueryEntry::success('mysql', 'db', 'select', $query, 1.0, []);
    }
}
