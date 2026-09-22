<?php

declare(strict_types=1);

/** enableProfiling and enableLogging of the monitored connections, as the process sees them; shared helper, not a scenario. */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';
    $flags = [];
    foreach (['db', 'admin/db'] as $id) {
        $connection = $conn($app, $id);
        $flags[$id] = ['profiling' => $connection->enableProfiling, 'logging' => $connection->enableLogging];
    }

    return $flags;
};
