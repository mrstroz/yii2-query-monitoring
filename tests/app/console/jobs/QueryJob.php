<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\console\jobs;

use yii\base\BaseObject;
use yii\queue\RetryableJobInterface;

/**
 * A job of the test queue that runs `SELECT 1 AS qm_<marker>` on `db`. `mode` changes what else it does:
 * - `ok` — nothing more,
 * - `fail-once` — throws on its first attempt (a marker file under `QM_QUEUE_PATH` remembers it), succeeds on the retry,
 * - `exit` — calls `exit(3)` after the query, as a job that kills its process,
 * - `handled` — nothing here: the test queue's `beforeExec` handler runs the query and marks the event handled,
 *   so this method never runs.
 * With `QM_JOB_SEES_CALLS=1` it first prints `calls-before-<marker>=<n>`, the adapter calls so far, on stderr.
 * A retry is allowed once, one second after the failure (`ttr`).
 */
final class QueryJob extends BaseObject implements RetryableJobInterface
{
    public string $marker = '';

    public string $mode = 'ok';

    public function execute($queue): void
    {
        $db = \Yii::$app?->getDb();
        assert($db instanceof \yii\db\Connection);
        if (getenv('QM_JOB_SEES_CALLS') === '1') {
            $calls = getenv('QM_CAPTURE_FILE') . '.calls';
            clearstatcache(true, $calls);
            fwrite(STDERR, "calls-before-{$this->marker}=" . (is_file($calls) ? count(file($calls, FILE_SKIP_EMPTY_LINES) ?: []) : 0) . "\n");
        }
        $db->createCommand("SELECT 1 AS qm_{$this->marker}")->queryScalar();
        if ($this->mode === 'fail-once') {
            $flag = getenv('QM_QUEUE_PATH') . "/failed-{$this->marker}";
            if (!is_file($flag)) {
                touch($flag);

                throw new \RuntimeException('qm queue job failed on purpose');
            }
        }
        if ($this->mode === 'exit') {
            exit(3);
        }
    }

    public function getTtr(): int
    {
        return 1;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 2;
    }
}
