<?php

declare(strict_types=1);

/** Queries alternating between db and admin/db, one of them failing, in a known order. */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';
    $db = $conn($app, 'db');
    $admin = $conn($app, 'admin/db');

    $db->createCommand('SELECT 1001 AS qm_seq_1')->queryScalar();
    $admin->createCommand('SELECT 1002 AS qm_seq_2')->queryScalar();
    try {
        $db->createCommand('SELECT qm_seq_3 FROM qm_t1_missing_table')->queryScalar();
    } catch (\yii\db\Exception) {
    }
    $admin->createCommand('SELECT 1004 AS qm_seq_4')->queryScalar();
    $db->createCommand('SELECT 1005 AS qm_seq_5')->queryScalar();

    return ['flags' => (require __DIR__ . '/_flags.php')($app)];
};
