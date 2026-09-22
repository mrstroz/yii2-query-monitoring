<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration;

use mrstroz\querymonitoring\tests\app\AppRunner;
use mrstroz\querymonitoring\tests\app\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-12: the test application runs in a separate PHP process, the capturing adapter hands the
 * batch back, and the Active Record schema is created by the test.
 */
final class TestApplicationTest extends IntegrationTestCase
{
    #[DataProvider('databases')]
    public function testSchemaIsCreatedAndCreationIsIdempotent(string $db): void
    {
        $this->requireDatabase($db);
        $connection = Schema::connection($db);

        Schema::create($connection);
        Schema::create($connection);

        self::assertIsNumeric($connection->createCommand('SELECT COUNT(*) FROM qm_order')->queryScalar());
        $connection->close();
    }

    #[DataProvider('databases')]
    public function testApplicationRunsInAnotherProcess(string $db): void
    {
        $out = $this->scenarioOutput($this->scenario($db, 'process'));

        self::assertNotSame(getmypid(), $out['pid']);
    }

    #[DataProvider('databases')]
    public function testActiveRecordRequestGivesBatchWithoutValues(string $db): void
    {
        $this->requireDatabase($db);
        Schema::create(Schema::connection($db));

        $result = AppRunner::run(self::defaultComponent(), 'order/index', ['QM_DB' => $db]);
        $this->assertProcessOk($result);
        $response = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(1, $response['count']);
        $this->assertNoErrors($result);

        $batch = $this->singleBatch($result);
        $orders = $this->entriesWith($batch, 'qm_order');
        $ops = array_column($orders, 'op');
        self::assertContains('insert', $ops);
        self::assertContains('select', $ops);
        foreach ($orders as $entry) {
            self::assertSame('db', $entry['conn']);
            self::assertSame($db, $entry['db']);
            self::assertSame('success', $entry['result']);
        }

        $json = (string) json_encode($batch);
        self::assertStringNotContainsString('alice@example.com', $json);
        self::assertStringNotContainsString('12.5', $json);
    }

    #[DataProvider('databases')]
    public function testModuleRouteUsesModuleConnection(string $db): void
    {
        $this->requireDatabase($db);
        Schema::create(Schema::connection($db));

        $result = AppRunner::run(self::defaultComponent(), 'admin/order/index', ['QM_DB' => $db]);

        $this->assertProcessOk($result);
        $orders = $this->entriesWith($this->singleBatch($result), 'qm_order');
        self::assertNotEmpty($orders);
        self::assertSame(['admin/db'], array_values(array_unique(array_column($orders, 'conn'))));
    }

    #[DataProvider('databases')]
    public function testRouteWithoutQueriesGivesNoBatch(string $db): void
    {
        $this->requireDatabase($db);

        $result = AppRunner::run(self::defaultComponent(), 'order/none', ['QM_DB' => $db]);

        $this->assertProcessOk($result);
        self::assertSame([], $result->batches);
    }
}
