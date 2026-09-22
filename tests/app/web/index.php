<?php

declare(strict_types=1);

/*
 * Entry script of the test application, run by AppRunner as `php tests/app/web/index.php`.
 * Plays one GET request for the route in QM_ROUTE.
 */

define('YII_DEBUG', true);
define('YII_ENV', 'test');

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['REQUEST_URI'] = '/index.php?r=' . rawurlencode((string) getenv('QM_ROUTE'));
$_GET['r'] = (string) getenv('QM_ROUTE');

(new yii\web\Application(require dirname(__DIR__) . '/config.php'))->run();
