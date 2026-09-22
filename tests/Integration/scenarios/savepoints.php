<?php

declare(strict_types=1);

/**
 * A transaction with nested transactions (savepoints in Yii): one committed, one rolled back and one
 * rolled back after a failing statement inside it (on pgsql the savepoint rollback is what lets the outer
 * transaction go on). Returns the rows left after the outer commit.
 */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';
    $db = $conn($app, 'db');
    (require __DIR__ . '/_table.php')($app);
    $insert = static fn(int $id) => $db->createCommand('INSERT INTO qm_t1_item (id, name, flag) VALUES (:id, :n, 0)', [':id' => $id, ':n' => "row{$id}"])->execute();

    $outer = $db->beginTransaction();
    $insert(1);

    $kept = $db->beginTransaction();
    $insert(2);
    $kept->commit();

    $dropped = $db->beginTransaction();
    $insert(3);
    $dropped->rollBack();

    $failed = $db->beginTransaction();
    try {
        $insert(1);
    } catch (\yii\db\Exception) {
        $failed->rollBack();
    }

    $insert(4);
    $outer->commit();

    $rows = $conn($app, 'dbOther')->createCommand('SELECT id FROM qm_t1_item ORDER BY id')->queryColumn();

    return ['rows' => array_map('intval', $rows), 'flags' => (require __DIR__ . '/_flags.php')($app)];
};
