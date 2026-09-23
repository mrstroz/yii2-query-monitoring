<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\app\RunResult;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-8, spec 01 §4 and ADR-0003: one batch per request whichever way it ends, finalisation in
 * EVENT_AFTER_REQUEST or in the shutdown callback, nothing after it.
 */
final class FinalizationTest extends IntegrationTestCase
{
    /** Hooks of the `lifecycle` scenario that run on each end; after_send runs on `throw` inside error rendering. */
    private const HOOKS = [
        'return' => ['after_request', 'after_send', 'shutdown'],
        'end' => ['after_request', 'after_send', 'shutdown'],
        'exit' => ['shutdown'],
        'throw' => ['after_send', 'shutdown'],
    ];

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function provideEndCases(): iterable
    {
        foreach (self::provideDatabaseCases() as $name => [$db]) {
            yield "{$name} return" => [$db, 'return', 0];
            yield "{$name} end" => [$db, 'end', 0];
            yield "{$name} exit" => [$db, 'exit', 0];
            yield "{$name} throw" => [$db, 'throw', 1];
        }
    }

    #[DataProvider('provideEndCases')]
    public function testOneBatchWhateverTheEnd(string $db, string $end, int $exitCode): void
    {
        $result = $this->lifecycle($db, $end);

        self::assertSame($exitCode, $result->exitCode, "stdout: {$result->stdout}\nstderr: {$result->stderr}");
        $batch = $this->singleBatch($result);
        self::assertSame(1, $batch['seq']);
        self::assertCount(1, $this->entriesWith($batch, 'qm_life_action'));
        self::assertSame([], $this->packageErrors($result));
        if ($end !== 'throw') {
            self::assertSame(['action' => 801], json_decode($result->stdout, true));
        }
    }

    #[DataProvider('provideEndCases')]
    public function testQueriesAfterFinalisationGiveNoEntry(string $db, string $end, int $exitCode): void
    {
        $result = $this->lifecycle($db, $end);
        self::assertSame($exitCode, $result->exitCode, "stderr: {$result->stderr}");
        $batch = $this->singleBatch($result);

        self::assertSame(self::HOOKS[$end], $this->hooksRun($result), 'hook queries ran and succeeded');
        self::assertSame([], $this->entriesWith($batch, 'qm_life_after_request'));
        self::assertSame([], $this->entriesWith($batch, 'qm_life_shutdown'));
        // On `throw` the response is sent by the ErrorHandler before the shutdown finalisation, so its
        // query belongs to the request (spec 01 §4: queries from exception handling are listed).
        self::assertCount($end === 'throw' ? 1 : 0, $this->entriesWith($batch, 'qm_life_after_send'));
    }

    #[DataProvider('provideDatabaseCases')]
    public function testRequestWithoutQueriesSendsNothing(string $db): void
    {
        $result = $this->scenario($db, 'no-queries');

        $this->assertProcessOk($result);
        self::assertSame([], $result->batches);
        self::assertSame([], $result->adapterCalls);
        $this->assertNoErrors($result);
    }

    #[DataProvider('provideEndCases')]
    public function testThrowingAdapterIsNotCalledAgainInShutdown(string $db, string $end, int $exitCode): void
    {
        $result = $this->lifecycle($db, $end, ['QM_ADAPTER' => 'throw']);

        self::assertSame($exitCode, $result->exitCode, "stderr: {$result->stderr}");
        self::assertCount(1, $result->adapterCalls, 'send() once, not again from the shutdown callback');
        self::assertSame([], $result->batches);
        self::assertCount(1, $this->packageErrors($result));
        if ($end !== 'throw') {
            self::assertSame(['action' => 801], json_decode($result->stdout, true));
        }
    }

    /**
     * @param array<string, string> $env
     */
    private function lifecycle(string $db, string $end, array $env = []): RunResult
    {
        return $this->scenario($db, 'lifecycle', [], ['QM_T1_END' => $end] + $env);
    }

    /**
     * Names of the hooks whose query ran, from the `hook:<name>=<value>` lines on stderr.
     *
     * @return list<string>
     */
    private function hooksRun(RunResult $result): array
    {
        $expected = ['after_request' => '802', 'after_send' => '803', 'shutdown' => '804'];
        preg_match_all('/^hook:(\w+)=(\S*)$/m', $result->stderr, $matches, PREG_SET_ORDER);
        $run = [];
        foreach ($matches as [, $name, $value]) {
            self::assertSame($expected[$name] ?? null, $value, "value of hook {$name}");
            $run[] = $name;
        }

        return $run;
    }

    /**
     * @return list<array{level: string, category: string, message: string}>
     */
    private function packageErrors(RunResult $result): array
    {
        return array_values(array_filter($this->errors($result), static fn(array $log): bool => $log['category'] === Guard::LOG_CATEGORY));
    }
}
