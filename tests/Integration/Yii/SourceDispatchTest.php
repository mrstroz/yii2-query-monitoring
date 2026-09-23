<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\FakeSourceQueryMonitor;
use mrstroz\querymonitoring\tests\app\FaultyQueryMonitor;

/**
 * YQM-33, spec 01 §1: the component hands each listed component to the first source that supports it, and a
 * source that skips a connection skips only that one.
 */
final class SourceDispatchTest extends IntegrationTestCase
{
    public function testIncompatibleSourceSkipsItsConnectionsAndLeavesOtherSourcesInstalled(): void
    {
        $sources = [
            ['sql', \yii\db\Connection::class, true],
            ['mongodb', \yii\mongodb\Connection::class, false],
            // Takes the same components as `sql`: only the first source that supports a component installs it.
            ['late-sql', \yii\db\Connection::class, false],
        ];

        $result = $this->scenario(self::ANY_DB, 'fake-sources', ['class' => FakeSourceQueryMonitor::class, 'connections' => ['db', 'admin/db', 'mongodb']], ['QM_FAKE_SOURCES' => (string) json_encode($sources)]);

        self::assertSame(['calls' => ['sql:db', 'sql:admin/db', 'mongodb:mongodb']], $this->scenarioOutput($result), 'every listed connection reaches its source, the failing one does not stop the list');
        $this->assertOnePackageError($result);
        self::assertStringContainsString('Source sql does not support the installed library.', $this->errors($result)[0]['message']);
    }

    public function testComponentNoSourceSupportsIsSkippedAndTheRestOfTheListMeasured(): void
    {
        $result = $this->request(self::ANY_DB, 'scenario/run', ['connections' => ['cache', 'db']], ['QM_SCENARIO' => 'probe', 'QM_PROBE_QUERY' => 'db']);

        $this->assertProcessOk($result);
        $this->assertOnePackageError($result);
        self::assertStringContainsString('Connection cache is not a connection the package measures.', $this->errors($result)[0]['message']);
        self::assertCount(1, $this->entriesWith($this->singleBatch($result), 'qm_on_db'));
    }

    public function testNormaliserIsCreatedOnceOnTheFirstSqlConnection(): void
    {
        $result = $this->scenario(self::ANY_DB, 'normalizers', ['class' => FaultyQueryMonitor::class, 'connections' => ['cache', 'db', 'admin/db']]);

        self::assertSame(['created' => 1], $this->scenarioOutput($result), 'one normaliser shared by both SQL connections');
    }

    public function testFailingNormaliserFactoryIsNotCalledWithoutSqlConnection(): void
    {
        $result = $this->scenario(self::ANY_DB, 'normalizers', ['class' => FaultyQueryMonitor::class, 'connections' => ['cache']], ['QM_FAULT' => 'normalizer-factory']);

        self::assertSame(['created' => 0], $this->scenarioOutput($result));
        $this->assertOnePackageError($result);
        self::assertStringContainsString('Connection cache is not a connection the package measures.', $this->errors($result)[0]['message'], 'the only error is the unsupported component, not the factory');
    }
}
