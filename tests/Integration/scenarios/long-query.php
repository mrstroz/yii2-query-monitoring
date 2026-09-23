<?php

declare(strict_types=1);

/**
 * A query whose normalised text is longer than the default maxQueryLength: 5000 literals, each `?, `
 * after normalisation. The alias survives normalisation and marks the entry.
 */
return static function (\yii\web\Application $app): array {
    $sql = 'SELECT 1 AS qm_long_query FROM (SELECT 1 AS a) t WHERE t.a IN (' . implode(', ', range(1, 5000)) . ')';

    return ['value' => $app->getDb()->createCommand($sql)->queryScalar()];
};
