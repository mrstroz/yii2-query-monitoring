<?php

declare(strict_types=1);

/**
 * YQM-39: the package in an application without ext-mongodb and yiisoft/yii2-mongodb, installed from a `path`
 * repository. Runs outside PHPUnit, because the package's own require-dev needs both (tests/README.md).
 *
 * One query through the listed `db` must give one batch with a SQL entry and no package error: the component
 * creates its MongoDB source too, which must load without either. Exit code 0 on success, 1 with the reason on stderr.
 */

use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\QueryMonitor;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/vendor/yiisoft/yii2/Yii.php';

$fail = static function (string $reason): never {
    fwrite(STDERR, "consumer smoke: {$reason}\n");
    exit(1);
};
if (extension_loaded('mongodb') || class_exists(\yii\mongodb\Connection::class)) {
    $fail('ext-mongodb or yii2-mongodb is present; the check needs an application without them');
}

$batches = [];
$app = new yii\console\Application([
    'id' => 'qm-consumer',
    'basePath' => __DIR__,
    'bootstrap' => ['queryMonitor'],
    'components' => [
        'db' => [
            'class' => yii\db\Connection::class,
            'dsn' => (string) getenv('QM_MYSQL_DSN'),
            'username' => (string) getenv('QM_MYSQL_USER'),
            'password' => (string) getenv('QM_MYSQL_PASSWORD'),
        ],
        'queryMonitor' => [
            'class' => QueryMonitor::class,
            'app' => 'qm-consumer',
            'connections' => ['db'],
            'adapter' => static function (QueryBatch $batch) use (&$batches): void {
                $batches[] = $batch;
            },
        ],
    ],
]);

$app->getDb()->createCommand('SELECT 4545 AS qm_consumer')->queryScalar();
$monitor = $app->get('queryMonitor');
assert($monitor instanceof QueryMonitor);
$monitor->finalize();

$errors = array_filter(Yii::getLogger()->messages, static fn(array $m): bool => $m[1] === yii\log\Logger::LEVEL_ERROR);
if ($errors !== []) {
    $fail('package error: ' . json_encode(array_column($errors, 0)));
}
if (count($batches) !== 1) {
    $fail('expected one batch, got ' . count($batches));
}
$entries = array_values(array_filter($batches[0]->queries, static fn($e): bool => str_contains((string) $e->query, 'qm_consumer')));
if (count($entries) !== 1 || $entries[0]->db !== 'mysql') {
    $fail('expected one mysql entry, got ' . json_encode(array_map(static fn($e): array => $e->toArray(), $batches[0]->queries)));
}
echo json_encode(['ok' => true, 'entry' => $entries[0]->toArray()]), "\n";
