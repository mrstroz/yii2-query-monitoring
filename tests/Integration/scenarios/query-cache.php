<?php

declare(strict_types=1);

/**
 * The same query twice inside Connection::cache() (ArrayCache of the test application), or twice without it
 * when QM_T1_NO_CACHE=1, the control case.
 */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';
    $db = $conn($app, 'db');
    (require __DIR__ . '/_table.php')($app);
    $conn($app, 'dbOther')->createCommand("INSERT INTO qm_t1_item (id, name, flag) VALUES (1, 'a', 0), (2, 'b', 1)")->execute();

    $query = static fn(\yii\db\Connection $db): string => (string) $db->createCommand('SELECT COUNT(*) AS qm_cached FROM qm_t1_item WHERE flag >= :f', [':f' => 0])->queryScalar();
    $results = [];
    for ($i = 0; $i < 2; $i++) {
        $results[] = getenv('QM_T1_NO_CACHE') === '1' ? $query($db) : $db->cache($query, 60);
    }

    return ['results' => $results, 'flags' => (require __DIR__ . '/_flags.php')($app)];
};
