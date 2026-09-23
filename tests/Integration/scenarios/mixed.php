<?php

declare(strict_types=1);

/**
 * YQM-40: SQL and MongoDB commands alternating in one request, `db` (MySQL or PostgreSQL by QM_DB) and `mongodb`,
 * each marked so a test finds it and reads the order of the entries.
 */
return static function (\yii\web\Application $app): array {
    $db = $app->getDb();
    $mongodb = $app->get('mongodb');
    assert($mongodb instanceof \yii\mongodb\Connection);

    $db->createCommand('SELECT 4601 AS qm_mixed_1')->queryScalar();
    $mongodb->getCollection('qm_mixed_2')->findOne([]);
    $db->createCommand('SELECT 4603 AS qm_mixed_3')->queryScalar();
    $mongodb->getCollection('qm_mixed_4')->insert(['k' => 4604]);

    return ['done' => true];
};
