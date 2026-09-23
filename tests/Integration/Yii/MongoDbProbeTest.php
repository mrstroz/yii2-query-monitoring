<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\MongoProbeSubscriber;

/**
 * YQM-32: the probe of open question 2, run through `tests/Integration/scenarios/mongodb-probe.php` with
 * {@see MongoProbeSubscriber} and without the package. The assertions hold the answers written down in
 * ADR-0010 and in the risk table of docs/plan/roadmap.md; if the driver changes one of them, the test says which.
 *
 * `tests/fixtures/mongodb-commands.json` holds the command documents and replies of the probe as canonical
 * Extended JSON, without `lsid`, for the tests of YQM-34 and YQM-36; `probe` names the case that sent it.
 * It was written once from the probe; the test only checks that yii2-mongodb still sends commands with the
 * same top-level keys.
 */
final class MongoDbProbeTest extends IntegrationTestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/mongodb-commands.json';

    public function testSubscriberAddedAfterOpenSeesEveryOpenOfTheConnectionOnce(): void
    {
        $out = $this->probe('manager');

        self::assertSame(2, $out['managerOpens'], 'EVENT_AFTER_OPEN fires on the first open and again after close()');
        self::assertSame(2, $out['distinctManagers'], 'open() after close() creates a new Manager');
        self::assertNull($out['managerAfterClose'], 'close() drops the Manager');
        self::assertFalse($out['sameAfterReopen']);
        self::assertTrue($out['earlyHadManager'], 'a connection opened before subscribing already has its Manager');
        self::assertSame(['find'], $out['twice'], 'a second addSubscriber() of the same object on one Manager has no effect');
        // Two finds of the connection, and the third of the early connection with the same DSN (see the shared client test).
        self::assertSame(['find', 'find', 'find'], $out['atOpen']);
    }

    public function testManagersWithTheSameConfigurationShareTheSubscribersOfTheirClient(): void
    {
        $out = $this->probe('shared-client');

        $started = array_values(array_filter($out['seenByA'], static fn(array $event): bool => $event['event'] === 'started'));
        self::assertSame(['a', 'same-dsn'], array_column($started, 'from'), 'A sees its own find and the find of another Manager with the same DSN and options, not those with other options, without client persistence or with disableClientPersistence: false');
        self::assertSame([], $out['ordered'], 'the same options in another order give another client');
    }

    public function testSubscriberOfAConnectionThatIsNeverOpenedSeesNothingOfItsClient(): void
    {
        $out = $this->probe('shared-client');

        self::assertFalse($out['lazyAOpened']);
        self::assertSame([], $out['onlyA'], 'subscribed only in the EVENT_AFTER_OPEN of A, which the request never opens');
        $started = array_values(array_filter($out['shared'], static fn(array $event): bool => $event['event'] === 'started'));
        self::assertSame(['lazy-b'], array_column($started, 'from'), 'one object subscribed in the EVENT_AFTER_OPEN of both sees the find of B once');
    }

    public function testFixtureHoldsTheCommandsYii2MongoDbSends(): void
    {
        $out = $this->probe('documents');

        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        $sent = [];
        foreach ($out['events'] as $event) {
            if ($event['event'] === 'started') {
                $command = json_decode($event['command'], true, 512, JSON_THROW_ON_ERROR);
                unset($command['lsid']);
                $sent[] = [$event['name'], array_keys($command)];
            }
        }
        $expected = [];
        foreach ($fixture as $case) {
            if ($case['probe'] === 'documents') {
                $expected[] = [(string) array_key_first($case['command']), array_keys($case['command'])];
            }
        }
        self::assertSame($expected, $sent, 'command names and top-level keys, in the order the probe sends them');
    }

    public function testFirstApplicationFrameOfTheEndEventIsWithinTheLimit(): void
    {
        $out = $this->probe('stack');

        $succeeded = array_values(array_filter($out['events'], static fn(array $event): bool => $event['event'] === 'succeeded'));
        self::assertSame(['find', 'insert', 'count', 'find', 'find', 'getMore', 'count', 'find', 'find', 'find'], array_column($succeeded, 'name'));
        foreach ($succeeded as $event) {
            self::assertStringStartsWith('tests/Integration/scenarios/mongodb-probe.php:', $event['app'][0]['at'], "first application frame of {$event['name']}");
            // Deepest measured: 32 (GridView with with() two levels deep); the limit is 64 frames (ADR-0009).
            self::assertLessThan(64, $event['app'][0]['position'], "position of {$event['name']}");
        }
    }

    public function testFailPointGivesWriteConcernErrorAndStandaloneRejectsOtherWriteConcerns(): void
    {
        $out = $this->probe('errors');

        $replies = [];
        foreach ($out['events'] as $event) {
            if ($event['event'] !== 'started') {
                $reply = json_decode($event['reply'], true, 512, JSON_THROW_ON_ERROR);
                $replies[] = [$event['event'], $event['name'], isset($reply['writeErrors']), isset($reply['writeConcernError']), $event['code'] ?? null];
            }
        }
        self::assertSame([
            ['succeeded', 'delete', false, false, null],
            ['succeeded', 'insert', false, false, null],
            ['succeeded', 'insert', true, false, null],
            ['failed', 'insert', false, false, 2],
            ['failed', 'insert', false, false, 2],
            ['succeeded', 'configureFailPoint', false, false, null],
            ['succeeded', 'insert', false, true, null],
            ['succeeded', 'configureFailPoint', false, false, null],
            ['succeeded', 'insert', true, true, null],
            ['failed', 'find', false, false, 2],
        ], $replies, 'duplicate key, w: 2 and a tag on a standalone, failCommand, both errors, a rejected filter');
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(string $case): array
    {
        $this->requireDatabase('mongodb');
        $out = $this->scenarioOutput($this->scenario(self::ANY_DB, 'mongodb-probe', ['connections' => []], ['QM_PROBE_CASE' => $case]));
        self::assertIsArray($out);

        return $out;
    }
}
