<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

/**
 * YQM-36 and YQM-37, spec 01 §3: the result of a MongoDB command from its reply or its failure, and one entry per
 * command the driver sends, `getMore` and every part of a split `insert` included.
 */
final class MongoDbResultTest extends IntegrationTestCase
{
    public function testWriteErrorsAndFailuresGiveErrorEntriesWithoutChangingWhatTheApplicationSees(): void
    {
        $this->requireDatabase('mongodb');

        $measured = $this->scenario(self::ANY_DB, 'mongodb-writes', ['connections' => ['mongodb']]);
        $without = $this->scenario(self::ANY_DB, 'mongodb-writes', ['connections' => ['mongodb'], 'enabled' => false]);

        self::assertSame($this->scenarioOutput($without), $this->scenarioOutput($measured), 'the same result or exception as without the package');
        self::assertNotSame('ok', $this->scenarioOutput($measured)['duplicate'], 'the duplicate key reaches the application as an exception');
        $batch = $this->singleBatch($measured);
        $last = static fn(array $entries): array => $entries[array_key_last($entries)];
        self::assertSame(['insert', 'error', '11000'], $this->resultOf($last($this->entriesWith($batch, 'qm_w_dup '))), 'writeErrors in CommandSucceeded');
        self::assertSame(['insert', 'error', '64'], $this->resultOf($last($this->entriesWith($batch, 'qm_w_wce '))), 'writeConcernError from the fail point');
        self::assertSame(['insert', 'error', '11000'], $this->resultOf($last($this->entriesWith($batch, 'qm_w_both '))), 'writeErrors wins over writeConcernError');
        self::assertSame(['find', 'error', '2'], $this->resultOf($last($this->entriesWith($batch, 'qm_w_rejected '))), 'CommandFailed through the subscriber');
        self::assertSame(['configureFailPoint'], array_values(array_unique(array_column(array_filter($batch['queries'], static fn(array $e): bool => $e['query'] === null), 'op'))), 'commands outside the normalisation table are entries with query null');
        $this->assertNoErrors($measured);
    }

    public function testEveryGetMoreAndEveryPartOfASplitInsertIsAnEntry(): void
    {
        $this->requireDatabase('mongodb');

        $result = $this->scenario(self::ANY_DB, 'mongodb-split', ['connections' => ['mongodb']]);

        $out = $this->scenarioOutput($result);
        self::assertSame(5, $out['read']);
        self::assertGreaterThanOrEqual(1, $out['getMore'], 'the cursor needed further batches');
        self::assertGreaterThanOrEqual(2, $out['bigInserts'], 'the driver split the insert');
        $batch = $this->singleBatch($result);
        $ops = array_count_values(array_column($this->entriesWith($batch, 'qm_split_cursor'), 'op'));
        self::assertSame(1, $ops['find'] ?? 0);
        self::assertSame($out['getMore'], $ops['getMore'] ?? 0, 'one entry per getMore the driver sent');
        self::assertCount($out['bigInserts'], array_filter($this->entriesWith($batch, 'qm_split_insert n:'), static fn(array $e): bool => $e['op'] === 'insert'), 'one entry per insert the driver sent');
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{mixed, mixed, mixed}
     */
    private function resultOf(array $entry): array
    {
        return [$entry['op'], $entry['result'], $entry['error']];
    }
}
