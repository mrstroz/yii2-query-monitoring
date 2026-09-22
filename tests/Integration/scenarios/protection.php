<?php

declare(strict_types=1);

/**
 * What the application gets from a few successful operations and one failing one; run with the
 * package on (with faults) and off, the printed JSON must be the same.
 * QM_T1_APP_ERROR=1 also logs one application error, for the control case of the querying log target.
 */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';
    $db = $conn($app, 'db');

    (require __DIR__ . '/_table.php')($app);
    $out = [
        'select' => (int) $db->createCommand('SELECT 901 AS qm_prot_select')->queryScalar(),
        'insert' => $db->createCommand('INSERT INTO qm_t1_item (id, name, flag) VALUES (:id, :name, 0)', [':id' => 1, ':name' => 'first'])->execute(),
        'rows' => $db->createCommand('SELECT id, name FROM qm_t1_item WHERE id = :id', [':id' => 1])->queryAll(),
    ];
    try {
        $db->createCommand('INSERT INTO qm_t1_item (id, name, flag) VALUES (:id, :name, 0)', [':id' => 1, ':name' => 'dup'])->execute();
        $out['thrown'] = null;
    } catch (\Throwable $e) {
        $out['thrown'] = (require __DIR__ . '/_describe.php')($e);
    }

    if (getenv('QM_T1_APP_ERROR') === '1') {
        \Yii::error('qm-t1 application error', 'application');
    }

    return $out;
};
