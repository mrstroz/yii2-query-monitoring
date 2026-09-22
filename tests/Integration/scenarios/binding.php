<?php

declare(strict_types=1);

use yii\db\Query;

/**
 * Parameter binding as the application uses it; the result must not depend on the package.
 */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';

    (require __DIR__ . '/_table.php')($app);
    $db = $conn($app, 'db');

    $db->createCommand('INSERT INTO qm_t1_item (id, name, flag, note) VALUES (:id, :name, :flag, :note)')
        ->bindValue(':id', 1, \PDO::PARAM_INT)
        ->bindValue(':name', "O'Brien \"x\" \\ ż", \PDO::PARAM_STR)
        ->bindValue(':flag', 1, \PDO::PARAM_INT)
        ->bindValue(':note', null, \PDO::PARAM_NULL)
        ->execute();

    $insert = $db->createCommand('INSERT INTO qm_t1_item (id, name, flag, note) VALUES (:id, :name, :flag, :note)');
    $id = 0;
    $name = '';
    $insert->bindParam(':id', $id, \PDO::PARAM_INT)
        ->bindParam(':name', $name)
        ->bindValues([':flag' => [0, \PDO::PARAM_INT], ':note' => 'n']);
    foreach ([2 => 'second', 3 => 'third'] as $id => $name) {
        $insert->execute();
    }

    $db->createCommand()->insert('qm_t1_item', ['id' => 4, 'name' => 'builder', 'flag' => 1, 'note' => null])->execute();
    $db->createCommand()->update('qm_t1_item', ['note' => 'updated'], ['id' => 4])->execute();

    return [
        'all' => (new Query())->from('qm_t1_item')->orderBy('id')->all($db),
        'in' => (new Query())->select('id')->from('qm_t1_item')->where(['id' => [1, 3]])->orderBy('id')->column($db),
        'named' => $db->createCommand('SELECT name FROM qm_t1_item WHERE id = :id AND flag = :flag', [':id' => 1, ':flag' => 1])->queryScalar(),
        'positional' => $db->createCommand('SELECT COUNT(*) FROM qm_t1_item WHERE flag = ?', [1 => 0])->queryScalar(),
        'raw' => $db->createCommand('SELECT name FROM qm_t1_item WHERE id = :id', [':id' => 2])->getRawSql(),
        'affected' => $db->createCommand()->delete('qm_t1_item', ['id' => 3])->execute(),
    ];
};
