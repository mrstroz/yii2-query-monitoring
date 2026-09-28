<?php

declare(strict_types=1);

/*
 * Console entry script of the test application, run by ConsoleRunner as `php tests/app/yii.php <route> [args]`.
 * A child started by the application itself (yii2-queue `isolate`) runs this same file through SCRIPT_FILENAME.
 */

define('YII_DEBUG', true);
define('YII_ENV', 'test');

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';

$application = new yii\console\Application(require __DIR__ . '/console/config.php');
exit($application->run());
