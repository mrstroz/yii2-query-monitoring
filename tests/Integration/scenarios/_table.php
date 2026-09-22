<?php

declare(strict_types=1);

/**
 * Creates (once) and empties the scenario table through `dbOther`, which is not monitored,
 * so the setup gives no entries. Shared by scenarios; not a scenario itself.
 */
return static function (\yii\web\Application $app): void {
    $conn = require __DIR__ . '/_conn.php';

    /** @var \yii\db\Connection $other */
    $other = $conn($app, 'dbOther');
    $other->createCommand(
        'CREATE TABLE IF NOT EXISTS qm_t1_item (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, flag SMALLINT NOT NULL, note VARCHAR(50) NULL)',
    )->execute();
    $other->createCommand('DELETE FROM qm_t1_item')->execute();
};
