<?php

declare(strict_types=1);

/**
 * Configuration of the console test application: the web configuration from tests/app/config.php,
 * without the components and settings only a web application knows.
 */
$config = require dirname(__DIR__) . '/config.php';
unset($config['components']['request'], $config['components']['urlManager'], $config['components']['user'], $config['components']['session']);
$config['id'] = 'qm-test-console';
$config['controllerNamespace'] = 'mrstroz\querymonitoring\tests\app\console\controllers';
// A real exit(1) after an unhandled exception, as in production; YII_ENV_TEST would silence it.
$config['components']['errorHandler'] = ['silentExitOnException' => false];

// QM_QUEUE_PATH: a yii2-queue file queue in that directory; QM_QUEUE_DRIVER=db: a db queue in table `qm_queue` on `db`,
// channel QM_QUEUE_CHANNEL (queue-push.php creates the table). Either has the package's behavior and a `beforeExec`
// handler that takes over `handled` jobs (YQM-53). The queue outlives one run, so a test can push and then run.
$queuePath = (string) getenv('QM_QUEUE_PATH');
$queueDriver = (string) getenv('QM_QUEUE_DRIVER');
if ($queuePath !== '' || $queueDriver === 'db') {
    $config['bootstrap'][] = 'queue';
    $config['components']['queue'] = [
        ...($queueDriver === 'db' ? [
            'class' => yii\queue\db\Queue::class,
            'tableName' => 'qm_queue',
            'channel' => (string) getenv('QM_QUEUE_CHANNEL') ?: 'queue',
            'mutex' => getenv('QM_DB') === 'pgsql' ? yii\mutex\PgsqlMutex::class : yii\mutex\MysqlMutex::class,
        ] : [
            'class' => yii\queue\file\Queue::class,
            'path' => $queuePath,
        ]),
        'as queryMonitor' => ['class' => mrstroz\querymonitoring\queue\JobMonitorBehavior::class, 'queueName' => 'queue'],
        // After the behavior, as a handler of the application would be: it runs the job's work itself.
        'on beforeExec' => static function (yii\queue\ExecEvent $event): void {
            if ($event->job instanceof mrstroz\querymonitoring\tests\app\console\jobs\QueryJob && $event->job->mode === 'handled') {
                Yii::$app?->getDb()->createCommand("SELECT 1 AS qm_{$event->job->marker}")->queryScalar();
                $event->handled = true;
            }
        },
    ];
}

return $config;
