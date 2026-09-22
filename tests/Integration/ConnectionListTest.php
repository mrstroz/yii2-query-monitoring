<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration;

use mrstroz\querymonitoring\sql\Command;
use mrstroz\querymonitoring\tests\app\RunResult;
use mrstroz\querymonitoring\tests\app\TestPdo;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-4, spec 01 §1: the package does not connect in bootstrap, and a connection it cannot take
 * over is skipped with one Yii::error while the rest of the list works.
 */
final class ConnectionListTest extends IntegrationTestCase
{
    /** An address that never answers, so any connection attempt would hang until the timeout. */
    private const UNREACHABLE = '10.255.255.1';

    private const APP_COMMAND = \yii\db\sqlite\Command::class;

    private const SLOW_CONNECT_S = 2;

    /**
     * @return array<string, mixed>
     */
    private static function mastersOnly(string $db): array
    {
        return ['dbMasters' => [
            'dsn' => null,
            'masters' => [['dsn' => "{$db}:host=" . self::UNREACHABLE . ';dbname=qm']],
            // pool members do not inherit pdoClass; without it an attempt would not go through TestPdo
            'masterConfig' => ['pdoClass' => TestPdo::class, 'attributes' => [\PDO::ATTR_TIMEOUT => 1]],
        ]];
    }

    #[DataProvider('databases')]
    public function testConnectionWithoutDsnIsSkippedWithoutConnecting(string $db): void
    {
        $started = microtime(true);
        $result = $this->probe($db, ['db', 'dbMasters', 'admin/db'], self::mastersOnly($db), state: ['dbMasters'], env: [
            // every connection attempt through TestPdo sleeps first, so one attempt shows in the run time
            'QM_SLOW_CONNECT_MS' => (string) (self::SLOW_CONNECT_S * 1000),
        ]);
        $elapsed = microtime(true) - $started;

        self::assertFalse($this->scenarioOutput($result)['open']['dbMasters']);
        self::assertLessThan(self::SLOW_CONNECT_S, $elapsed, 'no connection attempt in bootstrap');
        $this->assertOnePackageError($result);
        self::assertSame([], $result->batches);
    }

    #[DataProvider('databases')]
    public function testConnectionWithoutDsnLeavesRestOfListWorking(string $db): void
    {
        $result = $this->probe($db, ['db', 'dbMasters', 'admin/db'], self::mastersOnly($db), query: ['db']);

        $this->assertOnePackageError($result);
        self::assertCount(1, $this->entriesWith($this->singleBatch($result), 'qm_on_db'));
    }

    #[DataProvider('databases')]
    public function testListedConnectionIsNotOpenedByBootstrap(string $db): void
    {
        $result = $this->probe($db, ['db', 'admin/db'], [], state: ['db']);

        self::assertFalse($this->scenarioOutput($result)['open']['db']);
        $this->assertNoErrors($result);
        self::assertSame([], $result->batches);
    }

    #[DataProvider('databases')]
    public function testOwnCommandClassIsSkippedAndKept(string $db): void
    {
        $result = $this->probe($db, ['db', 'dbCustom'], [
            'dbCustom' => ['commandClass' => self::APP_COMMAND],
        ], class: ['db', 'dbCustom'], query: ['db']);

        $out = $this->scenarioOutput($result);
        self::assertSame(self::APP_COMMAND, $out['class']['dbCustom']);
        self::assertSame(Command::class, $out['class']['db']);
        $this->assertOnePackageError($result);
        self::assertCount(1, $this->entriesWith($this->singleBatch($result), 'qm_on_db'));
    }

    #[DataProvider('databases')]
    public function testOwnCommandMapIsSkippedAndKept(string $db): void
    {
        $result = $this->probe($db, ['db', 'dbMap'], [
            'dbMap' => ['commandMap' => [$db => self::APP_COMMAND]],
        ], class: ['db', 'dbMap'], query: ['db']);

        $out = $this->scenarioOutput($result);
        self::assertSame(self::APP_COMMAND, $out['class']['dbMap']);
        self::assertSame(Command::class, $out['class']['db']);
        $this->assertOnePackageError($result);
        self::assertCount(1, $this->entriesWith($this->singleBatch($result), 'qm_on_db'));
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function aliasOrders(): iterable
    {
        foreach (['mysql', 'pgsql'] as $db) {
            yield "{$db}: db first" => [$db, ['db', 'aliasDb'], 'db'];
            yield "{$db}: alias first" => [$db, ['aliasDb', 'db'], 'aliasDb'];
        }
    }

    /**
     * @param list<string> $connections
     */
    #[DataProvider('aliasOrders')]
    public function testSameConnectionUnderTwoIdsCountsTheFirstId(string $db, array $connections, string $expected): void
    {
        $result = $this->probe($db, $connections, ['aliasDb' => 'alias:db'], query: ['aliasDb', 'db']);

        $this->assertOnePackageError($result);
        $batch = $this->singleBatch($result);
        foreach (['qm_on_aliasDb', 'qm_on_db'] as $marker) {
            $entries = $this->entriesWith($batch, $marker);
            self::assertCount(1, $entries, "{$marker}: one entry, not two");
            self::assertSame($expected, $entries[0]['conn']);
        }
    }

    /**
     * @param list<string> $connections
     * @param array<string, mixed> $extra
     * @param list<string> $state
     * @param list<string> $class
     * @param list<string> $query
     * @param array<string, string> $env
     */
    private function probe(string $db, array $connections, array $extra, array $state = [], array $class = [], array $query = [], array $env = []): RunResult
    {
        return $this->scenario($db, 'probe', ['connections' => $connections], $env + [
            'QM_EXTRA_CONNECTIONS' => (string) json_encode($extra === [] ? new \stdClass() : $extra),
            'QM_PROBE_STATE' => implode(',', $state),
            'QM_PROBE_CLASS' => implode(',', $class),
            'QM_PROBE_QUERY' => implode(',', $query),
        ]);
    }
}
