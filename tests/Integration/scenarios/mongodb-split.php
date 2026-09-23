<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\MongoProbeSubscriber;

/**
 * YQM-37: a cursor read in batches of two, and a batchInsert larger than the server's 48 MB message, which the
 * driver splits into several `insert` commands. An independent subscriber, added after the package's, counts the
 * commands the driver really sent.
 */
return static function (\yii\web\Application $app): array {
    $mongodb = $app->get('mongodb');
    assert($mongodb instanceof \yii\mongodb\Connection);
    $mongodb->open();
    $watch = new MongoProbeSubscriber('watch');
    $mongodb->manager->addSubscriber($watch);

    $cursor = $mongodb->getCollection('qm_split_cursor');
    $cursor->remove();
    $cursor->batchInsert(array_map(static fn(int $i): array => ['i' => $i], range(1, 5)));
    $read = 0;
    foreach ((new \yii\mongodb\Query())->from('qm_split_cursor')->batch(2, $mongodb) as $rows) {
        $read += count($rows);
    }

    $started = static fn(string $name): int => count(array_filter($watch->events, static fn(array $e): bool => $e['event'] === 'started' && $e['name'] === $name));
    $insertsBefore = $started('insert');

    // 50 documents of 1 MB: more than maxMessageSizeBytes (48 MB), far below 16 MB per document. The driver
    // builds the BSON of the whole bulk in PHP memory, above the CLI default of 128 MB.
    ini_set('memory_limit', '512M');
    $big = $mongodb->getCollection('qm_split_insert');
    $big->remove();
    $payload = str_repeat('x', 1024 * 1024);
    $big->batchInsert(array_map(static fn(int $i): array => ['i' => $i, 'payload' => $payload], range(1, 50)));
    $big->remove();

    return ['read' => $read, 'getMore' => $started('getMore'), 'bigInserts' => $started('insert') - $insertsBefore];
};
