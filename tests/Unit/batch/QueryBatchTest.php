<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\batch;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\JobInfo;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\batch\Sample;
use mrstroz\querymonitoring\batch\SampleReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * YQM-2, YQM-49 and YQM-58: spec 02 §1–§3, spec 03 §1.
 */
final class QueryBatchTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/spec-02-3-batch.json';

    public function testExampleFromSpecIsProducedByteForByte(): void
    {
        $expected = file_get_contents(self::FIXTURE);
        self::assertIsString($expected);

        self::assertSame($expected, $this->specExample()->toJson());
    }

    public function testJsonIsOneLine(): void
    {
        self::assertStringNotContainsString("\n", $this->specExample()->toJson());
    }

    public function testHeaderKeysFollowSpecOrder(): void
    {
        self::assertSame(
            ['v', 'app', 'type', 'id', 'seq', 'route', 'job', 'ts', 'host', 'user', 'dropped', 'sample', 'queries'],
            array_keys($this->specExample()->toArray()),
        );
    }

    public function testEntryKeysFollowSpecOrder(): void
    {
        $success = QueryEntry::success('mysql', 'db', 'select', 'SELECT ?', 1.0, []);
        $error = QueryEntry::error('pgsql', 'db', 'insert', null, 1.0, '23505', ['models/Order.php:12']);

        self::assertSame(['db', 'conn', 'op', 'query', 'time_ms', 'result', 'caller'], array_keys($success->toArray()));
        self::assertSame(['db', 'conn', 'op', 'query', 'time_ms', 'result', 'error', 'caller'], array_keys($error->toArray()));
    }

    public function testErrorKeyOnlyForErrorResult(): void
    {
        $json = $this->batch([QueryEntry::success('mysql', 'db', 'select', 'SELECT ?', 1.5, [])])->toJson();

        self::assertStringNotContainsString('"error"', $json);
        self::assertStringContainsString('"result":"success"', $json);
    }

    public function testErrorEntryCarriesCodeAsString(): void
    {
        $json = $this->batch([QueryEntry::error('mongodb', 'mongodb', 'insert', 'contacts n:?', 0.4, '11000', [])])->toJson();

        self::assertStringContainsString('"db":"mongodb","conn":"mongodb","op":"insert","query":"contacts n:?","time_ms":0.4,"result":"error","error":"11000","caller":[]', $json);
    }

    public function testTimestampIsConvertedToUtcWithMilliseconds(): void
    {
        $batch = $this->batch([], new \DateTimeImmutable('2026-09-22T11:41:05.312999+02:00'));

        self::assertSame('2026-09-22T09:41:05.312Z', $batch->toArray()['ts']);
    }

    public function testTimestampPassedInUtcIsUnchanged(): void
    {
        $batch = $this->batch([], new \DateTimeImmutable('2026-01-01T00:00:00.000Z'));

        self::assertSame('2026-01-01T00:00:00.000Z', $batch->toArray()['ts']);
    }

    public function testTimeIsFloatAndNotRounded(): void
    {
        $json = $this->batch([
            QueryEntry::success('mysql', 'db', 'select', null, 2.0, []),
            QueryEntry::success('mysql', 'db', 'select', null, 1.23456789, []),
        ])->toJson();

        self::assertStringContainsString('"time_ms":2.0,', $json);
        self::assertStringContainsString('"time_ms":1.23456789,', $json);
    }

    public function testNullHeaderFieldsAndEmptyQueries(): void
    {
        $batch = new QueryBatch('app', BatchType::Console, 'id1', 7, null, new \DateTimeImmutable('2026-09-22T09:41:05.000Z'), 'h', 3, []);

        self::assertSame(
            '{"v":4,"app":"app","type":"console","id":"id1","seq":7,"route":null,"job":null,"ts":"2026-09-22T09:41:05.000Z","host":"h","user":null,"dropped":3,"sample":null,"queries":[]}',
            $batch->toJson(),
        );
    }

    public function testJobHeaderFromSpecIsProducedByteForByte(): void
    {
        $batch = new QueryBatch(
            'shop-api',
            BatchType::Job,
            '5be0c7d2a8f14e39',
            2,
            'queue/listen',
            new \DateTimeImmutable('2026-09-22T09:41:07.004Z'),
            'worker-01',
            0,
            [],
            JobInfo::create('app\\jobs\\SendInvoice', 'queue', 42, 2),
        );

        self::assertSame(
            '{"v":4,"app":"shop-api","type":"job","id":"5be0c7d2a8f14e39","seq":2,'
            . '"route":"queue/listen","job":{"name":"app\\\\jobs\\\\SendInvoice","queue":"queue","message_id":"42","attempt":2},'
            . '"ts":"2026-09-22T09:41:07.004Z","host":"worker-01","user":null,"dropped":0,"sample":null,"queries":[]}',
            $batch->toJson(),
        );
    }

    public function testSampledJobHeaderFromSpecIsProducedByteForByte(): void
    {
        $batch = new QueryBatch(
            'shop-api',
            BatchType::Job,
            '5be0c7d2a8f14e39',
            2,
            'queue/listen',
            new \DateTimeImmutable('2026-09-22T09:41:07.004Z'),
            'worker-01',
            0,
            [],
            JobInfo::create('app\\jobs\\SendInvoice', 'queue', 42, 2),
            new Sample(0.1, SampleReason::Sample),
        );

        self::assertSame(
            '{"v":4,"app":"shop-api","type":"job","id":"5be0c7d2a8f14e39","seq":2,'
            . '"route":"queue/listen","job":{"name":"app\\\\jobs\\\\SendInvoice","queue":"queue","message_id":"42","attempt":2},'
            . '"ts":"2026-09-22T09:41:07.004Z","host":"worker-01","user":null,"dropped":0,"sample":{"rate":0.1,"reason":"sample"},"queries":[]}',
            $batch->toJson(),
        );
    }

    /**
     * @return iterable<string, array{Sample, string}>
     */
    public static function provideSampleCases(): iterable
    {
        yield 'drawn at ten percent' => [new Sample(0.1, SampleReason::Sample), '{"rate":0.1,"reason":"sample"}'];
        yield 'drawn at an integer rate one' => [new Sample(1, SampleReason::Sample), '{"rate":1.0,"reason":"sample"}'];
        yield 'kept for an error' => [new Sample(1.0, SampleReason::Error), '{"rate":1.0,"reason":"error"}'];
        yield 'kept for a slow query' => [new Sample(1.0, SampleReason::SlowQuery), '{"rate":1.0,"reason":"slow_query"}'];
        yield 'kept for a slow batch' => [new Sample(1.0, SampleReason::SlowBatch), '{"rate":1.0,"reason":"slow_batch"}'];
        yield 'kept for many queries' => [new Sample(1.0, SampleReason::ManyQueries), '{"rate":1.0,"reason":"many_queries"}'];
        yield 'drawn at a long rate' => [new Sample(0.123456789, SampleReason::Sample), '{"rate":0.123456789,"reason":"sample"}'];
    }

    #[DataProvider('provideSampleCases')]
    public function testSampleIsWrittenAfterDroppedWithRateAlwaysAsANumberWithAFraction(Sample $sample, string $json): void
    {
        $batch = $this->batch([QueryEntry::success('mysql', 'db', 'select', 'SELECT ?', 1.5, [])])->withSample($sample);

        self::assertStringContainsString('"dropped":0,"sample":' . $json . ',"queries":[', $batch->toJson());
    }

    public function testWithSampleChangesOnlyTheSample(): void
    {
        $original = $this->specExample();

        $sampled = $original->withSample(new Sample(0.25, SampleReason::Sample));

        self::assertNull($original->sample, 'the original batch is not modified');
        $expected = $original->toArray();
        $expected['sample'] = ['rate' => 0.25, 'reason' => 'sample'];
        self::assertSame($expected, $sampled->toArray());
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function provideUserCases(): iterable
    {
        yield 'none' => [null, '"user":null'];
        yield 'a number as text' => ['42', '"user":"42"'];
        yield 'unicode and a slash, not escaped' => ['zażółć/7', '"user":"zażółć/7"'];
        yield 'a quote, escaped' => ['a"b', '"user":"a\\"b"'];
    }

    #[DataProvider('provideUserCases')]
    public function testUserIsWrittenAfterHostAndBeforeDropped(?string $user, string $json): void
    {
        $batch = $this->batch([])->withUser($user);

        self::assertStringContainsString('"host":"host",' . $json . ',"dropped":0,', $batch->toJson());
        self::assertSame($user, $batch->toArray()['user']);
    }

    public function testWithUserChangesOnlyTheUser(): void
    {
        $original = $this->specExample()->withSample(new Sample(0.25, SampleReason::Sample));

        $named = $original->withUser('u-7');

        self::assertSame('1187', $original->user, 'the original batch is not modified');
        $expected = $original->toArray();
        $expected['user'] = 'u-7';
        self::assertSame($expected, $named->toArray());
        self::assertNull($named->withUser(null)->user);
    }

    /**
     * @return iterable<string, array{string, \Closure(QueryBatch): QueryBatch, \Closure(QueryBatch): void}>
     */
    public static function provideCopyCases(): iterable
    {
        yield 'withSample' => [
            'sample',
            static fn(QueryBatch $batch): QueryBatch => $batch->withSample(new Sample(1.0, SampleReason::Error)),
            static fn(QueryBatch $copy) => self::assertEquals(new Sample(1.0, SampleReason::Error), $copy->sample),
        ];
        yield 'withUser' => [
            'user',
            static fn(QueryBatch $batch): QueryBatch => $batch->withUser('u-7'),
            static fn(QueryBatch $copy) => self::assertSame('u-7', $copy->user),
        ];
    }

    /**
     * Review finding 5: `withSample()` and `withUser()` pass every property as a named argument, so each must be
     * promoted from the constructor. The list below fails first when the constructor changes; the comparison then
     * checks every field.
     *
     * @param \Closure(QueryBatch): QueryBatch $copy
     * @param \Closure(QueryBatch): void $assertChanged
     */
    #[DataProvider('provideCopyCases')]
    public function testCopyKeepsEveryOtherConstructorField(string $changed, \Closure $copy, \Closure $assertChanged): void
    {
        $parameters = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(QueryBatch::class, '__construct'))->getParameters(),
        );
        self::assertSame(
            ['app', 'type', 'id', 'seq', 'route', 'ts', 'host', 'dropped', 'queries', 'job', 'sample', 'user'],
            $parameters,
            'a new header field: set it to a non-default value below and check that both copies keep it',
        );
        $properties = array_map(
            static fn(\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(QueryBatch::class))->getProperties(),
        );
        self::assertSame($parameters, $properties, 'the copies pass every property as a named argument, so each one is promoted');
        $original = new QueryBatch(
            'shop-api',
            BatchType::Job,
            '5be0c7d2a8f14e39',
            7,
            'queue/listen',
            new \DateTimeImmutable('2026-09-22T09:41:07.004Z'),
            'worker-01',
            3,
            [QueryEntry::success('mysql', 'db', 'select', 'SELECT ?', 1.5, [])],
            JobInfo::create('app\\jobs\\SendInvoice', 'queue', 42, 2),
            new Sample(0.5, SampleReason::Sample),
            '1187',
        );

        $result = $copy($original);

        foreach ($parameters as $name) {
            if ($name !== $changed) {
                self::assertSame($original->{$name}, $result->{$name}, $name);
            }
        }
        $assertChanged($result);
    }

    public function testJobWithUnknownMetadataKeepsAllFourKeys(): void
    {
        $batch = new QueryBatch('app', BatchType::Job, 'id1', 1, null, new \DateTimeImmutable('2026-09-22T09:41:05.000Z'), 'h', 0, [], JobInfo::create(''));

        self::assertSame(['name' => 'unknown', 'queue' => null, 'message_id' => null, 'attempt' => null], $batch->toArray()['job']);
    }

    public function testUnicodeAndSlashesAreNotEscaped(): void
    {
        $json = $this->batch([QueryEntry::success('pgsql', 'db', 'select', 'SELECT "zażółć" FROM a/b', 1.0, ['modules/zażółć/a.php:1'])])->toJson();

        self::assertStringContainsString('SELECT \"zażółć\" FROM a/b', $json);
        self::assertStringContainsString('"caller":["modules/zażółć/a.php:1"]', $json);
    }

    public function testAdapterContract(): void
    {
        $method = new \ReflectionMethod(BatchAdapterInterface::class, 'send');
        $params = $method->getParameters();

        self::assertTrue((new \ReflectionClass(BatchAdapterInterface::class))->isInterface());
        self::assertCount(1, $params);
        self::assertSame(QueryBatch::class, (string) $params[0]->getType());
        self::assertSame('void', (string) $method->getReturnType());
    }

    private function specExample(): QueryBatch
    {
        return new QueryBatch(
            'shop-api',
            BatchType::Http,
            'req_9f3a1c2e',
            1,
            'admin/orders/order/view',
            new \DateTimeImmutable('2026-09-22T09:41:05.312Z'),
            'web-03',
            0,
            [
                QueryEntry::success('mysql', 'db', 'select', 'SELECT * FROM `order` WHERE `id` = ?', 2.1, ['modules/admin/modules/orders/controllers/OrderController.php:41']),
                QueryEntry::error('mysql', 'db', 'insert', 'INSERT INTO `audit_log` (`order_id`, `action`) VALUES (?, ?)', 0.9, '23000', ['models/AuditLog.php:27', 'modules/admin/modules/orders/controllers/OrderController.php:44']),
                QueryEntry::success('mongodb', 'mongodb', 'find', 'contacts filter{externalId:?,tenantId:?} sort{updatedAt:?} limit:?', 1.3, ['components/ContactRepository.php:88', 'modules/admin/modules/orders/controllers/OrderController.php:52']),
                QueryEntry::success('mysql', 'db', 'select', null, 0.7, []),
            ],
            user: '1187',
        );
    }

    /**
     * @param list<QueryEntry> $queries
     */
    private function batch(array $queries, ?\DateTimeImmutable $ts = null): QueryBatch
    {
        return new QueryBatch('app', BatchType::Http, 'id', 1, null, $ts ?? new \DateTimeImmutable('2026-09-22T09:41:05.312Z'), 'host', 0, $queries);
    }
}
