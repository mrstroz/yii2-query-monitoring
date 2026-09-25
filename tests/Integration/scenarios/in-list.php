<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\models\Order;

/**
 * Pairs of the same Active Record query with lists of different length, as Yii builds them: `IN`, `IN` with
 * tuples of a composite key and `NOT IN`. The table may be empty; only the query text matters.
 */
return static function (\yii\web\Application $app): array {
    $tuples = static fn(int $count): array => array_map(
        static fn(int $i): array => ['id' => $i, 'customer' => "qm-in-{$i}@example.com"],
        range(1, $count),
    );
    $counts = [];
    foreach ([3, 211] as $count) {
        $counts[] = count(Order::find()->where(['id' => range(1, $count)])->andWhere(['customer' => 'qm-in@example.com'])->all());
    }
    foreach ([2, 3] as $count) {
        $counts[] = count(Order::find()->where(['in', ['id', 'customer'], $tuples($count)])->all());
    }
    foreach ([3, 211] as $count) {
        $counts[] = count(Order::find()->where(['not in', 'id', range(1, $count)])->andWhere(['customer' => 'qm-in@example.com'])->all());
    }

    return ['counts' => $counts];
};
