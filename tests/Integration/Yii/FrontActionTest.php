<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\RunResult;
use mrstroz\querymonitoring\tests\app\Schema;
use mrstroz\querymonitoring\tests\Integration\support\ForeignBeforeAction;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-7, spec 01 §4 and 02 §1: the entry action from the first EVENT_BEFORE_ACTION of the application.
 */
final class FrontActionTest extends IntegrationTestCase
{
    #[DataProvider('databases')]
    public function testActionOfMainApplicationHasNoModule(string $db): void
    {
        $batch = $this->singleBatch($this->scenario($db, 'lifecycle'));

        self::assertSame([null, 'scenario', 'run'], self::entryAction($batch));
    }

    #[DataProvider('databases')]
    public function testActionOfNestedModuleHasModulePathAndLocalIds(string $db): void
    {
        $result = $this->route($db, 'admin/orders/order/view');
        $this->assertProcessOk($result);
        $batch = $this->singleBatch($result);

        self::assertSame(['admin/orders', 'order', 'view'], self::entryAction($batch));
        self::assertNotEmpty($batch['queries']);
    }

    #[DataProvider('databases')]
    public function testNestedRunActionKeepsEntryAction(string $db): void
    {
        $result = $this->scenario($db, 'nested-run');
        self::assertSame(['inner' => ['ok' => true], 'value' => 811], $this->scenarioOutput($result));
        $batch = $this->singleBatch($result);

        self::assertSame([null, 'scenario', 'run'], self::entryAction($batch));
        self::assertCount(1, $this->entriesWith($batch, 'qm_nested_after'));
    }

    #[DataProvider('databases')]
    public function testQueryInInnerActionKeepsOuterAction(string $db): void
    {
        $result = $this->route($db, 'site/nested');
        $this->assertProcessOk($result);
        $batch = $this->singleBatch($result);

        self::assertSame([null, 'site', 'nested'], self::entryAction($batch));
        self::assertNotEmpty($this->entriesWith($batch, 'qm_order'), 'queries of the inner action');
    }

    #[DataProvider('databases')]
    public function testErrorActionDoesNotReplaceEntryAction(string $db): void
    {
        // a UserException: under YII_DEBUG the ErrorHandler runs errorAction only for those
        $result = $this->scenario($db, 'lifecycle', [], ['QM_T1_END' => 'user-throw', 'QM_ERROR_ACTION' => '1']);

        self::assertSame(1, $result->exitCode, "stdout: {$result->stdout}\nstderr: {$result->stderr}");
        self::assertSame(['error' => \yii\base\UserException::class], json_decode($result->stdout, true), 'errorAction rendered the response');
        $batch = $this->singleBatch($result);

        self::assertSame([null, 'scenario', 'run'], self::entryAction($batch));
        self::assertCount(1, $this->entriesWith($batch, 'qm_life_action'));
        self::assertCount(1, $this->entriesWith($batch, 'qm_in_error'), 'the error action ran its query');
    }

    #[DataProvider('databases')]
    public function testNotFoundBeforeRoutingHasNoAction(string $db): void
    {
        $result = $this->route($db, 'qm-t1-missing/none', ['QM_ERROR_ACTION' => '1']);
        $batch = $this->singleBatch($result);

        self::assertSame([null, null, null], self::entryAction($batch));
        self::assertCount(1, $this->entriesWith($batch, 'qm_in_error'), 'the error action ran its query');
    }

    #[DataProvider('databases')]
    public function testForeignBeforeActionEventWithPlainEventIsIgnored(string $db): void
    {
        // before routing, so the package's handler is still attached when the plain Event arrives
        $result = $this->scenario($db, 'lifecycle', ['as foreignEvent' => ['class' => ForeignBeforeAction::class]]);

        self::assertStringContainsString('foreign:before_action', $result->stderr);
        self::assertSame(['action' => 801], $this->scenarioOutput($result));
        self::assertSame([null, 'scenario', 'run'], self::entryAction($this->singleBatch($result)));
        $this->assertNoErrors($result);
    }

    /**
     * A request for a route of the test application; its actions use the `qm_order` table.
     *
     * @param array<string, string> $env
     */
    private function route(string $db, string $route, array $env = []): RunResult
    {
        $this->requireDatabase($db);
        Schema::create(Schema::connection($db));

        return $this->request($db, $route, [], $env);
    }

    /**
     * @param array<string, mixed> $batch
     *
     * @return array{mixed, mixed, mixed}
     */
    private static function entryAction(array $batch): array
    {
        self::assertArrayHasKey('module', $batch);
        self::assertArrayHasKey('controller', $batch);
        self::assertArrayHasKey('action', $batch);

        return [$batch['module'], $batch['controller'], $batch['action']];
    }
}
