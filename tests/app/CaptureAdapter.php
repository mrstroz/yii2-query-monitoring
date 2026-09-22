<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\batch\QueryBatch;

/**
 * Appends each batch as one JSON line to `QM_CAPTURE_FILE`, for {@see AppRunner} to read back.
 *
 * Every call first appends `{"call": n}` to `QM_CAPTURE_FILE.calls`. `QM_ADAPTER` changes what the call does:
 * - `throw` — throws a RuntimeException instead of writing the batch,
 * - `json-fail` — fails in `json_encode()` with JSON_THROW_ON_ERROR, as a serialising adapter would,
 * - `query` — runs `SELECT 4242 AS qm_in_adapter` through the monitored `db`, records the result in the
 *   call line (`"query": "4242"`) and then writes the batch.
 */
final class CaptureAdapter implements BatchAdapterInterface
{
    private int $calls = 0;

    public function send(QueryBatch $batch): void
    {
        $call = ['call' => ++$this->calls];
        $mode = (string) getenv('QM_ADAPTER');
        if ($mode === 'query') {
            $call['query'] = (string) \Yii::$app?->getDb()->createCommand('SELECT 4242 AS qm_in_adapter')->queryScalar();
        }
        $file = (string) getenv('QM_CAPTURE_FILE');
        file_put_contents($file . '.calls', json_encode($call) . "\n", FILE_APPEND | LOCK_EX);
        if ($mode === 'throw') {
            throw new \RuntimeException('CaptureAdapter failed on purpose.');
        }
        if ($mode === 'json-fail') {
            json_encode(NAN, JSON_THROW_ON_ERROR);
        }
        file_put_contents($file, $batch->toJson() . "\n", FILE_APPEND | LOCK_EX);
    }
}
