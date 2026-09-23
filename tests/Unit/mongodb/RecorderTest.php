<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\mongodb;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\mongodb\MongoDbNormalizer;
use mrstroz\querymonitoring\mongodb\Recorder;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-35, spec 01 §3: pairing of the driver's start and end events by `requestId`, without the driver.
 */
final class RecorderTest extends LoggedTestCase
{
    private int $documentsRead = 0;

    private int $repliesRead = 0;

    public function testPairOfEventsGivesOneEntry(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('7', 'find', $this->document(['find' => 'contacts', 'filter' => (object) ['email' => 'alice@example.com']]));
        $recorder->succeeded('7', 1235, $this->reply([]));

        $queries = $collector->close(new \DateTimeImmutable())->queries;
        self::assertCount(1, $queries);
        self::assertSame(['mongodb', 'mongodb', 'find', 'contacts filter{email:?}', 1.235, 'success', null], [$queries[0]->db, $queries[0]->conn, $queries[0]->op, $queries[0]->query, $queries[0]->timeMs, $queries[0]->result, $queries[0]->error]);
    }

    public function testFailedCommandGivesErrorEntryWithCode(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('3', 'find', $this->document(['find' => 'c', 'filter' => (object) ['$bad' => 1]]));
        $recorder->failed('3', 400, '2');

        $entry = $collector->close(new \DateTimeImmutable())->queries[0];
        self::assertSame([QueryEntry::RESULT_ERROR, '2', 0.4], [$entry->result, $entry->error, $entry->timeMs]);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, ?string}>
     */
    public static function provideWriteReplyCases(): iterable
    {
        $writeError = (object) ['index' => 0, 'code' => 11000, 'errmsg' => 'E11000 duplicate key dup key: { _id: "alice@example.com" }'];
        $concernError = (object) ['code' => 64, 'errmsg' => 'waiting for replication timed out'];
        yield 'write error' => ['insert', ['n' => 0, 'writeErrors' => [$writeError], 'ok' => 1.0], '11000'];
        yield 'write concern error' => ['update', ['n' => 1, 'writeConcernError' => $concernError, 'ok' => 1.0], '64'];
        yield 'both: write error wins' => ['delete', ['n' => 0, 'writeErrors' => [$writeError], 'writeConcernError' => $concernError, 'ok' => 1.0], '11000'];
        yield 'write concern error of findAndModify' => ['findAndModify', ['value' => null, 'writeConcernError' => $concernError, 'ok' => 1.0], '64'];
        yield 'empty write errors' => ['insert', ['n' => 1, 'writeErrors' => [], 'ok' => 1.0], null];
        yield 'clean write' => ['insert', ['n' => 1, 'ok' => 1.0], null];
        yield 'code that is not a number' => ['insert', ['n' => 0, 'writeErrors' => [(object) ['code' => 'x']], 'ok' => 1.0], null];
    }

    /**
     * @param array<string, mixed> $reply
     */
    #[DataProvider('provideWriteReplyCases')]
    public function testWriteReplyGivesItsErrorCode(string $command, array $reply, ?string $expected): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('1', $command, $this->document([$command => 'c']));
        $recorder->succeeded('1', 1000, $this->reply($reply));

        $entry = $collector->close(new \DateTimeImmutable())->queries[0];
        self::assertSame([$expected === null ? QueryEntry::RESULT_SUCCESS : QueryEntry::RESULT_ERROR, $expected], [$entry->result, $entry->error]);
        self::assertStringNotContainsString('alice', (string) json_encode($entry->toArray()), 'only the code leaves the reply');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideReplyNotReadCases(): iterable
    {
        yield 'find' => ['find'];
        yield 'getMore' => ['getMore'];
        yield 'aggregate' => ['aggregate'];
    }

    #[DataProvider('provideReplyNotReadCases')]
    public function testReplyOfCommandsWithResultDocumentsIsNotRead(string $command): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('1', $command, $this->document([$command => 'c']));
        $recorder->succeeded('1', 1000, $this->reply(['writeConcernError' => (object) ['code' => 64], 'ok' => 1.0]));

        self::assertSame(0, $this->repliesRead);
        self::assertSame(QueryEntry::RESULT_SUCCESS, $collector->close(new \DateTimeImmutable())->queries[0]->result);
    }

    public function testInterleavedCommandsArePairedByRequestId(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('1', 'find', $this->document(['find' => 'a']));
        $recorder->started('2', 'count', $this->document(['count' => 'b']));
        $recorder->succeeded('2', 1000, $this->reply([]));
        $recorder->succeeded('1', 2000, $this->reply([]));

        $queries = $collector->close(new \DateTimeImmutable())->queries;
        self::assertSame([['count', 'b', 1.0], ['find', 'a', 2.0]], array_map(static fn(QueryEntry $e): array => [$e->op, $e->query, $e->timeMs], $queries));
    }

    public function testEndWithoutStartGivesNothing(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->succeeded('9', 1000, $this->reply([]));
        $recorder->failed('10', 1000, '2');

        self::assertSame([0, 0], [$collector->count(), $collector->dropped()]);
    }

    public function testEndRemovesTheState(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('1', 'find', $this->document(['find' => 'a']));
        $recorder->succeeded('1', 1000, $this->reply([]));
        $recorder->succeeded('1', 1000, $this->reply([]));
        $recorder->failed('1', 1000, '2');

        self::assertSame(1, $collector->count());
    }

    public function testCommandNotDescribedByNormalisationHasNullQuery(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('1', 'createIndexes', $this->document(['createIndexes' => 'a', 'indexes' => []]));
        $recorder->succeeded('1', 1000, $this->reply([]));

        $entry = $collector->close(new \DateTimeImmutable())->queries[0];
        self::assertSame(['createIndexes', null], [$entry->op, $entry->query]);
    }

    public function testStartWhileNotAcceptingKeepsNoStateAndReadsNoDocument(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $collector->pause();
        $recorder->started('1', 'find', $this->document(['find' => 'a']));
        $collector->resume();
        $recorder->succeeded('1', 1000, $this->reply([]));

        self::assertSame([0, 0, 0], [$collector->count(), $collector->dropped(), $this->documentsRead]);
    }

    public function testEndAfterFinalisationGivesNothing(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('1', 'find', $this->document(['find' => 'a']));
        $batch = $collector->close(new \DateTimeImmutable());
        $recorder->succeeded('1', 1000, $this->reply([]));

        self::assertSame([], $batch->queries);
        self::assertSame(0, $collector->dropped());
    }

    public function testFullBatchAtStartCountsDroppedOnceWithoutReadingTheDocument(): void
    {
        $collector = $this->fullCollector();
        $recorder = $this->recorder($collector);

        $recorder->started('1', 'find', $this->document(['find' => 'a']));
        $recorder->succeeded('1', 1000, $this->reply([]));

        self::assertSame([1, 1, 0], [$collector->count(), $collector->dropped(), $this->documentsRead]);
    }

    public function testBatchFilledBetweenStartAndEndCountsDroppedAtTheEnd(): void
    {
        $collector = new QueryCollector('app', BatchType::Http, 'req_1', 'host', maxEntries: 1);
        $recorder = $this->recorder($collector);

        $recorder->started('1', 'find', $this->document(['find' => 'a']));
        $recorder->started('2', 'find', $this->document(['find' => 'b']));
        $recorder->succeeded('2', 1000, $this->reply([]));
        $recorder->succeeded('1', 1000, $this->reply([]));
        $recorder->succeeded('1', 1000, $this->reply([]));

        self::assertSame([1, 1], [$collector->count(), $collector->dropped()], 'the end event removed the state before counting it, so a repeated end counts nothing');
    }

    public function testEndWhilePausedRemovesTheState(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('1', 'find', $this->document(['find' => 'a']));
        $collector->pause();
        $recorder->succeeded('1', 1000, $this->reply([]));
        $collector->resume();
        $recorder->succeeded('1', 1000, $this->reply([]));

        self::assertSame(0, $collector->count(), 'the command ended while the adapter sent; no state is left for a later event');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEndEventCases(): iterable
    {
        yield 'succeeded' => ['succeeded'];
        yield 'failed' => ['failed'];
    }

    #[DataProvider('provideEndEventCases')]
    public function testFailureAtTheEndEventIsSwallowedWithOnePackageError(string $event): void
    {
        $collector = $this->collector();
        // uninitialised readonly properties: frames() throws \Error
        $callers = (new \ReflectionClass(CallerFrames::class))->newInstanceWithoutConstructor();
        $recorder = new Recorder('mongodb', new MongoDbNormalizer(8192), $collector, new Guard(), $callers);

        $recorder->started('1', 'find', $this->document(['find' => 'a']));
        $event === 'succeeded' ? $recorder->succeeded('1', 1000, $this->reply([])) : $recorder->failed('1', 1000, '2');

        self::assertSame(0, $collector->count());
        self::assertCount(1, $this->errors());
    }

    public function testFailingNormaliserKeepsNoStateAndLogsOnce(): void
    {
        $collector = $this->collector();
        // uninitialised readonly maxQueryLength: normalize() throws \Error
        $broken = (new \ReflectionClass(MongoDbNormalizer::class))->newInstanceWithoutConstructor();
        $recorder = $this->recorder($collector, $broken);

        $recorder->started('1', 'find', $this->document(['find' => 'a', 'filter' => (object) ['email' => 'alice@example.com']]));
        $recorder->succeeded('1', 1000, $this->reply([]));
        $recorder->started('2', 'find', $this->document(['find' => 'a']));

        self::assertSame([0, 0], [$collector->count(), $collector->dropped()]);
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame(Guard::LOG_CATEGORY, $errors[0][2]);
        self::assertStringNotContainsString('alice', (string) $errors[0][0]);
    }

    public function testFailingDocumentReadIsSwallowed(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('1', 'find', static fn(): object => throw new \RuntimeException('BSON'));
        $recorder->succeeded('1', 1000, $this->reply([]));

        self::assertSame(0, $collector->count());
        self::assertCount(1, $this->errors());
    }

    public function testCallerIsTakenAtTheEndEvent(): void
    {
        $collector = $this->collector();
        $recorder = $this->recorder($collector);

        $recorder->started('1', 'find', $this->document(['find' => 'a']));
        $line = __LINE__ + 1;
        $recorder->succeeded('1', 1000, $this->reply([]));

        $caller = $collector->close(new \DateTimeImmutable())->queries[0]->caller;
        self::assertSame('tests/Unit/mongodb/RecorderTest.php:' . $line, $caller[0]);
    }

    /**
     * @param array<string, mixed> $command
     *
     * @return \Closure(): object
     */
    private function document(array $command): \Closure
    {
        return function () use ($command): object {
            $this->documentsRead++;

            return (object) $command;
        };
    }

    /**
     * @param array<string, mixed> $reply
     *
     * @return \Closure(): object
     */
    private function reply(array $reply): \Closure
    {
        return function () use ($reply): object {
            $this->repliesRead++;

            return (object) $reply;
        };
    }

    private function recorder(QueryCollector $collector, ?MongoDbNormalizer $normalizer = null): Recorder
    {
        $root = dirname(__DIR__, 3);

        return new Recorder('mongodb', $normalizer ?? new MongoDbNormalizer(8192), $collector, new Guard(), new CallerFrames($root, $root . '/vendor', $root . '/src', null));
    }

    private function collector(): QueryCollector
    {
        return new QueryCollector('app', BatchType::Http, 'req_1', 'host');
    }

    private function fullCollector(): QueryCollector
    {
        $collector = new QueryCollector('app', BatchType::Http, 'req_1', 'host', maxEntries: 1);
        $collector->add(QueryEntry::success('mongodb', 'mongodb', 'find', 'a', 1.0, []));

        return $collector;
    }
}
