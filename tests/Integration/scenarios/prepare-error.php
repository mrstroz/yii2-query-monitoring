<?php

declare(strict_types=1);

/** Run with QM_FAIL_PREPARE: PDO::prepare() itself throws. */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';

    try {
        $conn($app, 'db')->createCommand('SELECT 7 AS qm_prep_fail')->queryAll();
    } catch (\Throwable $e) {
        return ['thrown' => (require __DIR__ . '/_describe.php')($e)];
    }

    return ['thrown' => null];
};
