<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use mrstroz\querymonitoring\adapter\FileAdapterException;
use mrstroz\querymonitoring\tests\app\RunResult;
use mrstroz\querymonitoring\tests\Integration\support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-17, spec 03 §2–§3 and 01 §6: a busy or unwritable file never changes what the application gets. A
 * busy lock loses the batch silently; a file error is one `Yii::error` with the adapter's own exception
 * and its message, so no PHP warning reached Yii's error handler (it would be logged as an ErrorException).
 */
final class FileAdapterProtectionTest extends IntegrationTestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        $this->dir = self::temporaryPath('protection');
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    #[DataProvider('provideDatabaseCases')]
    public function testLockHeldByAnotherProcessLosesTheBatchWithoutError(string $db): void
    {
        $working = $this->withFile($db, $this->dir . '/working/queries.jsonl');
        $this->assertNoErrors($working);
        self::assertFileExists($this->dir . '/working/queries.jsonl', 'the control run writes its batch');

        mkdir($this->dir . '/busy');
        $path = $this->dir . '/busy/queries.jsonl';
        $holder = fopen($path . '.lock', 'c');
        self::assertIsResource($holder);
        self::assertTrue(flock($holder, LOCK_EX));
        try {
            $busy = $this->withFile($db, $path);
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }

        $this->assertSameAnswer($working, $busy);
        self::assertFileDoesNotExist($path, 'no batch written');
        self::assertSame([], $busy->logs, 'no Yii::error or warning');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnwritableFileCases(): iterable
    {
        foreach (self::provideDatabaseCases() as [$db]) {
            yield "{$db}: directory without write permission" => [$db, 'read-only'];
            yield "{$db}: parent is a regular file" => [$db, 'file'];
        }
    }

    #[DataProvider('provideUnwritableFileCases')]
    public function testUnwritableFileLogsOneErrorAndChangesNothing(string $db, string $case): void
    {
        $working = $this->withFile($db, $this->dir . '/working/queries.jsonl');
        $this->assertNoErrors($working);

        if ($case === 'read-only') {
            mkdir($this->dir . '/read-only', 0o555);
            self::assertFalse(is_writable($this->dir . '/read-only'), 'needs a user without root rights, as in the container and CI (uid ' . getmyuid() . ')');
            $path = $this->dir . '/read-only/queries.jsonl';
        } else {
            touch($this->dir . '/file');
            $path = $this->dir . '/file/logs/queries.jsonl';
        }
        $failed = $this->withFile($db, $path);

        $this->assertSameAnswer($working, $failed);
        self::assertFileDoesNotExist($path);
        $this->assertOnePackageError($failed);
        self::assertCount(1, $failed->logs, 'no PHP warning logged next to the error: ' . json_encode($failed->logs));
        $message = $failed->logs[0]['message'];
        self::assertStringStartsWith('Query monitoring failed in send with ' . FileAdapterException::class . ': File adapter could not ', $message);
        self::assertStringContainsString($case === 'read-only' ? $path . '.lock' : dirname($path), $message);
        self::assertStringNotContainsString('qm_prot', $message, 'no query text');
    }

    private function withFile(string $db, string $path): RunResult
    {
        return $this->scenario($db, 'protection', ['adapter' => null, 'file' => ['path' => $path]]);
    }

    private function assertSameAnswer(RunResult $expected, RunResult $actual): void
    {
        $this->assertProcessOk($actual);
        self::assertSame($expected->stdout, $actual->stdout, 'the application answers as with a working file');
        self::assertSame('', $actual->stderr);
    }
}
