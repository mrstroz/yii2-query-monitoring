<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\context;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\batch\SampleReason;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\context\BatchSampling;
use mrstroz\querymonitoring\context\UserSource;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use mrstroz\querymonitoring\tests\Unit\StackProbe;

/**
 * YQM-60, spec 01 §5.7, spec 02 §5: the stack reads `user` for every batch it sends, after sampling kept it and
 * with intake paused, and the context reserves its width so the batch stays within `maxBatchBytes`.
 */
final class ContextStackUserTest extends LoggedTestCase
{
    /** @var list<QueryBatch> batches the source was called with */
    private array $seen = [];

    /** the stack a source adds to while it is read */
    private ?StackProbe $probe = null;

    public function testWithoutTheSettingEveryBatchHasUserNull(): void
    {
        $probe = $this->console(null, maxEntries: 2);

        foreach (['q1', 'q2', 'q3'] as $query) {
            $probe->stack->add($this->entry($query));
        }
        $probe->stack->finalize();

        self::assertCount(2, $probe->batches);
        foreach ($probe->batches as $batch) {
            self::assertNull($batch->user);
            self::assertArrayHasKey('user', $batch->toArray());
        }
    }

    public function testSourceIsCalledOnceForEverySentBatchWithItsOwnBatch(): void
    {
        $probe = $this->console($this->recording('u-7'), maxEntries: 2);

        foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $query) {
            $probe->stack->add($this->entry($query));
        }
        $probe->stack->finalize();

        self::assertSame([1, 2, 3], array_map(static fn(QueryBatch $b): int => $b->seq, $this->seen));
        self::assertSame(['u-7', 'u-7', 'u-7'], array_map(static fn(QueryBatch $b): ?string => $b->user, $probe->batches));
        foreach ($this->seen as $i => $given) {
            self::assertNull($given->user, 'the source gets the batch before its user is set');
            self::assertSame($given->toArray(), $probe->batches[$i]->withUser(null)->toArray(), 'and nothing else changes');
        }
        self::assertSame([], $this->errors());
    }

    public function testJobBatchesAreReadWithTheirOwnHeader(): void
    {
        $probe = $this->console($this->recording('u-7'));
        $job = $probe->stack->beginJob(JobInfo::create('app\jobs\Mail', 'queue', 9, 1), 'app\jobs\Mail');
        self::assertNotNull($job);

        $probe->stack->add($this->entry('in job'));
        $probe->stack->end($job);
        $probe->stack->add($this->entry('after job'));
        $probe->stack->finalize();

        self::assertSame([BatchType::Job, BatchType::Console], array_map(static fn(QueryBatch $b): BatchType => $b->type, $this->seen));
        self::assertSame('app\jobs\Mail', $this->seen[0]->job?->name);
        self::assertSame(['u-7', 'u-7'], array_map(static fn(QueryBatch $b): ?string => $b->user, $probe->batches));
    }

    public function testSourceIsNotCalledForABatchSkippedBySamplingAndSeesTheSampleOfAKeptOne(): void
    {
        $sampling = new BatchSampling(0.5, keepErrors: true, draw: static fn(QueryBatch $b): float => [1 => 0.9, 2 => 0.1, 3 => 0.9][$b->seq]);
        $probe = $this->console($this->recording('u-7'), maxEntries: 1, sampling: $sampling);

        $probe->stack->add($this->entry('skipped'));
        $probe->stack->add($this->entry('drawn'));
        $probe->stack->add(QueryEntry::error('mysql', 'db', 'select', 'kept for an error', 1.0, '42S02', []));
        $probe->stack->finalize();

        self::assertSame([2, 3], array_map(static fn(QueryBatch $b): int => $b->seq, $this->seen));
        self::assertSame([SampleReason::Sample, SampleReason::Error], array_map(static fn(QueryBatch $b): ?SampleReason => $b->sample?->reason, $this->seen));
        self::assertSame([2, 3], array_map(static fn(QueryBatch $b): int => $b->seq, $probe->batches));
    }

    public function testQueriesOfTheSourceAreNotEntries(): void
    {
        $source = UserSource::fromConfig(function (QueryBatch $batch): string {
            $this->probe?->stack->add($this->entry('run by the source'));

            return 'u-7';
        }, new Guard());
        $probe = $this->probe = $this->console($source, maxEntries: 2);

        foreach (['q1', 'q2', 'q3'] as $query) {
            $probe->stack->add($this->entry($query));
        }
        $probe->stack->finalize();

        self::assertSame([['q1', 'q2'], ['q3']], $this->queries($probe));
        self::assertSame(['u-7', 'u-7'], array_map(static fn(QueryBatch $b): ?string => $b->user, $probe->batches));
    }

    public function testFailingSourceLeavesUserNullAndTheBatchIsSent(): void
    {
        $source = UserSource::fromConfig(static function (): never {
            throw new \RuntimeException('source failed');
        }, new Guard());
        $probe = $this->console($source, maxEntries: 1);
        $probe->failOnCall = 2;

        foreach (['q1', 'q2', 'q3'] as $query) {
            $probe->stack->add($this->entry($query));
        }
        $probe->stack->finalize();

        self::assertSame([['q1'], ['q3']], $this->queries($probe));
        self::assertSame([null, null], array_map(static fn(QueryBatch $b): ?string => $b->user, $probe->batches));
        self::assertSame(
            ['Query monitoring failed in user with RuntimeException', 'Query monitoring failed in send with RuntimeException'],
            array_column($this->errors(), 0),
            'the failing source does not take the log entry of the failing adapter',
        );
    }

    /**
     * spec 02 §5: the stack sets `user` after the context filled the batch, so the context reserves its width.
     * Consecutive limits over the width of one entry make some batch end within a byte of the limit.
     */
    public function testBatchWithTheWidestUserStaysWithinMaxBatchBytesAtEveryLimit(): void
    {
        $entryBytes = strlen(json_encode($this->entry('q0000')->toArray(), QueryBatch::JSON_FLAGS)) + 1;
        $sampling = new BatchSampling(0.12345678901234568, draw: static fn(QueryBatch $b): float => 0.0);
        foreach ([null, $sampling] as $withSampling) {
            for ($max = 1500; $max <= 1500 + $entryBytes; $max++) {
                $probe = $this->console($this->widest(), maxBatchBytes: $max, sampling: $withSampling);

                for ($i = 0; $i < 60; $i++) {
                    $probe->stack->add($this->entry(sprintf('q%04d', $i)));
                }
                $probe->stack->finalize();

                self::assertGreaterThan(1, count($probe->batches), "limit {$max} splits");
                foreach ($probe->batches as $batch) {
                    self::assertSame(UserSource::widest(), $batch->user);
                    self::assertLessThanOrEqual($max, strlen($batch->toJson()), "limit {$max}, seq {$batch->seq}");
                }
                self::assertSame(60, count($probe->entries()), "limit {$max}: nothing lost to the reservation");
            }
        }
    }

    public function testHttpBatchWithTheWidestUserStaysWithinMaxBatchBytesAtEveryLimit(): void
    {
        $entryBytes = strlen(json_encode($this->entry('q0000')->toArray(), QueryBatch::JSON_FLAGS)) + 1;
        for ($max = 1500; $max <= 1500 + $entryBytes; $max++) {
            $probe = new StackProbe(QueryCollector::DEFAULT_MAX_ENTRIES, $max, user: $this->widest());

            for ($i = 0; $i < 60; $i++) {
                $probe->stack->add($this->entry(sprintf('q%04d', $i)));
            }
            $probe->stack->finalize();

            self::assertCount(1, $probe->batches);
            self::assertGreaterThan(0, $probe->batches[0]->dropped, "limit {$max} reached");
            self::assertSame(UserSource::widest(), $probe->batches[0]->user);
            self::assertLessThanOrEqual($max, strlen($probe->batches[0]->toJson()), "limit {$max}");
        }
    }

    private function recording(string $user): UserSource
    {
        $source = UserSource::fromConfig(function (QueryBatch $batch) use ($user): string {
            $this->seen[] = $batch;

            return $user;
        }, new Guard());
        assert($source !== null);

        return $source;
    }

    private function widest(): UserSource
    {
        $source = UserSource::fromConfig(static fn(): string => UserSource::widest(), new Guard());
        assert($source !== null);

        return $source;
    }

    private function console(
        ?UserSource $user,
        int $maxEntries = QueryCollector::DEFAULT_MAX_ENTRIES,
        int $maxBatchBytes = QueryCollector::DEFAULT_MAX_BATCH_BYTES,
        ?BatchSampling $sampling = null,
    ): StackProbe {
        $probe = new StackProbe($maxEntries, $maxBatchBytes, BatchType::Console, sampling: $sampling, user: $user);
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
