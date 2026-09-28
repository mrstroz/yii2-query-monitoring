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
 * spec 01 §5.2 and §5.4, spec 02 §5: the route set after entries makes the header longer. A splitting context whose
 * buffer no longer fits sends a batch that already carries the route and moves the entries that do not fit to the
 * next batch, without losing them or counting them in `dropped`.
 */
final class ContextStackRouteTest extends LoggedTestCase
{
    private const LIMIT = 1500;

    public function testConsoleRootFilledBeforeItsRouteLosesNoEntryWhenTheRouteArrives(): void
    {
        $route = 'import/' . str_repeat('r', 400);
        $count = $this->rootCapacity();
        $probe = new StackProbe(maxBatchBytes: self::LIMIT, root: BatchType::Console);
        for ($i = 1; $i <= $count; $i++) {
            $probe->stack->add($this->entry($i));
        }
        self::assertCount(0, $probe->batches, 'nothing is sent before the route');

        $probe->stack->setRoute($route);
        $probe->stack->finalize();

        $this->assertNothingLost($probe, $count, $route);
        self::assertGreaterThan(1, count($probe->batches), 'the longer header did not fit, the entries went on to the next batch');
    }

    public function testJobOpenedBeforeTheRouteLosesNoEntryWhenTheRouteArrives(): void
    {
        $route = 'import/' . str_repeat('r', 400);
        $count = $this->jobCapacity();
        $probe = new StackProbe(maxBatchBytes: self::LIMIT, root: BatchType::Console);
        $job = $probe->stack->beginJob(JobInfo::create('app\jobs\Early'), 'app\jobs\Early');
        self::assertNotNull($job);
        for ($i = 1; $i <= $count; $i++) {
            $probe->stack->add($this->entry($i));
        }
        self::assertCount(0, $probe->batches, 'the job buffer is full to the limit but has not been sent');

        $probe->stack->setRoute($route);
        $probe->stack->end($job);

        $this->assertNothingLost($probe, $count, $route);
        self::assertSame(['app\jobs\Early'], array_values(array_unique(array_map(static fn(QueryBatch $b): ?string => $b->job?->name, $probe->batches))));
    }

    public function testRouteLongerThanABatchDropsTheEntriesAndSendsNoEmptyBatch(): void
    {
        $probe = new StackProbe(maxBatchBytes: self::LIMIT, root: BatchType::Console);
        $probe->stack->add($this->entry(1));
        $probe->stack->add($this->entry(2));

        $probe->stack->setRoute('import/' . str_repeat('r', self::LIMIT));
        $probe->stack->finalize();

        self::assertNotSame([], $probe->batches, 'the loss is reported');
        foreach ($probe->batches as $batch) {
            self::assertSame([], $batch->queries, 'no entry fits next to such a header');
            self::assertGreaterThan(0, $batch->dropped, 'no batch with nothing in it');
        }
        self::assertSame(2, array_sum(array_map(static fn(QueryBatch $b): int => $b->dropped, $probe->batches)), 'both entries counted once');
    }

    private function assertNothingLost(StackProbe $probe, int $count, string $route): void
    {
        $numbers = [];
        foreach ($probe->batches as $batch) {
            self::assertLessThanOrEqual(self::LIMIT, strlen($batch->toJson()), "batch seq {$batch->seq} within maxBatchBytes");
            self::assertSame($route, $batch->route, "batch seq {$batch->seq} carries the route");
            self::assertSame(0, $batch->dropped, "batch seq {$batch->seq}: nothing dropped");
            foreach ($batch->queries as $entry) {
                $numbers[] = (int) substr((string) $entry->query, 2, 3);
            }
        }
        self::assertSame(range(1, $count), $numbers, 'every entry once, in order');
    }

    /**
     * How many entries fit in a console root before its route is known: after that it only counts `dropped`.
     */
    private function rootCapacity(): int
    {
        $probe = new StackProbe(maxBatchBytes: self::LIMIT, root: BatchType::Console);
        for ($i = 1; $i <= 100; $i++) {
            $probe->stack->add($this->entry($i));
        }
        $probe->stack->finalize();
        self::assertGreaterThan(0, $probe->batches[0]->dropped, 'the probe reached the limit');

        return count($probe->batches[0]->queries);
    }

    /**
     * How many entries a job holds before the next one makes it send.
     */
    private function jobCapacity(): int
    {
        $probe = new StackProbe(maxBatchBytes: self::LIMIT, root: BatchType::Console);
        $probe->stack->beginJob(JobInfo::create('app\jobs\Early'), 'app\jobs\Early');
        for ($i = 1; $i <= 100 && $probe->batches === []; $i++) {
            $probe->stack->add($this->entry($i));
        }
        self::assertNotSame([], $probe->batches, 'the probe reached the limit');

        return count($probe->batches[0]->queries);
    }

    private function entry(int $i): QueryEntry
    {
        return QueryEntry::success('mysql', 'db', 'select', sprintf('q-%03d SELECT ? FROM t', $i), 1.0, []);
    }
}
