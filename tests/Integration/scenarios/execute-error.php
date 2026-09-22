<?php

declare(strict_types=1);

/** A constraint violation raised by PDOStatement::execute(). */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';

    (require __DIR__ . '/_table.php')($app);
    $conn($app, 'dbOther')->createCommand("INSERT INTO qm_t1_item (id, name, flag) VALUES (1, 'first', 0)")->execute();
    try {
        $conn($app, 'db')->createCommand('INSERT INTO qm_t1_item (id, name, flag) VALUES (:id, :name, 0)', [':id' => 1, ':name' => 'dup'])->execute();
    } catch (\Throwable $e) {
        return ['thrown' => (require __DIR__ . '/_describe.php')($e)];
    }

    return ['thrown' => null];
};
