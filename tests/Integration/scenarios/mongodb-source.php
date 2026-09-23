<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\models\Contact;
use mrstroz\querymonitoring\tests\app\MongoProbeSubscriber;

/**
 * YQM-35: MongoDB commands through listed and unlisted connections.
 * - QM_MONGO_USE: comma-separated ids; each runs one `find` on collection `qm_src_<id>`, in this order,
 * - QM_MONGO_REOPEN=1: `mongodb` is closed after the finds and runs `find` on `qm_src_reopen`,
 * - QM_MONGO_AR=1: `Contact::find()->all()` through Active Record,
 * - QM_MONGO_WATCH=1: an independent subscriber added to `mongodb` after the package's; its events are returned,
 * - QM_MONGO_FINALIZE=1: the component is finalised, then `mongodb` runs `find` on `qm_src_after_finalize`,
 * - QM_MONGO_SQL=1: `db` runs `SELECT 4343 AS qm_src_sql` first.
 */
return static function (\yii\web\Application $app): array {
    $conn = static function (string $id) use ($app): \yii\mongodb\Connection {
        $connection = $app->get($id);
        assert($connection instanceof \yii\mongodb\Connection);

        return $connection;
    };
    $out = ['found' => []];
    if (getenv('QM_MONGO_SQL') === '1') {
        $out['sql'] = $app->getDb()->createCommand('SELECT 4343 AS qm_src_sql')->queryScalar();
    }
    $watch = null;
    if (getenv('QM_MONGO_WATCH') === '1') {
        $conn('mongodb')->open();
        $watch = new MongoProbeSubscriber('watch');
        $conn('mongodb')->manager->addSubscriber($watch);
    }
    foreach (array_filter(explode(',', (string) getenv('QM_MONGO_USE'))) as $id) {
        $out['found'][$id] = $conn($id)->getCollection('qm_src_' . $id)->findOne(['marker' => 'qm-src']);
    }
    if (getenv('QM_MONGO_REOPEN') === '1') {
        $conn('mongodb')->close();
        $out['found']['reopen'] = $conn('mongodb')->getCollection('qm_src_reopen')->findOne([]);
    }
    if (getenv('QM_MONGO_AR') === '1') {
        $out['ar'] = count(Contact::find()->all($conn('mongodb')));
    }
    if (getenv('QM_MONGO_FINALIZE') === '1') {
        $monitor = $app->get('queryMonitor');
        assert($monitor instanceof \mrstroz\querymonitoring\QueryMonitor);
        $monitor->finalize();
        $out['found']['after_finalize'] = $conn('mongodb')->getCollection('qm_src_after_finalize')->findOne([]);
    }
    if ($watch !== null) {
        $out['watched'] = array_column(array_filter($watch->events, static fn(array $e): bool => $e['event'] === 'started'), 'name');
    }

    return $out;
};
