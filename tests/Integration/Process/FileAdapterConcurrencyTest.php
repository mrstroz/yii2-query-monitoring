<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Process;

use mrstroz\querymonitoring\tests\app\ProcessGroup;
use PHPUnit\Framework\TestCase;
use yii\helpers\FileHelper;

/**
 * YQM-18, probe of ADR-0005: eight processes write through one FileAdapter file while it rotates, and
 * the `.lock` file keeps every line whole and in exactly one file. Needs no database.
 *
 * Integrity alone would not notice two parallel rotations overwriting `.1`, so every batch is accounted
 * for: the ids in the files are exactly those whose write() returned true. `maxFiles` is larger than
 * the number of writes, so no copy is ever removed and nothing may be missing.
 */
final class FileAdapterConcurrencyTest extends TestCase
{
    private const PROCESSES = 8;
    private const BATCHES_PER_PROCESS = 60;
    private const MAX_FILES = self::PROCESSES * self::BATCHES_PER_PROCESS + 1;
    private const MAX_SIZE = 4096;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/qm-concurrency-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->dir);
    }

    public function testParallelWritersWithRotationLoseNoLineOutsideABusyLock(): void
    {
        // The directory does not exist yet: the eight processes race to create it.
        $path = $this->dir . '/logs/queries.jsonl';
        $results = ProcessGroup::run(__DIR__ . '/../workers/file-writer.php', self::PROCESSES, 120, [
            'QM_PATH' => $path,
            'QM_MAX_SIZE' => (string) self::MAX_SIZE,
            'QM_MAX_FILES' => (string) self::MAX_FILES,
            'QM_BATCHES' => (string) self::BATCHES_PER_PROCESS,
            'QM_PAUSE_US' => '2000',
        ]);

        $written = [];
        $lost = [];
        foreach ($results as $result) {
            self::assertFalse($result->timedOut, "process {$result->index} timed out");
            self::assertSame(0, $result->exitCode, "process {$result->index}\nstdout: {$result->stdout}\nstderr: {$result->stderr}");
            self::assertSame('', $result->stderr);
            $ids = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($ids);
            array_push($written, ...$ids['true']);
            array_push($lost, ...$ids['false']);
        }
        self::assertCount(self::PROCESSES * self::BATCHES_PER_PROCESS, [...$written, ...$lost], 'every write() call reported');

        $copies = [];
        $inFiles = [];
        foreach ((array) scandir($this->dir . '/logs') as $name) {
            $name = (string) $name;
            if (in_array($name, ['.', '..', 'queries.jsonl.lock'], true)) {
                continue;
            }
            self::assertMatchesRegularExpression('/^queries\.jsonl(\.\d+)?$/', $name, 'only the current file and numbered copies');
            if ($name !== 'queries.jsonl') {
                $copies[] = (int) substr($name, strlen('queries.jsonl.'));
            }
            $content = (string) file_get_contents($this->dir . '/logs/' . $name);
            self::assertStringEndsWith("\n", $content, "{$name} ends with a whole line");
            foreach (explode("\n", rtrim($content, "\n")) as $line) {
                $batch = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($batch);
                $inFiles[] = $batch['id'];
            }
        }

        self::assertGreaterThanOrEqual(2, count($copies), 'the file was rotated at least twice');
        self::assertNotContains(self::MAX_FILES, $copies);
        self::assertNotContains(self::MAX_FILES + 1, $copies);
        self::assertSame(count($inFiles), count(array_unique($inFiles)), 'no batch written twice');
        sort($inFiles);
        sort($written);
        self::assertSame($written, $inFiles, 'the files hold exactly the batches whose write() returned true');
        self::assertSame([], array_values(array_intersect($lost, $inFiles)), 'no lost batch in the files');
        self::assertSame(self::PROCESSES * self::BATCHES_PER_PROCESS, count($inFiles) + count($lost));
    }
}
