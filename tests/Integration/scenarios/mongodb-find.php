<?php

declare(strict_types=1);

/** One document written and read back through the application's `mongodb` connection. */
return static function (\yii\web\Application $app): array {
    $mongodb = $app->get('mongodb');
    assert($mongodb instanceof \yii\mongodb\Connection);
    $collection = $mongodb->getCollection('qm_env');
    $collection->remove();
    $collection->insert(['marker' => 'qm-env']);

    // Query::all() sends a find command.
    return ['found' => count((new \yii\mongodb\Query())->from('qm_env')->where(['marker' => 'qm-env'])->all($mongodb))];
};
