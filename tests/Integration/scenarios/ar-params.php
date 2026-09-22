<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\models\Order;

/** The same Active Record query with two parameter values; the orders are inserted through the unmonitored dbOther. */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';
    $other = $conn($app, 'dbOther');
    $other->createCommand('DELETE FROM qm_order')->execute();
    $other->createCommand("INSERT INTO qm_order (customer, total) VALUES ('qm-t1-first@example.com', 17.25), ('qm-t1-second@example.com', 42.75)")->execute();

    $found = [];
    foreach (['qm-t1-first@example.com', 'qm-t1-second@example.com'] as $customer) {
        $found[] = array_map(static fn(Order $o): string => (string) $o->total, Order::find()->where(['customer' => $customer])->all());
    }

    return ['found' => $found, 'flags' => (require __DIR__ . '/_flags.php')($app)];
};
