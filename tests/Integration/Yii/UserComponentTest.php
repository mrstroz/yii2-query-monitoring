<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\tests\app\RunResult;
use mrstroz\querymonitoring\tests\app\UserProbe;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-60, spec 01 §5.7, spec 02 §1: the `user` setting of the component through a real `yii\web\User`.
 *
 * QM_SESSION_USER starts the request logged in through a session the application has not read yet, and
 * QM_AFTER_REQUEST=1 writes, after the package's finalisation, whether the session was opened, how many identity
 * lookups ran and whether the component exists and holds an identity. A wrong setting disables the package in
 * bootstrap, before any connection is measured, so that case runs on one database.
 */
final class UserComponentTest extends IntegrationTestCase
{
    private const PROBE = UserProbe::class;

    #[DataProvider('provideDatabaseCases')]
    public function testWithoutTheSettingEveryBatchHasUserNullAndNothingIsRead(string $db): void
    {
        $result = $this->scenario($db, 'user-untouched', [], $this->loggedInAs('42'));

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertSame(4, $batch['v']);
        self::assertSame(['host', 'user', 'dropped'], $this->keysAround($batch, 'user'));
        self::assertNull($batch['user']);
        self::assertCount(1, $this->entriesWith($batch, 'qm_user_untouched'));
        self::assertSame(
            ['userComponentCreated' => false, 'sessionActive' => false, 'findIdentityCalls' => 0, 'identityLoaded' => false],
            $this->afterRequest($result),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLoadedIdentityCases(): iterable
    {
        foreach (self::provideDatabaseCases() as $name => [$db]) {
            yield "{$name}, an int id" => [$db, '42'];
            yield "{$name}, a string id" => [$db, 'u-7'];
            yield "{$name}, a MongoDB ObjectId id" => [$db, 'oid:65f000000000000000000001'];
        }
    }

    #[DataProvider('provideLoadedIdentityCases')]
    public function testIdentityTheApplicationLoadedGivesItsIdAsAString(string $db, string $id): void
    {
        $result = $this->scenario($db, 'user-touched', ['user' => true], $this->loggedInAs($id));

        $output = $this->scenarioOutput($result);
        self::assertIsArray($output);
        self::assertFalse($output['isGuest'], 'the application sees the logged-in user');
        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertSame(str_replace('oid:', '', $id), $batch['user'], 'an int id and an ObjectId are written as strings');
        self::assertCount(1, $this->entriesWith($batch, 'qm_user_touched'));
        self::assertSame(1, $this->afterRequest($result)['findIdentityCalls'], 'only the lookup of the application');
    }

    #[DataProvider('provideDatabaseCases')]
    public function testIdentityTheApplicationDidNotLoadGivesNullWithoutLoadingIt(string $db): void
    {
        $result = $this->scenario($db, 'user-untouched', ['user' => true], $this->loggedInAs('42'));

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertNull($batch['user']);
        self::assertSame('qmtestsession0001', $result->runtimeFiles['session-id.txt'] ?? null, 'the session cookie was there');
        self::assertSame(
            ['userComponentCreated' => false, 'sessionActive' => false, 'findIdentityCalls' => 0, 'identityLoaded' => false],
            $this->afterRequest($result),
        );
    }

    #[DataProvider('provideDatabaseCases')]
    public function testCreatedComponentWithoutALoadedIdentityGivesNullWithoutLoadingIt(string $db): void
    {
        $result = $this->scenario($db, 'user-component-only', ['user' => true], $this->loggedInAs('42'));

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertNull($batch['user']);
        self::assertSame(
            ['userComponentCreated' => true, 'sessionActive' => false, 'findIdentityCalls' => 0, 'identityLoaded' => false],
            $this->afterRequest($result),
        );
    }

    #[DataProvider('provideDatabaseCases')]
    public function testGuestGivesNull(string $db): void
    {
        $result = $this->scenario($db, 'user-touched', ['user' => true], ['QM_AFTER_REQUEST' => '1']);

        $output = $this->scenarioOutput($result);
        self::assertIsArray($output);
        self::assertTrue($output['isGuest']);
        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertNull($batch['user']);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testLogoutInTheActionGivesNull(string $db): void
    {
        $result = $this->scenario($db, 'user-logout', ['user' => true], $this->loggedInAs('42'));

        self::assertSame(['db' => 603, 'before' => 42, 'after' => null], $this->normalisedOutput($result));
        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertNull($batch['user'], 'the value is read when the batch is sent, after the logout');
    }

    #[DataProvider('provideDatabaseCases')]
    public function testLoginInTheActionGivesTheNewId(string $db): void
    {
        $result = $this->scenario($db, 'user-login', ['user' => true], ['QM_AFTER_REQUEST' => '1']);

        self::assertSame(['db' => 604, 'login' => true, 'id' => 'u-7'], $this->normalisedOutput($result));
        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertSame('u-7', $batch['user']);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testUserComponentOfAnotherClassGivesNullWithoutAnError(string $db): void
    {
        $result = $this->scenario($db, 'user-component-only', ['user' => true], ['QM_USER_COMPONENT' => 'other']);

        $output = $this->scenarioOutput($result);
        self::assertIsArray($output);
        self::assertSame(\yii\base\Component::class, $output['class']);
        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertNull($batch['user']);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testCallableGetsTheKeptBatchWithUserNullAndSampleSet(string $db): void
    {
        $result = $this->scenario($db, 'user-untouched', [
            'user' => [self::PROBE, 'recording'],
            'sampling' => ['rate' => 1],
        ]);

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertSame('u-7', $batch['user']);
        self::assertSame(
            [['type' => 'http', 'id' => $batch['id'], 'seq' => 1, 'sample' => true, 'user' => null]],
            $this->userCalls($result),
        );
    }

    #[DataProvider('provideDatabaseCases')]
    public function testCallableIsNotCalledForABatchSkippedBySampling(string $db): void
    {
        $result = $this->scenario($db, 'user-untouched', [
            'user' => [self::PROBE, 'recording'],
            'sampling' => ['rate' => 0, 'keepErrors' => true],
        ]);

        $this->assertProcessOk($result);
        self::assertSame([], $result->batches);
        self::assertSame([], $this->userCalls($result));
        $this->assertNoErrors($result);
    }

    #[DataProvider('provideDatabaseCases')]
    public function testQueriesOfTheSourceAreNotEntries(string $db): void
    {
        $result = $this->scenario($db, 'user-untouched', ['user' => [self::PROBE, 'sqlLookup']]);

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertSame('42', $batch['user'], 'the source ran its query');
        self::assertCount(1, $batch['queries'], 'its query is not an entry');
        self::assertCount(1, $this->entriesWith($batch, 'qm_user_untouched'));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function provideAcceptedValueCases(): iterable
    {
        foreach (self::provideDatabaseCases() as $name => [$db]) {
            yield "{$name}, an int" => [$db, 'int42', '42'];
            yield "{$name}, a negative int" => [$db, 'negative', '-5'];
            yield "{$name}, 64 ASCII characters" => [$db, 'max64', str_repeat('m', 64)];
            yield "{$name}, an empty string" => [$db, 'empty', null];
            yield "{$name}, null" => [$db, 'none', null];
            yield "{$name}, an ObjectId" => [$db, 'objectid', '65f000000000000000000001'];
            yield "{$name}, a Stringable object" => [$db, 'stringable', 'u-7'];
        }
    }

    #[DataProvider('provideAcceptedValueCases')]
    public function testAcceptedValueOfTheSource(string $db, string $case, ?string $expected): void
    {
        $result = $this->scenario($db, 'user-untouched', ['user' => [self::PROBE, 'byCase']], ['QM_USER_CASE' => $case]);

        $batch = $this->singleBatch($result);
        $this->assertNoErrors($result);
        self::assertSame($expected, $batch['user']);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideRejectedValueCases(): iterable
    {
        foreach (self::provideDatabaseCases() as $name => [$db]) {
            yield "{$name}, an array" => [$db, 'array', 'got array', 'qm-user-marker'];
            yield "{$name}, a float" => [$db, 'float', 'got float', '4.2'];
            yield "{$name}, 65 ASCII characters" => [$db, 'over65', 'UserValueException: QueryMonitor::$user must give an int, null, a Stringable or a valid UTF-8 string of at most 66 bytes as JSON; got a string longer than 64 bytes.', str_repeat('o', 20)];
            yield "{$name}, 100 invalid bytes" => [$db, 'longbadutf8', 'got a string longer than 64 bytes.', "\xC3\xC3"];
            yield "{$name}, 63 ASCII characters and a quote" => [$db, 'quote63', 'got a string longer than 66 bytes as JSON.', str_repeat('q', 20)];
            yield "{$name}, invalid UTF-8" => [$db, 'badutf8', 'got an invalid UTF-8 string.', 'qm-user-'];
            yield "{$name}, an exception" => [$db, 'throw', 'in user with RuntimeException', 'qm-user-marker'];
            yield "{$name}, a configuration error of the source" => [$db, 'configthrow', 'in user with yii\\base\\InvalidConfigException', 'qm-user-marker'];
            yield "{$name}, a throwing __toString" => [$db, 'badtostring', 'in user with RuntimeException', 'qm-user-marker'];
        }
    }

    #[DataProvider('provideRejectedValueCases')]
    public function testRejectedValueGivesNullAndOneErrorWithoutTheValue(string $db, string $case, string $reason, string $value): void
    {
        $without = $this->scenarioOutput($this->scenario($db, 'user-untouched', ['enabled' => false]));
        $result = $this->scenario($db, 'user-untouched', ['user' => [self::PROBE, 'byCase']], ['QM_USER_CASE' => $case]);

        self::assertSame($without, $this->scenarioOutput($result), 'the application answers as without the package');
        $batch = $this->singleBatch($result);
        self::assertNull($batch['user']);
        self::assertCount(1, $this->entriesWith($batch, 'qm_user_untouched'), 'the batch is sent');
        $this->assertOnePackageError($result);
        $message = $this->errors($result)[0]['message'];
        self::assertStringContainsString('in user with', $message);
        self::assertStringContainsString($reason, $message);
        self::assertStringNotContainsString($value, $message, 'the log never carries the value');
    }

    #[DataProvider('provideDatabaseCases')]
    public function testConsoleBatchesHaveUserNullWithTheDefaultSource(string $db): void
    {
        $result = $this->consoleScenario($db, 'console-queries', ['user' => true, 'maxEntries' => 2], ['QM_QUERIES' => '5']);

        $this->assertProcessOk($result);
        $this->assertNoErrors($result);
        self::assertCount(3, $result->batches);
        self::assertSame([null, null, null], array_column($result->batches, 'user'));
        self::assertSame(['console', 'console', 'console'], array_column($result->batches, 'type'));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testCallableIsCalledOnceForEverySentConsoleBatch(string $db): void
    {
        $result = $this->consoleScenario($db, 'console-queries', ['user' => [self::PROBE, 'recording'], 'maxEntries' => 2], ['QM_QUERIES' => '5']);

        $this->assertProcessOk($result);
        $this->assertNoErrors($result);
        self::assertSame(['u-7', 'u-7', 'u-7'], array_column($result->batches, 'user'));
        $calls = $this->userCalls($result);
        self::assertSame([1, 2, 3], array_column($calls, 'seq'));
        self::assertSame(array_column($result->batches, 'id'), array_column($calls, 'id'));
        self::assertSame([null, null, null], array_column($calls, 'user'));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideEndCases(): iterable
    {
        foreach (self::provideDatabaseCases() as $name => [$db]) {
            yield "{$name}, exit in the action" => [$db, 'exit', 0];
            yield "{$name}, an unhandled exception" => [$db, 'throw', 1];
        }
    }

    /**
     * ADR-0003: a request that ends without EVENT_AFTER_REQUEST is finalised in the shutdown fallback, where the source
     * is read the same way.
     */
    #[DataProvider('provideEndCases')]
    public function testSourceIsReadWhenTheRequestIsFinalisedInShutdown(string $db, string $end, int $exitCode): void
    {
        $result = $this->scenario($db, 'lifecycle', ['user' => [self::PROBE, 'recording']], ['QM_T1_END' => $end]);

        self::assertSame($exitCode, $result->exitCode, "stderr: {$result->stderr}");
        $batch = $this->singleBatch($result);
        self::assertSame('u-7', $batch['user']);
        self::assertCount(1, $this->entriesWith($batch, 'qm_life_action'));
        self::assertSame([['type' => 'http', 'id' => $batch['id'], 'seq' => 1, 'sample' => false, 'user' => null]], $this->userCalls($result));
    }

    /**
     * An HTTP batch is sent once the request is finalised, when no context takes entries anyway; a console process
     * keeps running after it sends a batch, so this is where a query of the source could become an entry.
     */
    #[DataProvider('provideDatabaseCases')]
    public function testQueriesOfTheSourceAreNotEntriesOfTheNextConsoleBatch(string $db): void
    {
        $result = $this->consoleScenario($db, 'console-queries', ['user' => [self::PROBE, 'sqlLookup'], 'maxEntries' => 2], ['QM_QUERIES' => '5']);

        $this->assertProcessOk($result);
        $this->assertNoErrors($result);
        self::assertSame(['42', '42', '42'], array_column($result->batches, 'user'));
        $queries = array_merge(...array_column($result->batches, 'queries'));
        self::assertSame(
            ['SELECT ? AS qm_c_1', 'SELECT ? AS qm_c_2', 'SELECT ? AS qm_c_3', 'SELECT ? AS qm_c_4', 'SELECT ? AS qm_c_5'],
            array_column($queries, 'query'),
        );
    }

    #[DataProvider('provideDatabaseCases')]
    public function testFailingSourceDoesNotHideALaterAdapterError(string $db): void
    {
        $result = $this->consoleScenario(
            $db,
            'console-queries',
            ['user' => [self::PROBE, 'byCase'], 'maxEntries' => 2],
            ['QM_QUERIES' => '5', 'QM_USER_CASE' => 'throw', 'QM_ADAPTER' => 'throw-on-2'],
        );

        $this->assertProcessOk($result);
        self::assertSame([1, 3], array_column($result->batches, 'seq'), 'the second call of the adapter failed');
        self::assertSame([null, null], array_column($result->batches, 'user'));
        $messages = array_column($this->errors($result), 'message');
        self::assertCount(2, $messages, 'one error of the source, one of the adapter: ' . json_encode($messages));
        self::assertStringContainsString('in user with RuntimeException', $messages[0]);
        self::assertStringContainsString('in send with RuntimeException', $messages[1]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideWrongUserCases(): iterable
    {
        yield 'false' => [false];
        yield 'one' => [1];
        yield 'a text that names no function' => ['qm_no_such_function'];
        yield 'a non-static method' => [[self::PROBE, 'nonStatic']];
        yield 'a method of an unknown class' => [['qm\\NoSuchClass', 'of']];
        yield 'a list' => [['a', 'b', 'c']];
    }

    #[DataProvider('provideWrongUserCases')]
    public function testWrongSettingDisablesThePackage(mixed $user): void
    {
        $without = $this->scenarioOutput($this->scenario(self::ANY_DB, 'user-untouched', ['enabled' => false]));
        $result = $this->scenario(self::ANY_DB, 'user-untouched', ['user' => $user]);

        self::assertSame($without, $this->scenarioOutput($result));
        self::assertSame([], $result->batches);
        $this->assertOnePackageError($result);
        self::assertStringContainsString('QueryMonitor::$user must be null, true or a callable.', $this->errors($result)[0]['message']);
    }

    /**
     * @return array<string, string>
     */
    private function loggedInAs(string $id): array
    {
        return ['QM_SESSION_USER' => $id, 'QM_AFTER_REQUEST' => '1'];
    }

    /**
     * @return array<string, mixed>
     */
    private function afterRequest(RunResult $result): array
    {
        self::assertArrayHasKey('after-request.json', $result->runtimeFiles, 'stderr: ' . $result->stderr);
        $state = json_decode($result->runtimeFiles['after-request.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        return $state;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function userCalls(RunResult $result): array
    {
        $lines = array_filter(explode("\n", $result->runtimeFiles['user-calls.jsonl'] ?? ''));

        return array_values(array_map(static function (string $line): array {
            $call = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($call);

            return $call;
        }, $lines));
    }

    /**
     * The scenario output with the database's answer as an int: PDO gives a string in one driver and an int in another.
     *
     * @return array<string, mixed>
     */
    private function normalisedOutput(RunResult $result): array
    {
        $output = $this->scenarioOutput($result);
        self::assertIsArray($output);
        $output['db'] = (int) $output['db'];

        return $output;
    }

    /**
     * The key before `$key`, `$key` and the key after it in the batch.
     *
     * @param array<string, mixed> $batch
     *
     * @return list<string>
     */
    private function keysAround(array $batch, string $key): array
    {
        $keys = array_keys($batch);
        $at = array_search($key, $keys, true);
        self::assertIsInt($at);

        return array_slice($keys, $at - 1, 3);
    }
}
