<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\context;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\context\RouteExclusions;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use mrstroz\querymonitoring\tests\Unit\StackProbe;

/**
 * YQM-52, spec 01 §5.4: an excluded context skips its entries without `dropped`, also those recorded before its
 * route was known, and sends nothing; a job inside it is judged by its own key; an excluded job's entries do not
 * go to its parent.
 */
final class ContextStackExclusionTest extends LoggedTestCase
{
    public function testExcludedRootDiscardsTheBufferFromBeforeTheRouteAndItsDropped(): void
    {
        $probe = $this->probe(['console' => ['queue/*']], maxEntries: 2);
        for ($i = 1; $i <= 4; $i++) {
            $probe->stack->add($this->entry("boot{$i}"));
        }

        $probe->stack->setRoute('queue/listen');
        $probe->stack->add($this->entry('after'));

        self::assertFalse($probe->stack->isAccepting());
        self::assertFalse($probe->stack->dropIfFull(), 'an excluded query is not a dropped one');
        $probe->stack->finalize();
        self::assertCount(0, $probe->batches);
    }

    public function testRootThatIsNotExcludedKeepsItsBufferFromBeforeTheRoute(): void
    {
        $probe = $this->probe(['console' => ['queue/*']]);
        $probe->stack->add($this->entry('boot'));

        $probe->stack->setRoute('import/run');
        $probe->stack->add($this->entry('after'));
        $probe->stack->finalize();

        self::assertSame([[null, ['boot', 'after']]], $this->summary($probe));
    }

    public function testJobInsideAnExcludedRootIsMonitored(): void
    {
        $probe = $this->probe(['console' => ['queue/*']]);
        $probe->stack->setRoute('queue/listen');
        $probe->stack->add($this->entry('listener'));

        $job = $probe->stack->beginJob(JobInfo::create('A'), 'A');
        $probe->stack->add($this->entry('a1'));
        self::assertNotNull($job);
        $probe->stack->end($job);
        $probe->stack->add($this->entry('listener2'));
        $probe->stack->finalize();

        self::assertSame([['A', ['a1']]], $this->summary($probe));
        self::assertSame('queue/listen', $probe->batches[0]->route);
        self::assertSame(0, $probe->batches[0]->dropped);
    }

    public function testExcludedJobTakesNothingAndGivesNothingToItsParent(): void
    {
        $probe = $this->probe(['job' => ['app\jobs\Noisy']]);
        $probe->stack->setRoute('import/run');
        $probe->stack->add($this->entry('r1'));

        $noisy = $probe->stack->beginJob(JobInfo::create('app\jobs\Noisy'), 'app\jobs\Noisy');
        $probe->stack->add($this->entry('noisy'));
        self::assertFalse($probe->stack->isAccepting());
        self::assertNotNull($noisy);
        $probe->stack->end($noisy);
        $probe->stack->add($this->entry('r2'));
        $probe->stack->finalize();

        self::assertSame([[null, ['r1', 'r2']]], $this->summary($probe));
        self::assertSame(0, $probe->batches[0]->dropped);
    }

    public function testJobInsideAnExcludedJobIsMonitored(): void
    {
        $probe = $this->probe(['job' => ['Outer']]);
        $probe->stack->setRoute('import/run');

        $outer = $probe->stack->beginJob(JobInfo::create('Outer'), 'Outer');
        $probe->stack->add($this->entry('o1'));
        $inner = $probe->stack->beginJob(JobInfo::create('Inner'), 'Inner');
        $probe->stack->add($this->entry('i1'));
        self::assertNotNull($inner);
        self::assertNotNull($outer);
        $probe->stack->end($inner);
        $probe->stack->add($this->entry('o2'));
        $probe->stack->end($outer);

        self::assertSame([['Inner', ['i1']]], $this->summary($probe));
    }

    public function testJobNameIsMatchedBeforeTruncation(): void
    {
        $long = str_repeat('L', 300);
        $probe = $this->probe(['job' => [$long]]);
        $probe->stack->setRoute('import/run');
        $other = str_repeat('L', 299) . 'X';

        $excluded = $probe->stack->beginJob(JobInfo::create($long), $long);
        $probe->stack->add($this->entry('long'));
        self::assertNotNull($excluded);
        $probe->stack->end($excluded);
        $kept = $probe->stack->beginJob(JobInfo::create($other), $other);
        $probe->stack->add($this->entry('other'));
        self::assertNotNull($kept);
        $probe->stack->end($kept);

        self::assertCount(1, $probe->batches);
        self::assertSame(['other'], array_map(static fn(QueryEntry $e): ?string => $e->query, $probe->batches[0]->queries));
    }

    public function testExcludedHttpRootSendsNothing(): void
    {
        $probe = new StackProbe(exclusions: RouteExclusions::fromConfig(['http' => ['health/index']]));
        $probe->stack->add($this->entry('boot'));

        $probe->stack->setRoute('health/index');
        $probe->stack->add($this->entry('action'));
        $probe->stack->finalize();

        self::assertCount(0, $probe->batches);
    }

    public function testRootWhoseRouteIsNeverKnownIsNotExcludedEvenByStar(): void
    {
        $probe = $this->probe(['console' => ['*']]);
        $probe->stack->add($this->entry('boot'));

        $probe->stack->finalize();

        self::assertSame([[null, ['boot']]], $this->summary($probe));
    }

    /**
     * @param array<string, list<string>> $excludedRoutes
     */
    private function probe(array $excludedRoutes, int $maxEntries = 500): StackProbe
    {
        return new StackProbe($maxEntries, root: BatchType::Console, exclusions: RouteExclusions::fromConfig($excludedRoutes));
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
