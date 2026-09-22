<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\adapter;

use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\adapter\FileAdapterException;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use PHPUnit\Framework\TestCase;
use yii\helpers\FileHelper;

/**
 * YQM-13 and YQM-14: the file adapter of spec 03 §3 and ADR-0005, one process.
 *
 * Every test works in its own directory under sys_get_temp_dir() and records PHP warnings that were not
 * suppressed, because the adapter must report file problems only by its exception.
 */
final class FileAdapterTest extends TestCase
{
    /** Text in every batch's query; an exception message must never contain it. */
    public const MARKER = 'QM_MARKER_7f3a';

    private const CHILD = __DIR__ . '/fixtures/write-once.php';

    /** What `@` leaves in error_reporting() (PHP 8). */
    private const FATAL_LEVELS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    private string $dir;

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/qm-file-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        // An error masked by `@` goes on to PHP's own handler, which fills error_get_last() as it does
        // under Yii's ErrorHandler in an application; any other one is recorded as a failure. PHPUnit lowers
        // error_reporting() during a test, so `@` is recognised the way PHPUnit does it: only fatal levels left.
        set_error_handler(function (int $level, string $message): bool {
            if ((error_reporting() & ~self::FATAL_LEVELS) === 0) {
                return false;
            }
            $this->warnings[] = $message;

            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        FileHelper::removeDirectory($this->dir);
    }

    public function testWriteAppendsEachBatchAsOneJsonLineAndCreatesTheDirectory(): void
    {
        $path = $this->dir . '/missing/nested/queries.jsonl';
        $adapter = new FileAdapter($path);
        $first = self::batch('a', 1);
        $second = self::batch('b', 3);

        self::assertTrue($adapter->write($first));
        self::assertTrue($adapter->write($second));

        self::assertSame($first->toJson() . "\n" . $second->toJson() . "\n", file_get_contents($path));
        self::assertFileExists($path . '.lock');
        $this->assertNoWarning();
    }

    public function testBusyLockLosesTheBatchWithoutErrorAndLeavesTheFile(): void
    {
        $path = $this->dir . '/queries.jsonl';
        $adapter = new FileAdapter($path);
        $adapter->write(self::batch('a', 1));
        $before = file_get_contents($path);

        $holder = fopen($path . '.lock', 'c');
        self::assertIsResource($holder);
        self::assertTrue(flock($holder, LOCK_EX));
        try {
            self::assertFalse($adapter->write(self::batch('b', 1)));
            $adapter->send(self::batch('c', 1));
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }

        self::assertSame($before, file_get_contents($path));
        $this->assertNoWarning();
        self::assertTrue($adapter->write(self::batch('d', 1)), 'the lock is taken again once released');
    }

    public function testDirectoryUnderARegularFileThrowsFromSend(): void
    {
        touch($this->dir . '/file');
        $adapter = new FileAdapter($this->dir . '/file/logs/queries.jsonl');

        $message = $this->sendFailure($adapter);

        self::assertStringContainsString($this->dir . '/file/logs', $message);
        $this->assertNoWarning();
    }

    public function testFailedWriteThrowsFromSend(): void
    {
        $path = $this->dir . '/queries.jsonl';
        symlink('/dev/full', $path);

        $message = $this->sendFailure(new FileAdapter($path));

        self::assertStringContainsString('could not write to ' . $path . ': fwrite(): Write of ', $message);
        self::assertStringContainsString('No space left on device', $message);
        self::assertFileExists($path . '.lock');
        $this->assertNoWarning();
    }

    /**
     * A write cut short by the file size limit of the process (SIGXFSZ ignored, so fwrite() returns the
     * bytes it managed) is truncated back to the size before it. Runs in a child process, because the
     * limit cannot be lifted again.
     */
    public function testShortWriteIsTruncatedBackAndThrows(): void
    {
        $path = $this->dir . '/queries.jsonl';
        $first = self::batch('a', 1);
        (new FileAdapter($path))->write($first);
        $sizeBefore = (int) filesize($path);
        self::assertLessThan(1024, $sizeBefore);

        // dash counts `ulimit -f` in blocks of 512 bytes: the child may grow the file to 1024 bytes.
        $result = $this->writeInChild(['sh', '-c', sprintf(
            'trap "" XFSZ; ulimit -f 2; exec %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::CHILD),
            escapeshellarg($path),
        )]);

        self::assertSame(FileAdapterException::class, $result['class']);
        self::assertMatchesRegularExpression('/could not write the whole line to .*: \d+ of \d+ bytes written, file truncated back$/', $result['message']);
        self::assertStringNotContainsString(self::MARKER, $result['message']);
        clearstatcache();
        self::assertSame($sizeBefore, filesize($path));
        self::assertSame($first->toJson() . "\n", file_get_contents($path));
    }

    /**
     * is_dir() warns for a path outside open_basedir; the adapter reports it only by its exception.
     * Runs in a child process, because open_basedir cannot be widened again.
     */
    public function testPathOutsideOpenBasedirThrowsWithoutWarning(): void
    {
        mkdir($this->dir . '/base');
        $path = $this->dir . '/outside/queries.jsonl';

        $result = $this->writeInChild([
            PHP_BINARY,
            '-d', 'open_basedir=' . dirname(__DIR__, 3) . PATH_SEPARATOR . $this->dir . '/base',
            '-d', 'display_errors=stderr',
            '-d', 'error_reporting=' . E_ALL,
            self::CHILD,
            $path,
        ]);

        self::assertSame(FileAdapterException::class, $result['class']);
        self::assertStringStartsWith('File adapter could not create the directory ' . $this->dir . '/outside: ', $result['message']);
        self::assertStringNotContainsString(self::MARKER, $result['message']);
        self::assertDirectoryDoesNotExist($this->dir . '/outside');
    }

    public function testFileOfExactlyMaxSizeIsNotRotated(): void
    {
        $path = $this->dir . '/queries.jsonl';
        $batch = self::batch('a', 1);

        (new FileAdapter($path, strlen($batch->toJson()) + 1, 2))->write($batch);

        self::assertSame($batch->toJson() . "\n", file_get_contents($path));
        self::assertFileDoesNotExist($path . '.1');
    }

    public function testExceedingMaxSizeRotatesTheWholeFileAndKeepsAtMostMaxFilesCopies(): void
    {
        $path = $this->dir . '/queries.jsonl';
        $lines = [];
        for ($i = 1; $i <= 6; $i++) {
            $lines[$i] = self::batch("b{$i}", 1)->toJson() . "\n";
        }
        // Every line has the same length: the first write of a file fills it exactly, the second rotates it.
        $adapter = new FileAdapter($path, strlen($lines[1]), 2);

        $adapter->write(self::batch('b1', 1));
        $adapter->write(self::batch('b2', 1));
        self::assertFileDoesNotExist($path);
        self::assertSame($lines[1] . $lines[2], file_get_contents($path . '.1'));

        $adapter->write(self::batch('b3', 1));
        self::assertSame($lines[3], file_get_contents($path));

        $adapter->write(self::batch('b4', 1));
        self::assertSame($lines[3] . $lines[4], file_get_contents($path . '.1'));
        self::assertSame($lines[1] . $lines[2], file_get_contents($path . '.2'));

        $adapter->write(self::batch('b5', 1));
        $adapter->write(self::batch('b6', 1));
        self::assertSame($lines[5] . $lines[6], file_get_contents($path . '.1'));
        self::assertSame($lines[3] . $lines[4], file_get_contents($path . '.2'));
        self::assertFileDoesNotExist($path . '.3');
        self::assertSame(['queries.jsonl.1', 'queries.jsonl.2', 'queries.jsonl.lock'], $this->files());
        $this->assertNoWarning();
    }

    public function testBatchLargerThanMaxSizeGoesStraightToTheFirstCopy(): void
    {
        $path = $this->dir . '/queries.jsonl';
        $large = self::batch('large', 20);
        $small = self::batch('small', 1);
        $adapter = new FileAdapter($path, strlen($small->toJson()) + 1, 5);

        $adapter->write($large);
        self::assertSame($large->toJson() . "\n", file_get_contents($path . '.1'));
        self::assertFileDoesNotExist($path);

        $adapter->write($small);
        self::assertSame($small->toJson() . "\n", file_get_contents($path));
        self::assertSame($large->toJson() . "\n", file_get_contents($path . '.1'));
    }

    public function testFirstCopyThatCannotBeReplacedThrows(): void
    {
        $path = $this->dir . '/queries.jsonl';
        mkdir($path . '.1');
        touch($path . '.1/keep');

        $message = $this->sendFailure(new FileAdapter($path, 1, 1));

        self::assertStringContainsString($path . '.1', $message);
        $this->assertNoWarning();
    }

    /**
     * A batch whose `id` names it, with `$entries` entries carrying {@see self::MARKER}.
     */
    public static function batch(string $id, int $entries): QueryBatch
    {
        $queries = [];
        for ($i = 0; $i < $entries; $i++) {
            $queries[] = QueryEntry::success('mysql', 'db', 'SELECT', "SELECT * FROM t WHERE c = ? /* " . self::MARKER . " {$i} */", 1.5);
        }

        return new QueryBatch('app', BatchType::Http, $id, 1, null, 'site', 'index', new \DateTimeImmutable('2026-09-22T09:41:05Z'), 'host', 0, $queries);
    }

    /**
     * Sends a batch expecting the adapter's exception; returns its message, which never quotes the batch.
     */
    private function sendFailure(FileAdapter $adapter): string
    {
        try {
            $adapter->send(self::batch('failing', 2));
        } catch (FileAdapterException $e) {
            self::assertSame(FileAdapterException::class, $e::class);
            self::assertStringNotContainsString(self::MARKER, $e->getMessage());

            return $e->getMessage();
        }
        self::fail('send() did not throw');
    }

    /**
     * Runs {@see self::CHILD} and returns the exception it printed; the child must exit cleanly and
     * print nothing on stderr, where PHP would display a warning.
     *
     * @param list<string> $command
     *
     * @return array<string, mixed>
     */
    private function writeInChild(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, "stdout: {$stdout}\nstderr: {$stderr}");
        self::assertSame('', $stderr);
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    private function assertNoWarning(): void
    {
        self::assertSame([], $this->warnings, 'PHP warnings not suppressed by the adapter');
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = array_values(array_diff((array) scandir($this->dir), ['.', '..']));
        sort($files);

        return array_map('strval', $files);
    }
}
