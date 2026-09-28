<?php

declare(strict_types=1);

/**
 * Runs QM_OPS, a JSON list of operations on `queryMonitor` and `db`, in the web or the console application:
 * - `["begin", name, queue, messageId, attempt]` — `beginJob()`; handles are numbered from 0 in order of `begin`,
 * - `["end", n]` — `endJob()` of handle n,
 * - `["q", marker]` — `SELECT 1 AS <marker>`,
 * - `["qn", marker, count]` — `SELECT <i> AS <marker>_<i>` for i from 1 to count,
 * - `["mongo", collection]` — `findOne` on the collection through `mongodb`,
 * - `["throw", n]` — a job that throws inside `try { … } finally { endJob(n) }`, caught here as the caller would,
 * - `["exit"]` — `exit()` on the spot.
 * Returns `calls`, the adapter call count after each operation, and `caught`, the messages of exceptions that
 * reached the caller.
 */
return static function (\yii\base\Application $app): array {
    $monitor = $app->get('queryMonitor');
    assert($monitor instanceof \mrstroz\querymonitoring\QueryMonitor);
    $db = $app->getDb();
    $ops = json_decode((string) getenv('QM_OPS'), true, 512, JSON_THROW_ON_ERROR);
    $callsFile = getenv('QM_CAPTURE_FILE') . '.calls';
    $calls = static function () use ($callsFile): int {
        clearstatcache(true, $callsFile);

        return is_file($callsFile) ? count(file($callsFile, FILE_SKIP_EMPTY_LINES) ?: []) : 0;
    };

    $handles = [];
    $after = [];
    $caught = [];
    foreach ($ops as $op) {
        switch ($op[0]) {
            case 'begin':
                $handles[] = $monitor->beginJob($op[1], $op[2] ?? null, $op[3] ?? null, $op[4] ?? null);
                break;
            case 'end':
                $monitor->endJob($handles[$op[1]]);
                break;
            case 'q':
                $db->createCommand("SELECT 1 AS {$op[1]}")->queryScalar();
                break;
            case 'qn':
                for ($i = 1; $i <= $op[2]; $i++) {
                    $db->createCommand("SELECT {$i} AS {$op[1]}_{$i}")->queryScalar();
                }
                break;
            case 'mongo':
                $mongodb = $app->get('mongodb');
                assert($mongodb instanceof \yii\mongodb\Connection);
                $mongodb->getCollection($op[1])->findOne([]);
                break;
            case 'throw':
                try {
                    try {
                        $db->createCommand('SELECT 1 AS qm_job_before_throw')->queryScalar();

                        throw new \RuntimeException('qm job failed');
                    } finally {
                        $monitor->endJob($handles[$op[1]]);
                    }
                } catch (\RuntimeException $e) {
                    $caught[] = $e->getMessage();
                }
                break;
            case 'exit':
                echo json_encode(['calls' => $after, 'caught' => $caught, 'exited' => true]);
                exit(0);
            default:
                throw new \InvalidArgumentException("Unknown operation {$op[0]}.");
        }
        $after[] = $calls();
    }

    return ['calls' => $after, 'caught' => $caught];
};
