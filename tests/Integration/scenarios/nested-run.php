<?php

declare(strict_types=1);

/**
 * The entry action runs another action through Yii::$app->runAction() (a second EVENT_BEFORE_ACTION
 * of the application), which runs a query; the header keeps the entry action.
 */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';

    $inner = $app->runAction('order/none');
    $value = $conn($app, 'db')->createCommand('SELECT 811 AS qm_nested_after')->queryScalar();

    return ['inner' => $inner instanceof \yii\web\Response ? $inner->data : null, 'value' => (int) $value];
};
