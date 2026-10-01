<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use mrstroz\querymonitoring\batch\QueryBatch;

/**
 * Sources for the `user` setting of the test application (YQM-60), named so they survive the JSON of `QM_COMPONENT`:
 * `{"user": ["mrstroz\\querymonitoring\\tests\\app\\UserProbe", "recording"]}`.
 */
final class UserProbe
{
    /**
     * Returns `'u-7'` and appends what it saw to `@runtime/user-calls.jsonl`, one line per call:
     * `{"type", "id", "seq", "sample": bool, "user"}`.
     */
    public static function recording(QueryBatch $batch): string
    {
        $line = [
            'type' => $batch->type->value,
            'id' => $batch->id,
            'seq' => $batch->seq,
            'sample' => $batch->sample !== null,
            'user' => $batch->user,
        ];
        file_put_contents(\Yii::getAlias('@runtime') . '/user-calls.jsonl', json_encode($line) . "\n", FILE_APPEND);

        return 'u-7';
    }

    /**
     * Runs `SELECT 42 AS qm_user` on the measured `db` and returns its result.
     */
    public static function sqlLookup(QueryBatch $batch): mixed
    {
        return \Yii::$app?->getDb()->createCommand('SELECT 42 AS qm_user')->queryScalar();
    }

    /**
     * Returns or throws by `QM_USER_CASE`: `throw` (RuntimeException with the message `qm-user-marker`), `array`,
     * `float`, `empty`, `int42`, `negative` (-5), `max64` (64 ASCII), `over65` (65 ASCII), `quote63` (63 ASCII and
     * `"`), `badutf8` (an invalid byte in a short string), `objectid` ({@see TestIdentity::OBJECT_ID} as an ObjectId),
     * `stringable` (an object whose `__toString()` gives `'u-7'`), `badtostring` (its `__toString()` throws a
     * RuntimeException with `qm-user-marker`), `configthrow` (InvalidConfigException with `qm-user-marker`),
     * `longbadutf8` (100 invalid bytes).
     */
    public static function byCase(QueryBatch $batch): mixed
    {
        return match ((string) getenv('QM_USER_CASE')) {
            'throw' => throw new \RuntimeException('qm-user-marker'),
            'array' => ['id' => 'qm-user-marker'],
            'float' => 4.2,
            'empty' => '',
            'int42' => 42,
            'negative' => -5,
            'max64' => str_repeat('m', 64),
            'over65' => str_repeat('o', 65),
            'quote63' => str_repeat('q', 63) . '"',
            'badutf8' => "qm-user-\xC3",
            'objectid' => new \MongoDB\BSON\ObjectId(TestIdentity::OBJECT_ID),
            'stringable' => new class implements \Stringable {
                public function __toString(): string
                {
                    return 'u-7';
                }
            },
            'badtostring' => new class implements \Stringable {
                public function __toString(): string
                {
                    throw new \RuntimeException('qm-user-marker');
                }
            },
            'configthrow' => throw new \yii\base\InvalidConfigException('qm-user-marker'),
            'longbadutf8' => str_repeat("\xC3", 100),
            default => null,
        };
    }

    /**
     * Not static: `[UserProbe::class, 'nonStatic']` is not a callable without an instance, a wrong `user` setting.
     */
    public function nonStatic(QueryBatch $batch): string
    {
        return 'never';
    }
}
