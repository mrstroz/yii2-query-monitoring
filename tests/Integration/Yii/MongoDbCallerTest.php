<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration\Yii;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-38, spec 01 §3 and ADR-0009: every application path of YQM-32, question 5, gives `caller` with the path's
 * own first application frame within 64 frames of the end event. The deepest, a GridView with `with()` two levels
 * deep, puts it at position 32 (MongoDbProbeTest).
 */
final class MongoDbCallerTest extends IntegrationTestCase
{
    private const SCENARIO = 'tests/Integration/scenarios/mongodb-caller.php';

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideApplicationPathCases(): iterable
    {
        yield 'ActiveRecord::find()->one()' => ['one', ['find']];
        yield 'Collection::insert()' => ['insert', ['insert']];
        yield 'ActiveDataProvider in GridView' => ['grid', ['count', 'find']];
        yield 'cursor with getMore' => ['batch', ['find', 'getMore']];
        yield 'GridView with with() two levels deep' => ['grid-with', ['count', 'find', 'find', 'find']];
    }

    /**
     * @param list<string> $ops
     */
    #[DataProvider('provideApplicationPathCases')]
    public function testEveryCommandOfThePathHasItsFirstApplicationFrame(string $path, array $ops): void
    {
        $this->requireDatabase('mongodb');

        $result = $this->scenario(self::ANY_DB, 'mongodb-caller', ['connections' => ['mongodb']], ['QM_CALLER_PATH' => $path]);

        $this->assertProcessOk($result);
        $batch = $this->singleBatch($result);
        // The set-up of the scenario (remove, insert of the contact) comes first; the path's commands follow it.
        $entries = array_slice($batch['queries'], 2);
        self::assertSame($ops, array_column($entries, 'op'));
        $line = self::SCENARIO . ':' . $this->lineAfterCase($path);
        foreach ($entries as $entry) {
            self::assertNotSame([], $entry['caller'], "{$entry['op']}: an application frame within 64");
            self::assertSame($line, $entry['caller'][0], "{$entry['op']}: the path's own line");
        }
    }

    /**
     * The line under `case '<path>':` in the scenario, where the path's call stands.
     */
    private function lineAfterCase(string $path): int
    {
        $lines = file(dirname(__DIR__, 3) . '/' . self::SCENARIO, FILE_IGNORE_NEW_LINES) ?: [];
        $index = array_search("        case '{$path}':", $lines, true);
        self::assertIsInt($index, "case {$path} in the scenario");

        return $index + 2;
    }
}
