<?php

declare(strict_types=1);

use mrstroz\querymonitoring\queue\JobMonitorBehavior;
use mrstroz\querymonitoring\tests\app\console\jobs\QueryJob;

/**
 * A `yii\queue\sync\Queue` built here with the package's behavior and, after it, a `beforeExec` handler that takes
 * over `handled` jobs. Runs `SELECT 1 AS qm_root_before`, pushes QM_JOBS (a JSON list of `[mode, marker]` for
 * {@see QueryJob}), calls `run()` — which triggers no worker loop — and runs `SELECT 1 AS qm_root_after`.
 */
return static function (\yii\console\Application $app): array {
    $db = $app->getDb();
    /** @var \yii\queue\sync\Queue $queue */
    $queue = Yii::createObject([
        'class' => \yii\queue\sync\Queue::class,
        'as queryMonitor' => ['class' => JobMonitorBehavior::class, 'queueName' => 'sync'],
        'on beforeExec' => static function (\yii\queue\ExecEvent $event) use ($db): void {
            if ($event->job instanceof QueryJob && $event->job->mode === 'handled') {
                $db->createCommand("SELECT 1 AS qm_{$event->job->marker}")->queryScalar();
                $event->handled = true;
            }
        },
    ]);

    $db->createCommand('SELECT 1 AS qm_root_before')->queryScalar();
    foreach (json_decode((string) getenv('QM_JOBS'), true, 512, JSON_THROW_ON_ERROR) as [$mode, $marker]) {
        $queue->push(new QueryJob(['mode' => $mode, 'marker' => $marker]));
    }
    $queue->run();
    $db->createCommand('SELECT 1 AS qm_root_after')->queryScalar();

    return ['ran' => true];
};
