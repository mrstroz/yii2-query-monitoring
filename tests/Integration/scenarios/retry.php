<?php

declare(strict_types=1);

/**
 * The first attempt hits a duplicate key; the retry handler removes the old row and asks
 * Command::internalExecute() to try again, which then succeeds.
 */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';

    (require __DIR__ . '/_table.php')($app);
    $other = $conn($app, 'dbOther');
    $other->createCommand("INSERT INTO qm_t1_item (id, name, flag) VALUES (1, 'old', 0)")->execute();

    $command = $conn($app, 'db')->createCommand("INSERT INTO qm_t1_item (id, name, flag) VALUES (1, 'new', 0)");
    $attempts = [];
    $handler = static function (\yii\db\Exception $e, int $attempt) use ($other, &$attempts): bool {
        $attempts[] = $attempt;
        $other->createCommand('DELETE FROM qm_t1_item WHERE id = 1')->execute();

        return $attempt === 1;
    };
    (new \ReflectionMethod(\yii\db\Command::class, 'setRetryHandler'))->invoke($command, $handler);
    $affected = $command->execute();

    return [
        'affected' => $affected,
        'attempts' => $attempts,
        'name' => $other->createCommand('SELECT name FROM qm_t1_item WHERE id = 1')->queryScalar(),
    ];
};
