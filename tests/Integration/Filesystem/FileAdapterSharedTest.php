<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Filesystem;

use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\adapter\FileAdapterException;
use mrstroz\querymonitoring\tests\Integration\support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * YQM-54, spec 03 §3 and ADR-0005: files shared by the web server and console users. New files get
 * `fileMode`/`dirMode` whatever the umask; a rotation that fails keeps every archived copy, and the
 * current file does not grow past `maxSize` and one batch while rotation keeps failing.
 *
 * A directory without write permission makes `rename()` fail for a process that is not root, the way it
 * fails for the second user when the group has no write access. Linux, not root (the compose image user).
 */
final class FileAdapterSharedTest extends TestCase
{
    use TemporaryDirectory;

    private int $umask;

    protected function setUp(): void
    {
        $this->dir = self::temporaryPath('shared');
        mkdir($this->dir);
        $this->umask = umask(0o022);
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        $this->removeTemporaryDirectory();
    }

    public function testNewFilesAndDirectoriesGetTheConfiguredModesDespiteTheUmask(): void
    {
        $path = $this->dir . '/logs/nested/queries.jsonl';
        $adapter = new FileAdapter($path, fileMode: 0o664, dirMode: 0o775);

        $adapter->send(FileAdapterTest::batch('a', 1));

        self::assertSame(0o775, fileperms($this->dir . '/logs') & 0o7777);
        self::assertSame(0o775, fileperms($this->dir . '/logs/nested') & 0o7777);
        self::assertSame(0o664, fileperms($path) & 0o7777);
        self::assertSame(0o664, fileperms($path . '.lock') & 0o7777);
    }

    public function testArchivedCopyCreatedByRotationGetsTheFileMode(): void
    {
        $path = $this->dir . '/queries.jsonl';
        $line = FileAdapterTest::batch('a', 1)->toJson() . "\n";
        $adapter = new FileAdapter($path, maxSize: strlen($line), fileMode: 0o660, dirMode: 0o770);

        $adapter->send(FileAdapterTest::batch('a', 1));
        $adapter->send(FileAdapterTest::batch('b', 1));
        $adapter->send(FileAdapterTest::batch('c', 1));

        self::assertFileExists($path . '.1');
        self::assertSame(0o660, fileperms($path . '.1') & 0o7777);
        self::assertSame(0o660, fileperms($path) & 0o7777);
    }

    public function testFailedRotationKeepsEveryArchivedCopyAndDoesNotAppend(): void
    {
        $path = $this->dir . '/queries.jsonl';
        $maxSize = 1000;
        // The previous rotation failed: the current file is already over maxSize.
        file_put_contents($path, str_repeat("{\"old\":true}\n", 100));
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents("{$path}.{$i}", "{\"copy\":{$i}}\n");
        }
        touch($path . '.lock');
        $before = $this->hashes($path);
        chmod($this->dir, 0o555);
        $adapter = new FileAdapter($path, maxSize: $maxSize, maxFiles: 3);

        $message = $this->sendFailure($adapter);

        self::assertStringContainsString($path, $message);
        self::assertSame($before, $this->hashes($path), 'no copy removed, nothing appended to the current file');
    }

    public function testCurrentFileStopsGrowingWhileRotationKeepsFailing(): void
    {
        $path = $this->dir . '/queries.jsonl';
        $line = FileAdapterTest::batch('a', 1)->toJson() . "\n";
        $maxSize = 3 * strlen($line);
        file_put_contents($path, str_repeat($line, 3));
        touch($path . '.lock');
        chmod($this->dir, 0o555);
        $adapter = new FileAdapter($path, maxSize: $maxSize, maxFiles: 2);

        $failures = 0;
        for ($i = 0; $i < 5; $i++) {
            try {
                $adapter->send(FileAdapterTest::batch('a', 1));
            } catch (FileAdapterException) {
                $failures++;
            }
        }

        clearstatcache();
        self::assertSame(5, $failures, 'every send reports the failed rotation');
        self::assertLessThanOrEqual($maxSize + strlen($line), filesize($path), 'at most one batch over maxSize');
    }

    /**
     * @return array<string, string> sha256 of the current file and each copy, by name
     */
    private function hashes(string $path): array
    {
        clearstatcache();
        $hashes = [];
        foreach (glob($path . '*') ?: [] as $file) {
            if (!str_ends_with($file, '.lock')) {
                $hashes[basename($file)] = (string) hash_file('sha256', $file);
            }
        }
        ksort($hashes);

        return $hashes;
    }

    private function sendFailure(FileAdapter $adapter): string
    {
        try {
            $adapter->send(FileAdapterTest::batch('failing', 1));
        } catch (FileAdapterException $e) {
            return $e->getMessage();
        }
        self::fail('send() did not throw');
    }
}
