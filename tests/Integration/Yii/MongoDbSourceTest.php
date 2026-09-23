<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\FaultyQueryMonitor;
use mrstroz\querymonitoring\tests\app\RunResult;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-35, spec 01 §1 and §3, ADR-0010: the MongoDB source on the listed `yii\mongodb\Connection` components.
 */
final class MongoDbSourceTest extends IntegrationTestCase
{
    /** Same DSN, options and driver options as `mongodb`: the same driver client. */
    private const SAME_CLIENT = '{}';

    public function testActiveRecordFindGivesMongoDbEntry(): void
    {
        $result = $this->mongo(['mongodb'], ['QM_MONGO_AR' => '1']);

        $finds = $this->entriesWith($this->singleBatch($result), 'qm_contact');
        self::assertCount(1, $finds);
        $entry = $finds[0];
        self::assertSame(['mongodb', 'mongodb', 'find', 'qm_contact filter{}', 'success'], [$entry['db'], $entry['conn'], $entry['op'], $entry['query'], $entry['result']]);
        self::assertIsFloat($entry['time_ms']);
        self::assertStringStartsWith('tests/Integration/scenarios/mongodb-source.php:', $entry['caller'][0], 'first application frame within the limit of 64');
        $this->assertNoErrors($result);
    }

    public function testConnectionOpenedBeforeBootstrapAndReopenedIsMeasuredOncePerCommand(): void
    {
        $result = $this->mongo(['mongodb'], ['QM_MONGO_USE' => 'mongodb', 'QM_MONGO_REOPEN' => '1', 'QM_MONGODB_EARLY' => '1']);

        $batch = $this->singleBatch($result);
        self::assertCount(1, $this->entriesWith($batch, 'qm_src_mongodb '), 'opened before the package');
        self::assertCount(1, $this->entriesWith($batch, 'qm_src_reopen '), 'after close() and open()');
        $this->assertNoErrors($result);
    }

    public function testConnectionWithAnotherClientOutsideTheListGivesNoEntries(): void
    {
        $result = $this->mongo(['mongodb'], ['QM_MONGO_USE' => 'mongodb,mongodbOther', 'QM_MONGODB_EXTRA' => '{"mongodbOther":{"options":{"appname":"qm-other"}}}']);

        $batch = $this->singleBatch($result);
        self::assertCount(1, $this->entriesWith($batch, 'qm_src_mongodb '));
        self::assertSame([], $this->entriesWith($batch, 'qm_src_mongodbOther'));
    }

    public function testUnlistedConnectionOnTheSameClientIsMeasuredUnderTheListedIdWhileItsManagerExists(): void
    {
        $extra = '{"mongodbShared":' . self::SAME_CLIENT . '}';

        $opened = $this->singleBatch($this->mongo(['mongodb'], ['QM_MONGO_USE' => 'mongodb,mongodbShared', 'QM_MONGODB_EXTRA' => $extra]));
        $notOpened = $this->mongo(['mongodb'], ['QM_MONGO_USE' => 'mongodbShared', 'QM_MONGODB_EXTRA' => $extra]);

        self::assertSame(['mongodb'], array_column($this->entriesWith($opened, 'qm_src_mongodbShared'), 'conn'));
        $this->assertProcessOk($notOpened);
        self::assertSame([], $notOpened->batches, 'no Manager of a listed connection, no subscriber of the package');
    }

    public function testSecondListedIdOnTheSameClientIsMeasuredUnderTheFirstWithoutError(): void
    {
        $extra = '{"mongodbRead":' . self::SAME_CLIENT . '}';

        $onlySecond = $this->mongo(['mongodb', 'mongodbRead'], ['QM_MONGO_USE' => 'mongodbRead', 'QM_MONGODB_EXTRA' => $extra]);
        $both = $this->mongo(['mongodb', 'mongodbRead'], ['QM_MONGO_USE' => 'mongodb,mongodbRead', 'QM_MONGODB_EXTRA' => $extra]);

        self::assertSame(['mongodb'], array_column($this->entriesWith($this->singleBatch($onlySecond), 'qm_src_mongodbRead'), 'conn'), 'the first id is never opened');
        $this->assertNoErrors($onlySecond);
        $batch = $this->singleBatch($both);
        self::assertSame(['mongodb'], array_column($this->entriesWith($batch, 'qm_src_mongodb '), 'conn'));
        self::assertSame(['mongodb'], array_column($this->entriesWith($batch, 'qm_src_mongodbRead'), 'conn'), 'one subscriber on two Managers of one client: each command once');
        $this->assertNoErrors($both);
    }

    public function testListedIdsWithOptionsInAnotherOrderAreTwoClients(): void
    {
        $extra = '{"mongodbA":{"options":{"appname":"qm-order","connectTimeoutMS":5000}},"mongodbB":{"options":{"connectTimeoutMS":5000,"appname":"qm-order"}}}';

        $result = $this->mongo(['mongodbA', 'mongodbB'], ['QM_MONGO_USE' => 'mongodbA,mongodbB', 'QM_MONGODB_EXTRA' => $extra]);

        $batch = $this->singleBatch($result);
        self::assertSame(['mongodbA'], array_column($this->entriesWith($batch, 'qm_src_mongodbA'), 'conn'));
        self::assertSame(['mongodbB'], array_column($this->entriesWith($batch, 'qm_src_mongodbB'), 'conn'));
        $this->assertNoErrors($result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideClientPersistenceCases(): iterable
    {
        // disableClientPersistence: false next to no driver options is another client for the driver (YQM-32).
        yield 'false against none' => ['{"mongodbA":{},"mongodbB":{"driverOptions":{"disableClientPersistence":false}}}'];
        // true gives every Manager its own client, identical configuration or not.
        yield 'true on both' => ['{"mongodbA":{"driverOptions":{"disableClientPersistence":true}},"mongodbB":{"driverOptions":{"disableClientPersistence":true}}}'];
    }

    #[DataProvider('provideClientPersistenceCases')]
    public function testClientPersistenceOptionSeparatesTheClients(string $extra): void
    {
        $result = $this->mongo(['mongodbA', 'mongodbB'], ['QM_MONGO_USE' => 'mongodbA,mongodbB', 'QM_MONGODB_EXTRA' => $extra]);

        $batch = $this->singleBatch($result);
        self::assertSame(['mongodbA'], array_column($this->entriesWith($batch, 'qm_src_mongodbA'), 'conn'));
        self::assertSame(['mongodbB'], array_column($this->entriesWith($batch, 'qm_src_mongodbB'), 'conn'));
        $this->assertNoErrors($result);
    }

    public function testSameConnectionUnderTwoIdsCountsTheFirstId(): void
    {
        $result = $this->scenario(self::ANY_DB, 'mongodb-source', ['connections' => ['mongodb', 'mongodbAlias']], ['QM_MONGO_USE' => 'mongodbAlias', 'QM_EXTRA_CONNECTIONS' => '{"mongodbAlias":"alias:mongodb"}']);

        $this->assertOnePackageError($result);
        self::assertStringContainsString('Connection mongodbAlias is already monitored under another id.', $this->errors($result)[0]['message']);
        self::assertSame(['mongodb'], array_column($this->entriesWith($this->singleBatch($result), 'qm_src_mongodbAlias'), 'conn'));
    }

    public function testFailureInTheSubscriberChangesNeitherTheResultNorOtherSubscribers(): void
    {
        $healthy = $this->mongo(['mongodb'], ['QM_MONGO_USE' => 'mongodb', 'QM_MONGO_WATCH' => '1']);
        $faulty = $this->scenario(self::ANY_DB, 'mongodb-source', ['class' => FaultyQueryMonitor::class, 'connections' => ['mongodb']], ['QM_MONGO_USE' => 'mongodb', 'QM_MONGO_WATCH' => '1', 'QM_FAULT' => 'mongodb-normalizer']);

        self::assertSame($this->scenarioOutput($healthy), $this->scenarioOutput($faulty), 'same response, and the subscriber added after the package still sees every command');
        self::assertSame(['find'], $this->scenarioOutput($faulty)['watched']);
        $this->assertOnePackageError($faulty);
        self::assertSame([], $faulty->batches);
    }

    public function testFailureAtTheEndEventChangesNeitherTheResultNorOtherSubscribers(): void
    {
        $healthy = $this->mongo(['mongodb'], ['QM_MONGO_USE' => 'mongodb', 'QM_MONGO_WATCH' => '1']);
        $faulty = $this->scenario(self::ANY_DB, 'mongodb-source', ['class' => FaultyQueryMonitor::class, 'connections' => ['mongodb']], ['QM_MONGO_USE' => 'mongodb', 'QM_MONGO_WATCH' => '1', 'QM_FAULT' => 'mongodb-callers']);

        self::assertSame($this->scenarioOutput($healthy), $this->scenarioOutput($faulty));
        $this->assertOnePackageError($faulty);
    }

    public function testFailingSubscriptionAtOpenLeavesTheApplicationAndTheSqlSourceWorking(): void
    {
        $env = ['QM_MONGO_USE' => 'mongodb', 'QM_MONGO_SQL' => '1'];
        $healthy = $this->mongo(['db', 'mongodb'], $env);
        $faulty = $this->scenario(self::ANY_DB, 'mongodb-source', ['class' => FaultyQueryMonitor::class, 'connections' => ['db', 'mongodb']], $env + ['QM_FAULT' => 'mongodb-normalizer-factory']);

        self::assertSame($this->scenarioOutput($healthy), $this->scenarioOutput($faulty), 'the failure in EVENT_AFTER_OPEN does not reach open()');
        $this->assertOnePackageError($faulty);
        $batch = $this->singleBatch($faulty);
        self::assertCount(1, $this->entriesWith($batch, 'qm_src_sql'), 'the normaliser is created only when a MongoDB connection opens, so bootstrap and SQL are not affected');
        self::assertSame([], $this->entriesWith($batch, 'qm_src_mongodb'));
    }

    public function testCommandsAfterFinalisationAndOfTheAdapterGiveNoEntry(): void
    {
        $result = $this->mongo(['mongodb'], ['QM_MONGO_USE' => 'mongodb', 'QM_MONGO_FINALIZE' => '1', 'QM_ADAPTER' => 'mongodb-query']);

        $batch = $this->singleBatch($result);
        self::assertCount(1, $this->entriesWith($batch, 'qm_src_mongodb '));
        self::assertSame([], $this->entriesWith($batch, 'qm_src_after_finalize'));
        self::assertSame([], $this->entriesWith($batch, 'qm_in_adapter'));
        self::assertCount(1, $result->adapterCalls);
        $this->assertNoErrors($result);
    }

    /**
     * @param list<string> $connections
     * @param array<string, string> $env
     */
    private function mongo(array $connections, array $env): RunResult
    {
        $this->requireDatabase('mongodb');

        return $this->scenario(self::ANY_DB, 'mongodb-source', ['connections' => $connections], $env);
    }
}
