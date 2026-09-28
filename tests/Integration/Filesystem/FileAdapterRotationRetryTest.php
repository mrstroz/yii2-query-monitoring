<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Filesystem;

use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\tests\Integration\support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * spec 03 §3, rotation: a rotation that failed at its last step (the current file to `.1`) has already moved the
 * copies up by one, so `.1` is free. The next rotation moves only what stands before the first free slot and
 * removes no copy. The failure itself needs another user's file in a sticky directory, which only
 * tests/multiuser can arrange; this test starts from the state such a failure leaves.
 */
final class FileAdapterRotationRetryTest extends TestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        $this->dir = self::temporaryPath('rotation-retry');
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testRotationAfterAFailedLastStepFillsTheGapAndKeepsEveryCopy(): void
    {
        $path = $this->dir . '/queries.jsonl';
        // State after `.2 → .3` and `.1 → .2` succeeded and `current → .1` failed: A and B moved up, `.1` is free.
        file_put_contents("{$path}.2", "{\"copy\":\"A\"}\n");
        file_put_contents("{$path}.3", "{\"copy\":\"B\"}\n");
        file_put_contents($path, str_repeat("{\"current\":true}\n", 100));
        $current = (string) hash_file('sha256', $path);
        $adapter = new FileAdapter($path, maxSize: 1000, maxFiles: 3);

        $adapter->send(FileAdapterTest::batch('next', 1));

        self::assertSame("{\"copy\":\"A\"}\n", $this->contents("{$path}.2"), 'A stays where it is');
        self::assertSame("{\"copy\":\"B\"}\n", $this->contents("{$path}.3"), 'B is not overwritten');
        self::assertSame($current, hash_file('sha256', "{$path}.1") ?: null, 'the oversized current file moved into the gap');
        self::assertStringContainsString('"id":"next"', (string) file_get_contents($path), 'the batch went to a fresh current file');
    }

    public function testRotationWithAGapHigherUpMovesOnlyTheCopiesBelowIt(): void
    {
        $path = $this->dir . '/queries.jsonl';
        file_put_contents("{$path}.1", "{\"copy\":\"A\"}\n");
        file_put_contents("{$path}.3", "{\"copy\":\"C\"}\n");
        file_put_contents($path, str_repeat("{\"current\":true}\n", 100));
        $adapter = new FileAdapter($path, maxSize: 1000, maxFiles: 3);

        $adapter->send(FileAdapterTest::batch('next', 1));

        self::assertSame("{\"copy\":\"A\"}\n", $this->contents("{$path}.2"), 'A moved up into the gap');
        self::assertSame("{\"copy\":\"C\"}\n", $this->contents("{$path}.3"), 'C above the gap is kept');
    }

    public function testFullSetStillDropsOnlyTheOldestCopy(): void
    {
        $path = $this->dir . '/queries.jsonl';
        foreach (['A' => 1, 'B' => 2, 'C' => 3] as $copy => $i) {
            file_put_contents("{$path}.{$i}", "{\"copy\":\"{$copy}\"}\n");
        }
        file_put_contents($path, str_repeat("{\"current\":true}\n", 100));
        $adapter = new FileAdapter($path, maxSize: 1000, maxFiles: 3);

        $adapter->send(FileAdapterTest::batch('next', 1));

        self::assertSame(["{\"copy\":\"A\"}\n", "{\"copy\":\"B\"}\n"], [$this->contents("{$path}.2"), $this->contents("{$path}.3")], 'without a gap every copy moves up and the oldest goes');
        self::assertFileExists("{$path}.1");
    }

    /** Contents of the file, or null when it is not there. */
    private function contents(string $file): ?string
    {
        clearstatcache(true, $file);

        return is_file($file) ? (string) file_get_contents($file) : null;
    }
}
