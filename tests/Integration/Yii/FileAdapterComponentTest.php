<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\tests\Integration\support\JsonLines;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-15: without `adapter` the component writes to a file set by `file` (spec 01 §1, 03 §3). Every run of
 * the test application has its own `@runtime`, returned in RunResult::$runtimeFiles.
 */
final class FileAdapterComponentTest extends IntegrationTestCase
{
    private const DEFAULT_FILE = 'logs/query-monitoring.jsonl';

    #[DataProvider('provideDatabaseCases')]
    public function testWithoutAdapterTheBatchGoesToTheDefaultFile(string $db): void
    {
        $result = $this->scenario($db, 'file-adapter', ['adapter' => null]);

        $settings = $this->scenarioOutput($result);
        $this->assertNoErrors($result);
        self::assertSame([
            'path' => $settings['runtime'] . '/' . self::DEFAULT_FILE,
            'maxSize' => FileAdapter::DEFAULT_MAX_SIZE,
            'maxFiles' => FileAdapter::DEFAULT_MAX_FILES,
        ], array_diff_key($settings, ['runtime' => true]));
        self::assertSame([], $result->batches, 'the capturing adapter is not used');
        self::assertSame([self::DEFAULT_FILE, self::DEFAULT_FILE . '.lock'], array_keys($result->runtimeFiles));

        $batch = $this->singleLine($result->runtimeFiles[self::DEFAULT_FILE]);
        self::assertSame(self::APP, $batch['app']);
        self::assertCount(1, $this->entriesWith($batch, 'qm_file_adapter'));
    }

    /**
     * Each key of `file` overrides only itself.
     *
     * @return iterable<string, array{string, array<string, mixed>, string, int, int}>
     */
    public static function provideOverrideCases(): iterable
    {
        foreach (self::provideDatabaseCases() as [$db]) {
            yield "{$db}: path with an alias" => [$db, ['path' => '@runtime/custom/queries.jsonl'], 'custom/queries.jsonl', FileAdapter::DEFAULT_MAX_SIZE, FileAdapter::DEFAULT_MAX_FILES];
            yield "{$db}: maxSize" => [$db, ['maxSize' => 1024], self::DEFAULT_FILE, 1024, FileAdapter::DEFAULT_MAX_FILES];
            yield "{$db}: maxFiles" => [$db, ['maxFiles' => 2], self::DEFAULT_FILE, FileAdapter::DEFAULT_MAX_SIZE, 2];
        }
    }

    /**
     * @param array<string, mixed> $file
     */
    #[DataProvider('provideOverrideCases')]
    public function testEachFileKeyOverridesOnlyItself(string $db, array $file, string $relativePath, int $maxSize, int $maxFiles): void
    {
        $result = $this->scenario($db, 'file-adapter', ['adapter' => null, 'file' => $file]);

        $settings = $this->scenarioOutput($result);
        $this->assertNoErrors($result);
        self::assertSame($settings['runtime'] . '/' . $relativePath, $settings['path']);
        self::assertSame($maxSize, $settings['maxSize']);
        self::assertSame($maxFiles, $settings['maxFiles']);
        self::assertArrayHasKey($relativePath, $result->runtimeFiles);
        $this->singleLine($result->runtimeFiles[$relativePath]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideBadFileSettingCases(): iterable
    {
        yield 'empty path' => [['path' => '']];
        yield 'maxSize 0' => [['maxSize' => 0]];
        yield 'maxFiles 0' => [['maxFiles' => 0]];
        yield 'maxSize not an integer' => [['maxSize' => '1024']];
        yield 'unknown key' => [['maxsize' => 1024]];
        yield 'unknown alias' => [['path' => '@nowhere/queries.jsonl']];
    }

    /**
     * @param array<string, mixed> $file
     */
    #[DataProvider('provideBadFileSettingCases')]
    public function testBadFileSettingDisablesThePackageBeforeTheCommandSwap(array $file): void
    {
        $db = self::ANY_DB;

        $component = ['adapter' => null, 'file' => $file];
        $without = $this->scenario($db, 'per-connection', ['enabled' => false]);
        $result = $this->scenario($db, 'per-connection', $component);

        $this->assertProcessOk($result);
        self::assertSame($without->stdout, $result->stdout, 'the application answers as without the package');
        self::assertSame([], $result->runtimeFiles, 'nothing written');
        $this->assertOnePackageError($result);
        self::assertStringContainsString('failed in bootstrap with yii\base\InvalidConfigException: QueryMonitor::$file', $this->errors($result)[0]['message']);

        $classes = $this->scenarioOutput($this->scenario($db, 'command-classes', $component));
        self::assertSame(\yii\db\Command::class, $classes['db'], 'no Command replacement when disabled');
    }

    #[DataProvider('provideDatabaseCases')]
    public function testOwnAdapterIgnoresAWrongFileSetting(string $db): void
    {
        $result = $this->scenario($db, 'per-connection', ['file' => ['path' => '', 'unknown' => true]]);

        $this->assertNoErrors($result);
        self::assertCount(1, $this->entriesWith($this->singleBatch($result), 'qm_on_db'));
        self::assertSame([], $result->runtimeFiles);
    }

    /**
     * The only line of a file written by the adapter, decoded.
     *
     * @return array<string, mixed>
     */
    private function singleLine(string $content): array
    {
        $batches = JsonLines::decode($content);
        self::assertCount(1, $batches, 'the component writes exactly one line');

        return $batches[0];
    }
}
