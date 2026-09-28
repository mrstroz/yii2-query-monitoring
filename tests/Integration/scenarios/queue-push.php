<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\console\jobs\QueryJob;

/**
 * Pushes QM_JOBS, a JSON list of `[mode, marker]`, to the test queue as {@see QueryJob}s; returns their ids.
 * A db queue gets its table from yii2-queue's own migrations first, when the table does not exist yet.
 */
return static function (\yii\console\Application $app): array {
    $queue = $app->get('queue');
    assert($queue instanceof \yii\queue\Queue);
    $db = $queue instanceof \yii\queue\db\Queue ? $queue->db : null;
    if ($queue instanceof \yii\queue\db\Queue && $db instanceof \yii\db\Connection && $db->getTableSchema($queue->tableName, true) === null) {
        foreach (['M161119140200Queue', 'M170307170300Later', 'M170509001400Retry', 'M170601155600Priority', 'M211218163000JobQueueSize'] as $name) {
            $class = "yii\\queue\\db\\migrations\\{$name}";
            $migration = new $class(['db' => $db, 'tableName' => $queue->tableName, 'compact' => true]);
            $migration->up();
        }
    }
    $ids = [];
    foreach (json_decode((string) getenv('QM_JOBS'), true, 512, JSON_THROW_ON_ERROR) as [$mode, $marker]) {
        $ids[] = $queue->push(new QueryJob(['mode' => $mode, 'marker' => $marker]));
    }

    return ['ids' => $ids];
};
