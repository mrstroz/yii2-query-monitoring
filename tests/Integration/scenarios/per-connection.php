<?php

declare(strict_types=1);

/** One marked query per connection; the alias survives normalisation and tells the entries apart. */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';

    return [
        'db' => $conn($app, 'db')->createCommand('SELECT 101 AS qm_on_db')->queryScalar(),
        'dbOther' => $conn($app, 'dbOther')->createCommand('SELECT 102 AS qm_on_other')->queryScalar(),
        'admin/db' => $conn($app, 'admin/db')->createCommand('SELECT 103 AS qm_on_admin')->queryScalar(),
    ];
};
