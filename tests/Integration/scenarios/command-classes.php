<?php

declare(strict_types=1);

/** Class of the command each connection creates; nothing is executed. */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';

    return [
        'db' => get_class($conn($app, 'db')->createCommand()),
        'dbOther' => get_class($conn($app, 'dbOther')->createCommand()),
        'admin/db' => get_class($conn($app, 'admin/db')->createCommand()),
    ];
};
