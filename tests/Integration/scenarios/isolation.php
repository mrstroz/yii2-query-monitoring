<?php

declare(strict_types=1);

/**
 * Command::requireTransaction() outside a transaction: internalExecute() opens one and calls
 * itself; the statement is still sent once.
 */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';

    (require __DIR__ . '/_table.php')($app);
    $command = $conn($app, 'db')->createCommand("INSERT INTO qm_t1_item (id, name, flag) VALUES (5, 'isolated', 0)");
    (new \ReflectionMethod(\yii\db\Command::class, 'requireTransaction'))->invoke($command);
    $affected = $command->execute();

    return [
        'affected' => $affected,
        'name' => $conn($app, 'dbOther')->createCommand('SELECT name FROM qm_t1_item WHERE id = 5')->queryScalar(),
    ];
};
