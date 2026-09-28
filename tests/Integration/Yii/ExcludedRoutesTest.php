<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-52, spec 01 §7 and ADR-0013: `excludedRoutes` per context type skips the entries of an excluded context
 * without counting them in `dropped`, also those recorded before the route was known; a job inside an excluded
 * context is judged by its own key.
 */
final class ExcludedRoutesTest extends IntegrationTestCase
{
    #[DataProvider('provideDatabaseCases')]
    public function testExcludedRequestSendsNothingAlsoForQueriesBeforeRouting(string $db): void
    {
        $result = $this->scenario($db, 'probe', ['excludedRoutes' => ['http' => ['scenario/run']]], ['QM_BOOTSTRAP_QUERY' => '1']);

        $this->assertProcessOk($result);
        self::assertSame([], $result->batches);
        self::assertSame([], $result->adapterCalls);
        $this->assertNoErrors($result);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testQueryBeforeRoutingIsInTheBatchOfARequestThatIsNotExcluded(string $db): void
    {
        $result = $this->scenario($db, 'probe', ['excludedRoutes' => ['http' => ['site/index']]], ['QM_BOOTSTRAP_QUERY' => '1']);

        $batch = $this->singleBatch($result);
        self::assertSame('scenario/run', $batch['route']);
        self::assertNotSame([], $this->entriesWith($batch, 'qm_bootstrap'), 'the bootstrap query is kept');
        self::assertSame(0, $batch['dropped']);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testExcludedCommandDiscardsTheBufferFilledBeforeTheRoute(string $db): void
    {
        $result = $this->consoleScenario($db, 'console-probe', ['maxEntries' => 1, 'excludedRoutes' => ['console' => ['scenario/run']]], ['QM_BOOTSTRAP_QUERY' => '1']);

        $this->assertProcessOk($result);
        self::assertSame([], $result->adapterCalls, 'neither the full buffer nor its dropped count is sent');
        $this->assertNoErrors($result);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testExcludedListenerStillMonitorsItsJobs(string $db): void
    {
        $result = $this->consoleScenario($db, 'jobs', ['excludedRoutes' => ['console' => ['scenario/run']]], ['QM_OPS' => (string) json_encode([
            ['q', 'qm_listener_1'],
            ['begin', 'app\jobs\A'], ['q', 'qm_a'], ['end', 0],
            ['q', 'qm_listener_2'],
            ['begin', 'app\jobs\B'], ['q', 'qm_b'], ['end', 1],
        ])]);

        $this->assertProcessOk($result);
        self::assertSame(['job', 'job'], array_column($result->batches, 'type'));
        self::assertSame(['scenario/run', 'scenario/run'], array_column($result->batches, 'route'), 'route of an excluded root stays in job batches');
        self::assertSame([0, 0], array_column($result->batches, 'dropped'), 'excluded entries are not dropped entries');
        foreach ($result->batches as $batch) {
            self::assertSame([], $this->entriesWith($batch, 'qm_listener'));
        }
    }

    #[DataProvider('provideDatabaseCases')]
    public function testJobExcludedByNameLeavesNoEntryAnywhere(string $db): void
    {
        $result = $this->consoleScenario($db, 'jobs', ['excludedRoutes' => ['job' => ['app\jobs\Noisy']]], ['QM_OPS' => (string) json_encode([
            ['q', 'qm_root'],
            ['begin', 'app\jobs\Noisy'], ['q', 'qm_noisy'], ['end', 0],
            ['begin', 'app\jobs\Quiet'], ['q', 'qm_quiet'], ['end', 1],
        ])]);

        $this->assertProcessOk($result);
        self::assertSame([['job', 'app\jobs\Quiet'], ['console', null]], array_map(static fn(array $b): array => [$b['type'], $b['job']['name'] ?? null], $result->batches));
        self::assertSame([], $this->entriesWith($result->batches[1], 'qm_noisy'), 'entries of an excluded job do not go to its parent');
        self::assertSame(0, $result->batches[1]['dropped']);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testJobInsideAnExcludedJobIsMonitored(string $db): void
    {
        $result = $this->consoleScenario($db, 'jobs', ['excludedRoutes' => ['job' => ['app\jobs\Outer']]], ['QM_OPS' => (string) json_encode([
            ['begin', 'app\jobs\Outer'], ['q', 'qm_outer'],
            ['begin', 'app\jobs\Inner'], ['q', 'qm_inner'], ['end', 1],
            ['end', 0],
        ])]);

        $this->assertProcessOk($result);
        self::assertCount(1, $result->batches);
        self::assertSame('app\jobs\Inner', $result->batches[0]['job']['name']);
        self::assertNotSame([], $this->entriesWith($result->batches[0], 'qm_inner'));
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function provideMatchCases(): iterable
    {
        yield 'exact' => [['scenario/run'], true];
        yield 'exact is not a prefix' => [['scenario'], false];
        yield 'prefix' => [['scenario/*'], true];
        yield 'prefix of another controller' => [['scen/*'], false];
        yield 'prefix without slash' => [['scen*'], true];
        yield 'star alone' => [['*'], true];
        yield 'case matters' => [['Scenario/run'], false];
    }

    /**
     * @param list<string> $patterns
     */
    #[DataProvider('provideMatchCases')]
    public function testMatchingRules(array $patterns, bool $excluded): void
    {
        $result = $this->consoleScenario(self::ANY_DB, 'console-probe', ['excludedRoutes' => ['console' => $patterns]]);

        $this->assertProcessOk($result);
        self::assertCount($excluded ? 0 : 1, $result->batches);
        $this->assertNoErrors($result);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testJobNameIsMatchedBeforeTruncation(string $db): void
    {
        $name = 'app\jobs\\' . str_repeat('L', 300);
        $result = $this->consoleScenario($db, 'jobs', ['excludedRoutes' => ['job' => [$name]]], ['QM_OPS' => (string) json_encode([
            ['begin', $name], ['q', 'qm_long'], ['end', 0],
            ['begin', 'app\jobs\\' . str_repeat('L', 299) . 'X'], ['q', 'qm_other'], ['end', 1],
        ])]);

        $this->assertProcessOk($result);
        self::assertCount(1, $result->batches, 'only the job whose full name differs');
        self::assertNotSame([], $this->entriesWith($result->batches[0], 'qm_other'));
        self::assertLessThanOrEqual(255, strlen($result->batches[0]['job']['name']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideWrongConfigurationCases(): iterable
    {
        yield 'unknown key' => [['cli' => ['x']]];
        yield 'star inside' => [['console' => ['queue/*/run']]];
        yield 'empty pattern' => [['console' => ['']]];
        yield 'not a string' => [['console' => [1]]];
        yield 'not a list' => [['console' => 'queue/*']];
        yield 'leading slash' => [['console' => ['/scenario/run']]];
    }

    #[DataProvider('provideWrongConfigurationCases')]
    public function testWrongConfigurationDisablesThePackage(mixed $excludedRoutes): void
    {
        $result = $this->consoleScenario(self::ANY_DB, 'console-probe', ['excludedRoutes' => $excludedRoutes]);

        $this->assertProcessOk($result);
        self::assertSame([], $result->batches);
        $this->assertOnePackageError($result);
    }
}
