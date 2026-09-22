<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration;

use mrstroz\querymonitoring\sql\Command;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-4: spec 01 §1 (component and configuration), 02 §1 (header), 01 §4 (a request without entries sends nothing).
 */
final class QueryMonitorComponentTest extends IntegrationTestCase
{
    #[DataProvider('databases')]
    public function testHeader(string $db): void
    {
        $before = time();
        $result = $this->scenario($db, 'per-connection');
        $after = time();

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);

        self::assertSame(1, $batch['v']);
        self::assertSame(self::APP, $batch['app']);
        self::assertSame('http', $batch['type']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $batch['id']);
        self::assertSame(1, $batch['seq']);
        self::assertSame((string) gethostname(), $batch['host']);
        self::assertSame(0, $batch['dropped']);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $batch['ts']);
        $ts = (new \DateTimeImmutable($batch['ts']))->getTimestamp();
        self::assertGreaterThanOrEqual($before - 1, $ts);
        self::assertLessThanOrEqual($after + 1, $ts);
    }

    #[DataProvider('databases')]
    public function testIdIsRandomPerProcess(string $db): void
    {
        $first = $this->singleBatch($this->scenario($db, 'per-connection'));
        $second = $this->singleBatch($this->scenario($db, 'per-connection'));

        self::assertNotSame($first['id'], $second['id']);
    }

    #[DataProvider('databases')]
    public function testOnlyListedConnectionsGiveEntries(string $db): void
    {
        $result = $this->scenario($db, 'per-connection');
        $batch = $this->singleBatch($result);

        self::assertSame(['db' => 101, 'dbOther' => 102, 'admin/db' => 103], array_map('intval', $this->scenarioOutput($result)));

        $onDb = $this->entriesWith($batch, 'qm_on_db');
        self::assertCount(1, $onDb);
        self::assertSame('db', $onDb[0]['conn']);
        self::assertSame($db, $onDb[0]['db']);
        self::assertSame('select', $onDb[0]['op']);
        self::assertSame('SELECT ? AS qm_on_db', $onDb[0]['query']);
        self::assertSame('success', $onDb[0]['result']);

        self::assertSame([], $this->entriesWith($batch, 'qm_on_other'), 'dbOther is not in connections');
        foreach ($batch['queries'] as $entry) {
            self::assertContains($entry['conn'], ['db', 'admin/db']);
        }
    }

    #[DataProvider('databases')]
    public function testModuleConnectionIsFound(string $db): void
    {
        $batch = $this->singleBatch($this->scenario($db, 'per-connection'));

        $onAdmin = $this->entriesWith($batch, 'qm_on_admin');
        self::assertCount(1, $onAdmin);
        self::assertSame('admin/db', $onAdmin[0]['conn']);
    }

    #[DataProvider('databases')]
    public function testListedConnectionsGetMeasuredCommand(string $db): void
    {
        $classes = $this->scenarioOutput($this->scenario($db, 'command-classes'));

        self::assertSame(Command::class, $classes['db']);
        self::assertSame(Command::class, $classes['admin/db']);
        self::assertSame(\yii\db\Command::class, $classes['dbOther']);
    }

    #[DataProvider('databases')]
    public function testDisabledChangesNothingAndSendsNothing(string $db): void
    {
        $classes = $this->scenarioOutput($this->scenario($db, 'command-classes', ['enabled' => false]));
        self::assertSame(['db' => \yii\db\Command::class, 'dbOther' => \yii\db\Command::class, 'admin/db' => \yii\db\Command::class], $classes);

        $result = $this->scenario($db, 'per-connection', ['enabled' => false]);
        $this->assertProcessOk($result);
        self::assertSame([], $result->batches, 'adapter must not be called');
        $this->assertNoErrors($result);
    }

    #[DataProvider('databases')]
    public function testRequestWithoutQueriesSendsNothing(string $db): void
    {
        $result = $this->scenario($db, 'no-queries');

        self::assertSame('nothing', $this->scenarioOutput($result));
        self::assertSame([], $result->batches);
        $this->assertNoErrors($result);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function badConfigurations(): iterable
    {
        foreach (['mysql', 'pgsql'] as $db) {
            yield "{$db}: maxQueryLength below 3" => [$db, ['maxQueryLength' => 2]];
            yield "{$db}: empty app" => [$db, ['app' => '']];
        }
    }

    /**
     * @param array<string, mixed> $component
     */
    #[DataProvider('badConfigurations')]
    public function testBadConfigurationDisablesPackageWithOneError(string $db, array $component): void
    {
        $without = $this->scenario($db, 'per-connection', ['enabled' => false]);
        $result = $this->scenario($db, 'per-connection', $component);

        $this->assertProcessOk($result);
        self::assertSame($without->stdout, $result->stdout, 'the application answers as without the package');
        self::assertSame([], $result->batches);
        $this->assertOnePackageError($result);

        $classes = $this->scenarioOutput($this->scenario($db, 'command-classes', $component));
        self::assertSame(\yii\db\Command::class, $classes['db'], 'no Command replacement when disabled');
    }

    #[DataProvider('databases')]
    public function testUnknownConnectionIsSkippedWithOneError(string $db): void
    {
        $result = $this->scenario($db, 'per-connection', ['connections' => ['db', 'nope', 'admin/db']]);

        $batch = $this->singleBatch($result);
        $this->assertOnePackageError($result);
        self::assertCount(1, $this->entriesWith($batch, 'qm_on_db'));
        self::assertCount(1, $this->entriesWith($batch, 'qm_on_admin'));
    }

    #[DataProvider('databases')]
    public function testUnknownModuleIsSkippedWithOneError(string $db): void
    {
        $result = $this->scenario($db, 'per-connection', ['connections' => ['db', 'nomodule/db']]);

        $batch = $this->singleBatch($result);
        $this->assertOnePackageError($result);
        self::assertCount(1, $this->entriesWith($batch, 'qm_on_db'));
    }
}
