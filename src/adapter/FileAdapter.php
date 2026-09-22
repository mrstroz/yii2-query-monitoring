<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\adapter;

use mrstroz\querymonitoring\batch\QueryBatch;
use yii\helpers\FileHelper;

/**
 * Default adapter: appends each batch as one JSON line to a file, with size rotation (spec 03 §3, ADR-0005).
 *
 * Writing and rotation share a non-blocking `flock` on `<path>.lock`. When another process holds it, the
 * batch is lost without an error, so a request never waits for monitoring. Any other file problem throws a
 * {@see FileAdapterException} whose message names the operation and the path, never the batch; PHP
 * warnings of the file functions are suppressed, the exception reports them.
 *
 * The constructor takes a resolved path and does not validate: {@see \mrstroz\querymonitoring\QueryMonitor}
 * checks the `file` setting. Nothing touches the file system before the first write.
 */
final class FileAdapter implements BatchAdapterInterface
{
    public const DEFAULT_PATH = '@runtime/logs/query-monitoring.jsonl';
    public const DEFAULT_MAX_SIZE = 10485760;
    public const DEFAULT_MAX_FILES = 5;

    /**
     * @param string $path file path without aliases; the lock is `$path . '.lock'`
     * @param int $maxSize bytes after which the file is rotated; a file of exactly this size is not
     * @param int $maxFiles number of archived copies `$path.1` … `$path.$maxFiles`
     */
    public function __construct(
        public readonly string $path,
        public readonly int $maxSize = self::DEFAULT_MAX_SIZE,
        public readonly int $maxFiles = self::DEFAULT_MAX_FILES,
    ) {}

    public function send(QueryBatch $batch): void
    {
        $this->write($batch);
    }

    /**
     * Appends the batch and rotates the file when it grew over `$maxSize`.
     *
     * @return bool false when the lock was busy and the batch was lost
     *
     * @throws FileAdapterException when the directory, the lock, the write or the rotation fails
     */
    public function write(QueryBatch $batch): bool
    {
        $line = $batch->toJson() . "\n";
        $this->ensureDirectory();

        $lockPath = $this->path . '.lock';
        error_clear_last();
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw $this->failure('open the lock file', $lockPath);
        }
        try {
            error_clear_last();
            if (!@flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if ($wouldBlock === 1) {
                    return false;
                }

                throw $this->failure('lock', $lockPath);
            }
            try {
                if ($this->append($line) > $this->maxSize) {
                    $this->rotate();
                }
            } finally {
                @flock($lock, LOCK_UN);
            }
        } finally {
            @fclose($lock);
        }

        return true;
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        error_clear_last();
        // Under `@` a mkdir lost to another process returns false instead of throwing, hence the second
        // is_dir(). is_dir() itself warns outside open_basedir.
        if (!@is_dir($directory) && !@FileHelper::createDirectory($directory) && !@is_dir($directory)) {
            throw $this->failure('create the directory', $directory);
        }
    }

    /**
     * Writes the line with one `fwrite` and returns the size of the file after it. A partial write is
     * truncated back to the size before it, so the next batch does not continue a broken line.
     */
    private function append(string $line): int
    {
        error_clear_last();
        $data = @fopen($this->path, 'a');
        if ($data === false) {
            throw $this->failure('open', $this->path);
        }
        try {
            // In append mode ftell() says 0 until the first write; fstat() reports the real size.
            $stat = @fstat($data);
            if ($stat === false) {
                throw $this->failure('read the size of', $this->path);
            }
            $before = max(0, $stat['size']);
            $length = strlen($line);
            error_clear_last();
            $written = @fwrite($data, $line);
            if ($written === $length) {
                return $before + $written;
            }
            if ($written === false || $written === 0) {
                throw $this->failure('write to', $this->path);
            }
            $restored = @ftruncate($data, $before);

            throw $this->failure('write the whole line to', $this->path, sprintf(
                '%d of %d bytes written, %s',
                $written,
                $length,
                $restored ? 'file truncated back' : 'truncating back failed',
            ));
        } finally {
            @fclose($data);
        }
    }

    /**
     * `$path` becomes `.1`, each `.N` becomes `.N+1` up to `.$maxFiles`, and the old `.$maxFiles` is removed.
     */
    private function rotate(): void
    {
        clearstatcache();
        $last = $this->path . '.' . $this->maxFiles;
        if (self::exists($last)) {
            error_clear_last();
            if (!@unlink($last)) {
                throw $this->failure('remove', $last);
            }
        }
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $from = $this->path . '.' . $i;
            if (self::exists($from)) {
                $this->rename($from, $this->path . '.' . ($i + 1));
            }
        }
        $this->rename($this->path, $this->path . '.1');
    }

    /**
     * Whether a file, a directory or a (possibly dangling) link is at `$path`, without a PHP warning.
     */
    private static function exists(string $path): bool
    {
        return @file_exists($path) || @is_link($path);
    }

    private function rename(string $from, string $to): void
    {
        error_clear_last();
        if (!@rename($from, $to)) {
            throw $this->failure('rename ' . $from . ' to', $to);
        }
    }

    /**
     * The exception for a failed file operation, with the PHP error it suppressed when there was one.
     *
     * error_get_last() is filled by PHP's own handler, which Yii's ErrorHandler passes `@`-masked errors
     * on to. Without `@` the same warning would reach the application instead of this message.
     */
    private function failure(string $operation, string $path, ?string $detail = null): FileAdapterException
    {
        $error = error_get_last();
        $detail ??= $error['message'] ?? null;

        return new FileAdapterException(sprintf('File adapter could not %s %s%s', $operation, $path, $detail === null ? '' : ': ' . $detail));
    }
}
