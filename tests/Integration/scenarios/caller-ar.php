<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\models\Order;

/**
 * YQM-27, application path: Active Record reads one row (with the schema of the table, no schema cache).
 */
return static function (\yii\web\Application $app): array {
    $order = Order::find()->one();

    return ['found' => $order !== null];
};
