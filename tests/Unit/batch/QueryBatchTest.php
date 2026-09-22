<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\batch;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use PHPUnit\Framework\TestCase;

/**
 * YQM-2: spec 02 §1–§3, spec 03 §1.
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
            ['v', 'app', 'type', 'id', 'seq', 'module', 'controller', 'action', 'ts', 'host', 'dropped', 'queries'],
            array_keys($this->specExample()->toArray()),
        );
    }

    public function testEntryKeysFollowSpecOrder(): void
    {
        $success = QueryEntry::success('mysql', 'db', 'select', 'SELECT ?', 1.0);
        $error = QueryEntry::error('pgsql', 'db', 'insert', null, 1.0, '23505');

        self::assertSame(['db', 'conn', 'op', 'query', 'time_ms', 'result'], array_keys($success->toArray()));
        self::assertSame(['db', 'conn', 'op', 'query', 'time_ms', 'result', 'error'], array_keys($error->toArray()));
    }

    public function testErrorKeyOnlyForErrorResult(): void
    {
        $json = $this->batch([QueryEntry::success('mysql', 'db', 'select', 'SELECT ?', 1.5)])->toJson();

        self::assertStringNotContainsString('"error"', $json);
        self::assertStringContainsString('"result":"success"', $json);
    }

    public function testErrorEntryCarriesCodeAsString(): void
    {
        $json = $this->batch([QueryEntry::error('mongodb', 'mongodb', 'insert', 'contacts n:?', 0.4, '11000')])->toJson();

        self::assertStringContainsString('"db":"mongodb","conn":"mongodb","op":"insert","query":"contacts n:?","time_ms":0.4,"result":"error","error":"11000"', $json);
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
            QueryEntry::success('mysql', 'db', 'select', null, 2.0),
            QueryEntry::success('mysql', 'db', 'select', null, 1.23456789),
        ])->toJson();

        self::assertStringContainsString('"time_ms":2.0,', $json);
        self::assertStringContainsString('"time_ms":1.23456789,', $json);
    }

    public function testNullHeaderFieldsAndEmptyQueries(): void
    {
        $batch = new QueryBatch('app', BatchType::Console, 'id1', 7, null, null, null, new \DateTimeImmutable('2026-09-22T09:41:05.000Z'), 'h', 3, []);

        self::assertSame(
            '{"v":1,"app":"app","type":"console","id":"id1","seq":7,"module":null,"controller":null,"action":null,"ts":"2026-09-22T09:41:05.000Z","host":"h","dropped":3,"queries":[]}',
            $batch->toJson(),
        );
    }

    public function testUnicodeAndSlashesAreNotEscaped(): void
    {
        $json = $this->batch([QueryEntry::success('pgsql', 'db', 'select', 'SELECT "zażółć" FROM a/b', 1.0)])->toJson();

        self::assertStringContainsString('SELECT \"zażółć\" FROM a/b', $json);
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
            'admin/orders',
            'order',
            'view',
            new \DateTimeImmutable('2026-09-22T09:41:05.312Z'),
            'web-03',
            0,
            [
                QueryEntry::success('mysql', 'db', 'select', 'SELECT * FROM `order` WHERE `id` = :qp0', 2.1),
                QueryEntry::error('mysql', 'db', 'insert', 'INSERT INTO `audit_log` (`order_id`, `action`) VALUES (:qp0, :qp1)', 0.9, '23000'),
                QueryEntry::success('mongodb', 'mongodb', 'find', 'contacts filter{externalId:?,tenantId:?} sort{updatedAt:?} limit:?', 1.3),
                QueryEntry::success('mysql', 'db', 'select', null, 0.7),
            ],
        );
    }

    /**
     * @param list<QueryEntry> $queries
     */
    private function batch(array $queries, ?\DateTimeImmutable $ts = null): QueryBatch
    {
        return new QueryBatch('app', BatchType::Http, 'id', 1, null, null, null, $ts ?? new \DateTimeImmutable('2026-09-22T09:41:05.312Z'), 'host', 0, $queries);
    }
}
