<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\QueryMonitor;
use mrstroz\querymonitoring\tests\app\RunResult;
use mrstroz\querymonitoring\tests\app\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-11, YQM-15, YQM-40, YQM-55: the SQL, MongoDB, console, queue worker and consumer examples in README.md run
 * as written and write their batches to the default file, and the option table matches the component.
 */
final class ReadmeExampleTest extends IntegrationTestCase
{
    private const MARKER = '<!-- example:sql-config -->';
    private const MONGODB_MARKER = '<!-- example:mongodb-config -->';
    private const CONSOLE_MARKER = '<!-- example:console-config -->';
    private const QUEUE_MARKER = '<!-- example:queue-config -->';

    #[DataProvider('provideDatabaseCases')]
    public function testConfigurationExampleRunsInTheTestApplication(string $db): void
    {
        $example = $this->example(self::MARKER);
        self::assertIsArray($example['bootstrap'] ?? null);
        self::assertContains('queryMonitor', $example['bootstrap']);
        $component = $example['components']['queryMonitor'] ?? null;
        self::assertIsArray($component);
        self::assertSame(QueryMonitor::class, $component['class']);
        self::assertArrayNotHasKey('adapter', $component, 'the example uses the default file adapter');

        $this->requireDatabase($db);
        Schema::create(Schema::connection($db));
        // The test application's own default is the capturing adapter; the example's missing key means null.
        $result = $this->request($db, 'order/index', ['adapter' => null] + $component);

        $this->assertProcessOk($result);
        $this->assertNoErrors($result);
        self::assertSame([], $result->batches);
        $file = $result->runtimeFiles['logs/query-monitoring.jsonl'] ?? '';
        self::assertStringEndsWith("\n", $file);
        self::assertStringNotContainsString("\n", rtrim($file, "\n"), 'one batch, one line');
        $batch = json_decode($file, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($batch);
        self::assertSame($component['app'], $batch['app']);
        self::assertNotEmpty($batch['queries']);
        self::assertSame($component['connections'], array_values(array_unique(array_column($batch['queries'], 'conn'))));
    }

    public function testMongoDbExampleGivesOneBatchWithEntriesOfBothSources(): void
    {
        $component = $this->example(self::MONGODB_MARKER)['components']['queryMonitor'] ?? null;
        self::assertIsArray($component);
        self::assertSame(['db', 'mongodb'], $component['connections']);
        $this->requireDatabase('mongodb');

        $result = $this->scenario(self::ANY_DB, 'mixed', ['adapter' => null] + $component);

        $this->assertProcessOk($result);
        $this->assertNoErrors($result);
        $batch = json_decode($result->runtimeFiles['logs/query-monitoring.jsonl'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($batch);
        self::assertSame(['db', 'mongodb', 'db', 'mongodb'], array_column($this->entriesWith($batch, 'qm_mixed_'), 'conn'));
    }

    public function testConsoleExampleSendsALongCommandInBatchesWhileItRuns(): void
    {
        $example = $this->example(self::CONSOLE_MARKER);
        self::assertContains('queryMonitor', $example['bootstrap'] ?? []);
        $component = $example['components']['queryMonitor'] ?? null;
        self::assertIsArray($component);
        self::assertArrayNotHasKey('adapter', $component, 'the example uses the default file adapter');

        $result = $this->consoleScenario(self::ANY_DB, 'console-queries', ['adapter' => null] + $component, ['QM_QUERIES' => '1200']);

        $this->assertProcessOk($result);
        $this->assertNoErrors($result);
        $batches = $this->fileBatches($result);
        self::assertSame([1, 2, 3], array_column($batches, 'seq'));
        self::assertSame([500, 500, 200], array_map(static fn(array $b): int => count($b['queries']), $batches));
        self::assertSame(['console'], array_values(array_unique(array_column($batches, 'type'))));
        self::assertSame([$component['app']], array_values(array_unique(array_column($batches, 'app'))));
    }

    /**
     * The example's queue is `yii\queue\db\Queue`; the test application runs the same behavior configuration on a
     * file queue (tests/app/console/config.php), in a child process as `queue/run` does by default.
     */
    public function testQueueExampleMonitorsJobsOfAnExcludedWorker(): void
    {
        $example = $this->example(self::QUEUE_MARKER);
        self::assertContains('queue', $example['bootstrap'] ?? []);
        $component = $example['components']['queryMonitor'] ?? null;
        self::assertIsArray($component);
        self::assertSame(['queue/listen', 'queue/run', 'queue/exec'], $component['excludedRoutes']['console'] ?? null);
        self::assertSame(
            ['class' => \mrstroz\querymonitoring\queue\JobMonitorBehavior::class, 'queueName' => 'queue'],
            $example['components']['queue']['as queryMonitor'] ?? null,
            'the behavior as the test application attaches it',
        );
        $queuePath = sys_get_temp_dir() . '/qm-readme-queue-' . bin2hex(random_bytes(6));
        mkdir($queuePath);

        try {
            $push = $this->consoleScenario(self::ANY_DB, 'queue-push', ['enabled' => false], ['QM_QUEUE_PATH' => $queuePath, 'QM_JOBS' => '[["ok","readme_job"]]']);
            $this->assertProcessOk($push);
            $result = $this->command(self::ANY_DB, 'queue/run', [], ['adapter' => null] + $component, ['QM_QUEUE_PATH' => $queuePath]);
        } finally {
            \yii\helpers\FileHelper::removeDirectory($queuePath);
        }

        $this->assertProcessOk($result);
        $this->assertNoErrors($result);
        $batches = $this->fileBatches($result);
        self::assertCount(1, $batches, 'only the job; the worker and its child are excluded');
        self::assertSame(['job', 'queue/exec'], [$batches[0]['type'], $batches[0]['route']]);
        self::assertSame('queue', $batches[0]['job']['queue']);
        self::assertNotSame([], $this->entriesWith($batches[0], 'qm_readme_job'));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideConsumerCases(): iterable
    {
        yield 'job succeeds' => [false];
        yield 'job throws' => [true];
    }

    #[DataProvider('provideConsumerCases')]
    public function testConsumerExampleEndsTheJobAfterSuccessAndAfterAnException(bool $throws): void
    {
        $result = $this->consoleScenario(self::ANY_DB, 'readme-consumer', ['adapter' => null], ['QM_JOB_THROWS' => $throws ? '1' : '0']);

        self::assertSame(['caught' => $throws ? 'qm readme job failed' : null], $this->scenarioOutput($result), 'the job\'s exception reaches the caller unchanged');
        $this->assertNoErrors($result);
        $jobs = array_values(array_filter($this->fileBatches($result), static fn(array $b): bool => $b['type'] === 'job'));
        self::assertCount(1, $jobs);
        self::assertSame(['name' => 'SendInvoice', 'queue' => 'invoices', 'message_id' => 'm-1', 'attempt' => 2], $jobs[0]['job']);
        self::assertNotSame([], $this->entriesWith($jobs[0], 'qm_readme_consumer'));
    }

    public function testOptionTableListsEveryOptionWithItsDefault(): void
    {
        preg_match_all('/^\| `(\w+)` \| ([^|]+?) \|/m', $this->readme(), $rows, PREG_SET_ORDER);
        $table = [];
        foreach ($rows as [, $name, $default]) {
            $table[$name] = trim($default, " `");
        }

        $defaults = (new \ReflectionClass(QueryMonitor::class))->getDefaultProperties();
        $options = [];
        foreach ((new \ReflectionClass(QueryMonitor::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() === QueryMonitor::class && !$property->isStatic()) {
                $options[$property->getName()] = $defaults[$property->getName()];
            }
        }
        ksort($options);
        ksort($table);
        self::assertSame(array_keys($options), array_keys($table), 'README option table and the public properties of QueryMonitor');

        foreach ($options as $name => $default) {
            if ($table[$name] === 'required') {
                self::assertContains($default, ['', null], "{$name} is required, so it has no usable default");
                continue;
            }
            self::assertSame(json_encode($default), $table[$name], "default of {$name}");
        }
    }

    /**
     * The configuration returned by the README block.
     *
     * @return array<mixed>
     */
    private function example(string $marker): array
    {
        $readme = $this->readme();
        $at = strpos($readme, $marker);
        self::assertNotFalse($at, 'README has the example marker');
        $code = preg_match('/\G\s*```php\n(.*?)\n```/s', $readme, $block, 0, $at + strlen($marker)) === 1 ? $block[1] : null;
        self::assertNotNull($code, 'a php block right after the marker');

        $file = (string) tempnam(sys_get_temp_dir(), 'qm-readme-');
        try {
            file_put_contents($file, $code);
            $config = require $file;
        } finally {
            unlink($file);
        }
        self::assertIsArray($config);

        return $config;
    }

    /**
     * Batches the default file adapter wrote during the run, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function fileBatches(RunResult $result): array
    {
        $file = $result->runtimeFiles['logs/query-monitoring.jsonl'] ?? '';

        return array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $file), static fn(string $line): bool => $line !== '')),
        );
    }

    private function readme(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../README.md');
    }
}
