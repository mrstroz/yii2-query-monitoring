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
    'controllerNamespace' => 'mrstroz\querymonitoring\tests\app\controllers',
    'bootstrap' => ['log', 'queryMonitor'],
    'modules' => [
        'admin' => [
            'class' => AdminModule::class,
            'components' => ['db' => $connection],
        ],
    ],
    'components' => [
        'db' => $connection,
        'dbOther' => $connection,
        'queryMonitor' => $monitor,
        ...$extra,
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
