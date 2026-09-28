<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\console\jobs\QueryJob;

/**
 * Pushes QM_JOBS, a JSON list of `[mode, marker]`, to the test queue as {@see QueryJob}s; returns their ids.
 */
return static function (\yii\console\Application $app): array {
    $queue = $app->get('queue');
    assert($queue instanceof \yii\queue\Queue);
    $ids = [];
    foreach (json_decode((string) getenv('QM_JOBS'), true, 512, JSON_THROW_ON_ERROR) as [$mode, $marker]) {
        $ids[] = $queue->push(new QueryJob(['mode' => $mode, 'marker' => $marker]));
    }

    return ['ids' => $ids];
};
