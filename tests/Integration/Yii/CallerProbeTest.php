<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-27: the stack of each query as YQM-28 will see it, recorded by {@see \mrstroz\querymonitoring\tests\app\CallerProbe}.
 *
 * Both engines, because loading table schema goes through `yii\db\mysql\Schema` or `yii\db\pgsql\Schema`
 * and the stack differs; the assertions read the batch as well. The assertions are about the shape of the
 * stack only. With `QM_CALLER_REPORT=<file>` each case appends its probes there as one JSON line, and
 * `QM_CALLER_TIMING` (limits, e.g. `24,0`) adds the cost of `debug_backtrace()` at the first query.
 */
final class CallerProbeTest extends IntegrationTestCase
{
    private const ENTRY_SCRIPT = 'tests/app/web/index.php';

    private string $probeFile = '';

    protected function setUp(): void
    {
        $this->probeFile = (string) tempnam(sys_get_temp_dir(), 'qm-probe-');
    }

    protected function tearDown(): void
    {
        @unlink($this->probeFile);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideApplicationPathCases(): iterable
    {
        foreach (self::provideDatabaseCases() as [$db]) {
            foreach (['caller-ar', 'caller-command', 'caller-grid'] as $path) {
                yield "{$db} {$path}" => [$db, $path];
            }
        }
    }

    #[DataProvider('provideApplicationPathCases')]
    public function testFirstApplicationFrameOfEveryQueryIsTheScenario(string $db, string $path): void
    {
        $this->requireDatabase($db);
        $connection = Schema::connection($db);
        Schema::create($connection);
        // At least one row, whatever an earlier test left: a found record is hydrated, which loads the table schema.
        if ((int) $connection->createCommand('SELECT COUNT(*) FROM qm_order')->queryScalar() === 0) {
            $connection->createCommand()->insert('qm_order', ['customer' => 'qm-caller@example.com', 'total' => '1.00'])->execute();
        }

        $result = $this->scenario($db, $path, [], $this->probeEnv());

        $this->assertProcessOk($result);
        $probes = $this->probes();
        $this->assertOneProbePerEntry($probes, $this->singleBatch($result));
        foreach ($probes as $probe) {
            self::assertNotSame([], $probe['app'], "application frame of {$probe['sql']}");
            self::assertStringStartsWith("tests/Integration/scenarios/{$path}.php:", $probe['app'][0]['at'], "first application frame of {$probe['sql']}");
        }
        if ($path !== 'caller-command') {
            $schema = array_filter($probes, static fn(array $probe): bool => $probe['kind'] === 'schema');
            self::assertNotSame([], $schema, 'hydrating the found record loads the table schema');
        }
        if ($path === 'caller-grid') {
            $counting = array_filter($probes, static fn(array $probe): bool => str_contains(strtoupper($probe['sql']), 'COUNT('));
            self::assertNotSame([], $counting, 'the counting query of the data provider');
        }
        $this->report($db, $path, $probes);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testQueryBeforeControllerHasNoApplicationFrameButTheEntryScript(string $db): void
    {
        $this->requireDatabase($db);
        $connection = Schema::connection($db);
        Schema::create($connection);
        // A miss on every run: the rules are read and written, whatever an earlier test left.
        $connection->createCommand()->delete('qm_cache')->execute();

        $result = $this->request($db, 'site/index', [], ['QM_DB_CACHE' => '1'] + $this->probeEnv());

        self::assertSame(['route' => 'site/index'], $this->scenarioOutput($result), 'pretty URL routed to the default action');
        $batch = $this->singleBatch($result);
        $probes = $this->probes();
        $this->assertOneProbePerEntry($probes, $batch);
        self::assertNotSame([], $this->entriesWith($batch, 'qm_cache'), 'DbCache read the URL rules');
        foreach ($probes as $probe) {
            self::assertTrue($probe['beforeController'], "no controller yet for {$probe['sql']}");
            $files = array_unique(array_map(static fn(array $frame): string => explode(':', $frame['at'])[0], $probe['app']));
            self::assertSame([self::ENTRY_SCRIPT], array_values($files), "only the entry script for {$probe['sql']}");
        }
        $this->report($db, 'control', $probes);
    }

    /**
     * @return array<string, string>
     */
    private function probeEnv(): array
    {
        $env = ['QM_CALLER_PROBE' => $this->probeFile];
        $timing = (string) getenv('QM_CALLER_TIMING');
        if ($timing !== '' && (string) getenv('QM_CALLER_REPORT') !== '') {
            $env['QM_CALLER_TIMING'] = $timing;
        }

        return $env;
    }

    /**
     * @return list<array{op: string, sql: string, kind: string, beforeController: bool, depth: int, app: list<array{position: int, at: string}>, entryScript: int|null}>
     */
    private function probes(): array
    {
        $probes = [];
        foreach (file($this->probeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $probe = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($probe);
            self::assertArrayNotHasKey('error', $probe, 'the probe found Command::measuredExecute: ' . $line);
            $probes[] = $probe;
        }
        self::assertNotSame([], $probes, 'the path ran at least one query');

        /** @var list<array{op: string, sql: string, kind: string, beforeController: bool, depth: int, app: list<array{position: int, at: string}>, entryScript: int|null}> */
        return $probes;
    }

    /**
     * @param list<array<string, mixed>> $probes
     * @param array<string, mixed> $batch
     */
    private function assertOneProbePerEntry(array $probes, array $batch): void
    {
        self::assertIsArray($batch['queries']);
        self::assertCount(count($probes), $batch['queries'], 'every executed statement is an entry of the batch');
    }

    /**
     * @param list<array<string, mixed>> $probes
     */
    private function report(string $db, string $path, array $probes): void
    {
        $file = (string) getenv('QM_CALLER_REPORT');
        if ($file === '') {
            return;
        }
        $line = ['php' => PHP_VERSION, 'db' => $db, 'path' => $path, 'probes' => $probes];
        file_put_contents($file, json_encode($line, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }
}
