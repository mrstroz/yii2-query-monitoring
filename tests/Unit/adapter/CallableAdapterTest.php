<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\adapter;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\adapter\CallableAdapter;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use PHPUnit\Framework\TestCase;

/**
 * spec 03 §1: a callable with one QueryBatch argument is a valid adapter.
 */
final class CallableAdapterTest extends TestCase
{
    public function testCallbackReceivesTheBatchOnce(): void
    {
        $batch = new QueryBatch('app', BatchType::Http, 'id', 1, null, null, null, new \DateTimeImmutable('2026-09-22T09:41:05Z'), 'h', 0, []);
        $received = [];
        $adapter = new CallableAdapter(static function (QueryBatch $b) use (&$received): string {
            $received[] = $b;

            return 'ignored';
        });

        $adapter->send($batch);

        self::assertInstanceOf(BatchAdapterInterface::class, $adapter);
        self::assertCount(1, $received);
        self::assertSame($batch, $received[0]);
    }

    public function testExceptionOfCallbackIsNotHiddenByTheAdapter(): void
    {
        $adapter = new CallableAdapter(static function (): never {
            throw new \RuntimeException('adapter failed');
        });

        $this->expectException(\RuntimeException::class);

        $adapter->send(new QueryBatch('app', BatchType::Http, 'id', 1, null, null, null, new \DateTimeImmutable(), 'h', 0, []));
    }
}
