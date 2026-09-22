<?php

declare(strict_types=1);

/**
 * Probes connections named in the environment, without opening any other:
 * - QM_PROBE_STATE: ids whose `pdo` is reported (null means never opened),
 * - QM_PROBE_CLASS: ids whose createCommand() class is reported,
 * - QM_PROBE_QUERY: ids that run `SELECT <n> AS qm_on_<id>`.
 */
return static function (\yii\web\Application $app): array {
    $conn = require __DIR__ . '/_conn.php';
    $ids = static fn(string $name): array => array_filter(explode(',', (string) getenv($name)));

    $out = ['open' => [], 'class' => [], 'query' => []];
    foreach ($ids('QM_PROBE_CLASS') as $id) {
        $out['class'][$id] = get_class($conn($app, $id)->createCommand());
    }
    foreach ($ids('QM_PROBE_QUERY') as $n => $id) {
        $out['query'][$id] = $conn($app, $id)->createCommand('SELECT ' . (200 + $n) . ' AS qm_on_' . $id)->queryScalar();
    }
    foreach ($ids('QM_PROBE_STATE') as $id) {
        $out['open'][$id] = $conn($app, $id)->pdo !== null;
    }

    return $out;
};
