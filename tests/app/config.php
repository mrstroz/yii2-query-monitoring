<?php

declare(strict_types=1);

use mrstroz\querymonitoring\QueryMonitor;
use mrstroz\querymonitoring\tests\app\CaptureAdapter;
use mrstroz\querymonitoring\tests\app\JsonLogTarget;
use mrstroz\querymonitoring\tests\app\modules\admin\Module as AdminModule;
use mrstroz\querymonitoring\tests\app\TestPdo;

/**
 * Configuration of the test application, built from the environment set by AppRunner.
 */
$db = (string) getenv('QM_DB') ?: 'mysql';
$prefix = 'QM_' . strtoupper($db) . '_';
$connection = [
    'class' => yii\db\Connection::class,
    'dsn' => (string) getenv($prefix . 'DSN'),
    'username' => (string) getenv($prefix . 'USER'),
    'password' => (string) getenv($prefix . 'PASSWORD'),
    'pdoClass' => TestPdo::class,
    // Measurement must not depend on Yii's own query logging and profiling (YQM-10).
    'enableLogging' => false,
    'enableProfiling' => false,
];

$monitor = [
    'class' => QueryMonitor::class,
    'app' => 'test-app',
    'connections' => ['db', 'admin/db'],
    'adapter' => CaptureAdapter::class,
];
$override = json_decode((string) getenv('QM_COMPONENT') ?: '{}', true);
foreach (is_array($override) ? $override : [] as $key => $value) {
    $monitor[$key] = $value;
}

// Extra connections for configuration tests: {"id": {overrides of $connection}} or {"id": "alias:<other id>"}
// for the same object under a second id.
$extra = [];
foreach (json_decode((string) getenv('QM_EXTRA_CONNECTIONS') ?: '{}', true) ?: [] as $id => $spec) {
    if (is_string($spec) && str_starts_with($spec, 'alias:')) {
        $target = substr($spec, 6);
        $extra[$id] = static function () use ($target): object {
            $app = Yii::$app;
            assert($app !== null);

            return $app->get($target);
        };
    } elseif (is_array($spec)) {
        $extra[$id] = array_replace($connection, $spec);
    }
}

return [
    'id' => 'qm-test-app',
    'basePath' => __DIR__,
    // The package's own vendor/, so `@vendor` points where the frames of Yii really are (YQM-27).
    'vendorPath' => dirname(__DIR__, 2) . '/vendor',
    // A directory per run from AppRunner, so the default file adapter never writes into the repository.
    'runtimePath' => (string) getenv('QM_RUNTIME') ?: __DIR__ . '/runtime',
    'controllerNamespace' => 'mrstroz\querymonitoring\tests\app\controllers',
    'bootstrap' => ['log', 'queryMonitor'],
    'modules' => [
        'admin' => [
            'class' => AdminModule::class,
            'components' => ['db' => $connection],
        ],
    ],
    'components' => [
        'cache' => yii\caching\ArrayCache::class,
        'db' => $connection,
        'dbOther' => $connection,
        'mongodb' => ['class' => yii\mongodb\Connection::class, 'dsn' => (string) getenv('QM_MONGODB_DSN')],
        'queryMonitor' => $monitor,
        ...$extra,
        // QM_DB_CACHE=1: URL rules cached in DbCache, so a query runs in Request::resolve(), before any controller (YQM-27).
        ...(getenv('QM_DB_CACHE') === '1' ? [
            'cache' => ['class' => yii\caching\DbCache::class, 'cacheTable' => 'qm_cache'],
            'urlManager' => ['enablePrettyUrl' => true, 'rules' => ['orders' => 'order/index']],
        ] : []),
        'errorHandler' => [
            // A real exit(1) after an unhandled exception, as in production; YII_ENV_TEST would silence it.
            'silentExitOnException' => false,
            'errorAction' => getenv('QM_ERROR_ACTION') === '1' ? 'site/error' : null,
        ],
        'request' => [
            'cookieValidationKey' => 'test',
            'enableCsrfValidation' => false,
            'scriptFile' => __DIR__ . '/web/index.php',
            'scriptUrl' => '/index.php',
        ],
        'log' => [
            'flushInterval' => 1,
            'targets' => [
                ['class' => JsonLogTarget::class, 'levels' => ['error', 'warning'], 'logVars' => [], 'exportInterval' => 1],
            ],
        ],
    ],
];
