<?php

declare(strict_types=1);

/*
 * Child process of FileAdapterTest for settings that cannot be undone in the test process (`ulimit -f`,
 * `open_basedir`). Writes one batch of about 6 KB to the file in $argv[1] and prints the exception as JSON.
 */

use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\tests\Unit\adapter\FileAdapterTest;

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';

try {
    (new FileAdapter((string) $argv[1]))->send(FileAdapterTest::batch('short', 40));
    echo json_encode(['class' => null, 'message' => 'no exception']);
} catch (\Throwable $e) {
    echo json_encode(['class' => $e::class, 'message' => $e->getMessage()]);
}
