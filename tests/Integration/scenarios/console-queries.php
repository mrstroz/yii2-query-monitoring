<?php

declare(strict_types=1);

/**
 * QM_QUERIES short queries `SELECT <i> AS qm_c_<i>`, i from 1, so the order of entries is readable from `query`.
 * QM_CLOCK: JSON {"<i>": seconds} — written to QM_CLOCK_FILE before query i (`"0"` before the first query);
 * QM_BIG: JSON list of i run as one long query `SELECT ? AS qm_big_<i>_<k>, …` of QM_BIG_COLUMNS columns instead;
 * QM_IDLE_CLOCK: seconds written to QM_CLOCK_FILE after the last query, with no query after it.
 * Returns `calls`: {"<i>": adapter calls so far} for each i after which the count changed, and `idleCalls`,
 * the count read after QM_IDLE_CLOCK was written.
 */
return static function (\yii\console\Application $app): array {
    $db = $app->getDb();
    $count = (int) getenv('QM_QUERIES');
    $clock = json_decode((string) getenv('QM_CLOCK') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $big = json_decode((string) getenv('QM_BIG') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    $columns = (int) (getenv('QM_BIG_COLUMNS') ?: 200);
    $clockFile = (string) getenv('QM_CLOCK_FILE');
    $callsFile = getenv('QM_CAPTURE_FILE') . '.calls';
    $calls = static function () use ($callsFile): int {
        clearstatcache(true, $callsFile);

        return is_file($callsFile) ? count(file($callsFile, FILE_SKIP_EMPTY_LINES) ?: []) : 0;
    };

    $seen = 0;
    $changes = [];
    for ($i = 1; $i <= $count; $i++) {
        if (isset($clock[(string) $i])) {
            file_put_contents($clockFile, (string) $clock[(string) $i]);
        }
        if (in_array($i, $big, true)) {
            $list = implode(', ', array_map(static fn(int $k): string => "{$k} AS qm_big_{$i}_{$k}", range(1, $columns)));
            $db->createCommand("SELECT {$list}")->queryOne();
        } else {
            $db->createCommand("SELECT {$i} AS qm_c_{$i}")->queryScalar();
        }
        if ($calls() !== $seen) {
            $seen = $calls();
            $changes[(string) $i] = $seen;
        }
    }
    $idle = null;
    if (getenv('QM_IDLE_CLOCK') !== false) {
        file_put_contents($clockFile, (string) getenv('QM_IDLE_CLOCK'));
        $idle = $calls();
    }

    return ['calls' => (object) $changes, 'idleCalls' => $idle];
};
