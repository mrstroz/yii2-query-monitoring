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
 * spec 01 §5.3, `$scope`: a job whose scope object was released ends at the next operation of the package —
 * a query, `beginJob()`, `end()`, finalisation — before that operation records anything, and sends its rest.
 * Nothing is ended while the adapter sends. Released jobs are cut from the deepest down.
 */
final class ContextStackScopeTest extends LoggedTestCase
{
    public function testQueryAfterTheScopeIsReleasedBelongsToTheParent(): void
    {
        $probe = $this->console();
        $scope = new \stdClass();
        $probe->stack->beginJob(JobInfo::create('Handled'), 'Handled', $scope);
        $probe->stack->add($this->entry('job'));

        unset($scope);
        $probe->stack->add($this->entry('parent'));

        self::assertSame([['Handled', ['job']]], $this->summary($probe), 'the job was sent before the parent\'s query was recorded');
        self::assertSame(1, $probe->stack->depth());
        $probe->stack->finalize();
        self::assertSame([['Handled', ['job']], [null, ['parent']]], $this->summary($probe));
    }

    public function testJobWhoseScopeLivesIsNotEnded(): void
    {
        $probe = $this->console();
        $scope = new \stdClass();
        $probe->stack->beginJob(JobInfo::create('Running'), 'Running', $scope);

        $probe->stack->add($this->entry('a'));
        $probe->stack->add($this->entry('b'));

        self::assertSame(2, $probe->stack->depth());
        self::assertCount(0, $probe->batches);
        // The scope lives until here.
        unset($scope);
    }

    public function testJobWithoutAScopeIsNeverEndedByTheStack(): void
    {
        $probe = $this->console();
        $probe->stack->beginJob(JobInfo::create('Manual'), 'Manual');

        $probe->stack->add($this->entry('a'));
        gc_collect_cycles();
        $probe->stack->add($this->entry('b'));

        self::assertSame(2, $probe->stack->depth());
        self::assertCount(0, $probe->batches);
    }

    public function testNextBeginJobEndsTheReleasedJobFirst(): void
    {
        $probe = $this->console();
        $scope = new \stdClass();
        $probe->stack->beginJob(JobInfo::create('Handled'), 'Handled', $scope);
        $probe->stack->add($this->entry('handled'));
        unset($scope);

        $next = $probe->stack->beginJob(JobInfo::create('Next'), 'Next');
        $probe->stack->add($this->entry('next'));
        self::assertNotNull($next);
        $probe->stack->end($next);

        self::assertSame([['Handled', ['handled']], ['Next', ['next']]], $this->summary($probe), 'Next is not nested in the released job');
        self::assertSame(1, $probe->stack->depth());
    }

    public function testFinalisationEndsTheReleasedJobBeforeTheRoot(): void
    {
        $probe = $this->console();
        $probe->stack->add($this->entry('root'));
        $scope = new \stdClass();
        $probe->stack->beginJob(JobInfo::create('Handled'), 'Handled', $scope);
        $probe->stack->add($this->entry('handled'));
        unset($scope);

        $probe->stack->finalize();

        self::assertSame([['Handled', ['handled']], [null, ['root']]], $this->summary($probe));
    }

    public function testReleasedChildAndParentAreCutFromTheDeepest(): void
    {
        $probe = $this->console();
        $outer = new \stdClass();
        $inner = new \stdClass();
        $probe->stack->beginJob(JobInfo::create('Outer'), 'Outer', $outer);
        $probe->stack->add($this->entry('o'));
        $probe->stack->beginJob(JobInfo::create('Inner'), 'Inner', $inner);
        $probe->stack->add($this->entry('i'));

        unset($inner, $outer);
        $probe->stack->add($this->entry('root'));
        $probe->stack->finalize();

        self::assertSame([['Inner', ['i']], ['Outer', ['o']], [null, ['root']]], $this->summary($probe));
    }

    public function testReleasedParentUnderALiveChildWaitsForTheChild(): void
    {
        $probe = $this->console();
        $outer = new \stdClass();
        $probe->stack->beginJob(JobInfo::create('Outer'), 'Outer', $outer);
        $probe->stack->add($this->entry('outer'));
        $child = $probe->stack->beginJob(JobInfo::create('Child'), 'Child');
        self::assertNotNull($child);

        unset($outer);
        $probe->stack->add($this->entry('child'));
        self::assertSame(3, $probe->stack->depth(), 'the live child is the deepest; nothing is cut under it');
        $probe->stack->end($child);
        $probe->stack->add($this->entry('root'));
        $probe->stack->finalize();

        self::assertSame([['Child', ['child']], ['Outer', ['outer']], [null, ['root']]], $this->summary($probe), 'the released parent went at the next operation after its child, before the root took the entry');
    }

    public function testAdapterQueryDuringASendEndsNoReleasedJob(): void
    {
        $probe = $this->console();
        $outer = new \stdClass();
        $inner = new \stdClass();
        $probe->stack->beginJob(JobInfo::create('Outer'), 'Outer', $outer);
        $probe->stack->add($this->entry('o'));
        $probe->stack->beginJob(JobInfo::create('Inner'), 'Inner', $inner);
        $probe->stack->add($this->entry('i'));
        $send = new \stdClass();
        $send->depth = 0;
        $send->deepest = 0;
        $send->answers = [];
        $probe->onSend = static function () use ($probe, $send): void {
            $send->depth++;
            $send->deepest = max($send->deepest, $send->depth);
            // The adapter runs a query: the recorder asks the stack first.
            $send->answers[] = $probe->stack->isAccepting();
            $send->depth--;
        };

        unset($inner, $outer);
        $probe->stack->add($this->entry('root'));
        $probe->stack->finalize();

        self::assertSame(1, $send->deepest, 'no send inside a send');
        self::assertSame([false, false, false], $send->answers, 'no entry is taken while the adapter sends');
        self::assertSame(3, $probe->calls, 'Inner, Outer and the root, each once');
        self::assertSame([['Inner', ['i']], ['Outer', ['o']], [null, ['root']]], $this->summary($probe));
    }

    private function console(int $maxEntries = 500): StackProbe
    {
        $probe = new StackProbe($maxEntries, root: BatchType::Console);
        $probe->stack->setRoute('worker/run');

        return $probe;
    }

    /**
     * `[job name, queries]` of each batch sent, in order.
     *
     * @return list<array{?string, list<string>}>
     */
    private function summary(StackProbe $probe): array
    {
        return array_map(static fn(QueryBatch $b): array => [
            $b->job?->name,
            array_map(static fn(QueryEntry $e): string => (string) $e->query, $b->queries),
        ], $probe->batches);
    }

    private function entry(string $query): QueryEntry
    {
        return QueryEntry::success('mysql', 'db', 'select', $query, 1.0, []);
    }
}
