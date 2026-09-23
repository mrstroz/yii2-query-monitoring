<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-28, spec 02 §2: every entry of a batch from the test application carries `caller`, the application
 * frames nearest to the query, relative to the project root, without the entry script.
 *
 * The application paths also guard `N`: the deepest query, the second level of `with()` under GridView in
 * `caller-grid-with` (position 31 on both engines), lies beyond the 30 frames of YQM-28 and inside
 * `Recorder::TRACE_LIMIT`; a limit that no longer reaches it turns its `caller` into `[]` and fails here.
 */
final class CallerFieldTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideApplicationPathCases(): iterable
    {
        foreach (self::provideDatabaseCases() as [$db]) {
            foreach (['caller-ar', 'caller-command', 'caller-grid', 'caller-grid-with'] as $path) {
                yield "{$db} {$path}" => [$db, $path];
            }
        }
    }

    #[DataProvider('provideApplicationPathCases')]
    public function testEveryQueryOfApplicationPathStartsAtItsScenario(string $db, string $path): void
    {
        $this->requireDatabase($db);
        $connection = Schema::connection($db);
        Schema::create($connection);
        // A found record is hydrated, which loads the table schema: the deepest query of the path.
        if ((int) $connection->createCommand('SELECT COUNT(*) FROM qm_order')->queryScalar() === 0) {
            $connection->createCommand()->insert('qm_order', ['customer' => 'qm-caller@example.com', 'total' => '1.00'])->execute();
        }

        $result = $this->scenario($db, $path);

        $this->assertProcessOk($result);
        $queries = $this->queries($this->singleBatch($result));
        self::assertNotSame([], $queries);
        foreach ($queries as $entry) {
            $caller = $this->caller($entry);
            self::assertNotSame([], $caller, "application frame within the trace limit for {$entry['query']}");
            self::assertStringStartsWith("tests/Integration/scenarios/{$path}.php:", $caller[0], "nearest frame for {$entry['query']}");
            $this->assertRelativeWithoutEntryScript($caller);
        }
    }

    #[DataProvider('provideDatabaseCases')]
    public function testCallerOfCommandIsScenarioThenController(string $db): void
    {
        $batch = $this->singleBatch($this->scenario($db, 'caller-command'));

        $entries = $this->entriesWith($batch, 'qm_caller_command');
        self::assertCount(1, $entries);
        self::assertSame(
            ['tests/Integration/scenarios/caller-command.php:9', 'tests/app/controllers/ScenarioController.php:23'],
            $this->caller($entries[0]),
            'the query line, then the call of the scenario; vendor, the package and index.php left out',
        );
    }

    /**
     * spec 02 §2: the entry script is the first file PHP runs, not counting `auto_prepend_file`.
     * The CLI lists the main script before the prepended file, which is why the rule is not "the second file".
     */
    #[DataProvider('provideDatabaseCases')]
    public function testAutoPrependFileDoesNotChangeCaller(string $db): void
    {
        $plain = $this->entriesWith($this->singleBatch($this->scenario($db, 'caller-command')), 'qm_caller_command');
        $prepended = $this->entriesWith($this->singleBatch($this->scenario($db, 'caller-command', [], ['QM_AUTO_PREPEND' => '1'])), 'qm_caller_command');

        self::assertCount(1, $plain);
        self::assertCount(1, $prepended);
        self::assertSame($this->caller($plain[0]), $this->caller($prepended[0]), 'the same caller with and without auto_prepend_file');
        foreach ($this->caller($prepended[0]) as $frame) {
            self::assertStringStartsNotWith('tests/app/web/index.php:', $frame, 'the entry script stays excluded');
            self::assertStringStartsNotWith('tests/app/prepend.php:', $frame);
        }
    }

    #[DataProvider('provideDatabaseCases')]
    public function testQueryBeforeControllerHasEmptyCaller(string $db): void
    {
        $this->requireDatabase($db);
        $connection = Schema::connection($db);
        Schema::create($connection);
        $connection->createCommand()->delete('qm_cache')->execute();

        $batch = $this->singleBatch($this->request($db, 'site/index', [], ['QM_DB_CACHE' => '1']));

        self::assertNotSame([], $this->entriesWith($batch, 'qm_cache'), 'DbCache read the URL rules before the controller');
        foreach ($this->queries($batch) as $entry) {
            self::assertSame([], $this->caller($entry), "only Yii and the entry script issued {$entry['query']}");
        }
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string, int}>
     */
    public static function provideErrorEntryCases(): iterable
    {
        foreach (self::provideDatabaseCases() as [$db]) {
            yield "{$db} execute" => [$db, 'execute-error', [], 'INSERT INTO qm_t1_item (id, name, flag) VALUES (:id', 12];
            yield "{$db} prepare" => [$db, 'prepare-error', ['QM_FAIL_PREPARE' => '42000'], 'qm_prep_fail', 10];
        }
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('provideErrorEntryCases')]
    public function testErrorEntryCarriesCallerAsLastField(string $db, string $scenario, array $env, string $marker, int $line): void
    {
        $batch = $this->singleBatch($this->scenario($db, $scenario, [], $env));

        $entries = $this->entriesWith($batch, $marker);
        self::assertCount(1, $entries);
        self::assertSame('error', $entries[0]['result']);
        self::assertSame('caller', array_key_last($entries[0]), 'caller follows error');
        self::assertSame("tests/Integration/scenarios/{$scenario}.php:{$line}", $this->caller($entries[0])[0]);
    }

    /**
     * @param array<string, mixed> $batch
     *
     * @return list<array<string, mixed>>
     */
    private function queries(array $batch): array
    {
        self::assertIsArray($batch['queries']);

        /** @var list<array<string, mixed>> */
        return $batch['queries'];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<string>
     */
    private function caller(array $entry): array
    {
        self::assertArrayHasKey('caller', $entry, 'every entry carries caller');
        self::assertIsArray($entry['caller']);
        self::assertLessThanOrEqual(3, count($entry['caller']));
        self::assertTrue(array_is_list($entry['caller']));
        self::assertContainsOnly('string', $entry['caller']);

        /** @var list<string> */
        return $entry['caller'];
    }

    /**
     * @param list<string> $caller
     */
    private function assertRelativeWithoutEntryScript(array $caller): void
    {
        foreach ($caller as $frame) {
            self::assertMatchesRegularExpression('~^[^/].*\.php:\d+$~', $frame, 'relative path:line');
            self::assertStringStartsNotWith('tests/app/web/index.php:', $frame, 'the entry script is not a caller');
            self::assertStringNotContainsString('vendor/', $frame);
            self::assertStringStartsNotWith('src/', $frame);
        }
    }
}
