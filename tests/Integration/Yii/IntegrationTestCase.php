<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\app\AppRunner;
use mrstroz\querymonitoring\tests\app\RunResult;
use PHPUnit\Framework\TestCase;

/**
 * Runs the test application in a separate process against MySQL and PostgreSQL from docker compose.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const APP = 't1-test';

    /**
     * @return iterable<string, array{string}>
     */
    public static function databases(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    protected function requireDatabase(string $db): void
    {
        if (!AppRunner::hasDatabase($db)) {
            self::markTestSkipped("No DSN for {$db}.");
        }
    }

    /**
     * Runs a scenario from tests/Integration/scenarios with the given component settings.
     *
     * @param array<string, mixed> $component merged over {@see self::defaultComponent()}
     * @param array<string, string> $env
     */
    protected function scenario(string $db, string $name, array $component = [], array $env = []): RunResult
    {
        return $this->request($db, AppRunner::SCENARIO_ROUTE, $component, ['QM_SCENARIO' => $name] + $env);
    }

    /**
     * Runs one request of the test application for `$route` (named so, because TestCase::run() is final).
     *
     * @param array<string, mixed> $component merged over {@see self::defaultComponent()}
     * @param array<string, string> $env
     */
    protected function request(string $db, string $route, array $component = [], array $env = []): RunResult
    {
        $this->requireDatabase($db);

        return AppRunner::run(array_replace(self::defaultComponent(), $component), $route, ['QM_DB' => $db] + $env);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function defaultComponent(): array
    {
        return ['app' => self::APP, 'connections' => ['db', 'admin/db']];
    }

    /**
     * Decoded JSON the scenario printed.
     */
    protected function scenarioOutput(RunResult $result): mixed
    {
        $this->assertProcessOk($result);

        return json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function assertProcessOk(RunResult $result): void
    {
        self::assertSame(0, $result->exitCode, "exit code\nstdout: {$result->stdout}\nstderr: {$result->stderr}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function singleBatch(RunResult $result): array
    {
        self::assertCount(1, $result->batches, 'exactly one batch; stderr: ' . $result->stderr);

        return $result->batches[0];
    }

    /**
     * Entries of the batch whose `query` contains `$marker`.
     *
     * @param array<string, mixed> $batch
     *
     * @return list<array<string, mixed>>
     */
    protected function entriesWith(array $batch, string $marker): array
    {
        self::assertIsArray($batch['queries']);

        return array_values(array_filter(
            $batch['queries'],
            static fn(array $entry): bool => is_string($entry['query']) && str_contains($entry['query'], $marker),
        ));
    }

    /**
     * @return list<array{level: string, category: string, message: string}>
     */
    protected function errors(RunResult $result): array
    {
        return array_values(array_filter($result->logs, static fn(array $log): bool => $log['level'] === 'error'));
    }

    protected function assertNoErrors(RunResult $result): void
    {
        self::assertSame([], $this->errors($result), 'no Yii::error expected');
    }

    protected function assertOnePackageError(RunResult $result): void
    {
        $errors = $this->errors($result);
        self::assertCount(1, $errors, 'exactly one Yii::error: ' . json_encode($result->logs));
        self::assertSame(Guard::LOG_CATEGORY, $errors[0]['category']);
    }
}
