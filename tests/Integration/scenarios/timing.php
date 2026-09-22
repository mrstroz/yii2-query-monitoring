<?php

declare(strict_types=1);

/**
 * Timing probes for TestPdo switches. The first query on `db` also opens the connection;
 * the same prepared command runs twice; the last one fetches rows.
 */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';

    $db = $conn($app, 'db');
    $first = $db->createCommand('SELECT 1 AS qm_time_open')->queryScalar();

    $twice = $db->createCommand('SELECT 2 AS qm_time_twice');
    $a = $twice->queryScalar();
    $b = $twice->queryScalar();

    $rows = $db->createCommand('SELECT 3 AS qm_time_fetch')->queryAll();

    return [$first, $a, $b, count($rows)];
};
