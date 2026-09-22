<?php

declare(strict_types=1);

use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\QueryMonitor;

/** One query, and the settings of the file adapter the component created (null for another adapter). */
return static function (\yii\web\Application $app): ?array {
    $conn = require __DIR__ . '/_conn.php';
    $conn($app, 'db')->createCommand('SELECT 1 AS qm_file_adapter')->queryScalar();

    $adapter = (new \ReflectionProperty(QueryMonitor::class, 'batchAdapter'))->getValue($app->get('queryMonitor'));

    return $adapter instanceof FileAdapter
        ? ['path' => $adapter->path, 'maxSize' => $adapter->maxSize, 'maxFiles' => $adapter->maxFiles, 'runtime' => $app->getRuntimePath()]
        : null;
};
