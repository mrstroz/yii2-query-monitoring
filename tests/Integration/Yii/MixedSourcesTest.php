<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-40, spec 00 §6: one request with SQL and MongoDB gives one batch with the entries of both sources, in the
 * order the commands ended. Both SQL engines, because each goes through its own `commandMap` key next to the
 * driver subscriber (tests/README.md, step 1).
 */
final class MixedSourcesTest extends IntegrationTestCase
{
    #[DataProvider('provideDatabaseCases')]
    public function testOneBatchHoldsEntriesOfBothSourcesInOrder(string $db): void
    {
        $this->requireDatabase('mongodb');

        $result = $this->scenario($db, 'mixed', ['connections' => ['db', 'mongodb']]);

        $this->assertProcessOk($result);
        $this->assertNoErrors($result);
        $entries = $this->entriesWith($this->singleBatch($result), 'qm_mixed_');
        self::assertSame(
            [[$db, 'db', 'select'], ['mongodb', 'mongodb', 'find'], [$db, 'db', 'select'], ['mongodb', 'mongodb', 'insert']],
            array_map(static fn(array $e): array => [$e['db'], $e['conn'], $e['op']], $entries),
        );
        self::assertSame(['qm_mixed_2 filter{} limit:?', 'qm_mixed_4 n:1'], [$entries[1]['query'], $entries[3]['query']]);
    }
}
