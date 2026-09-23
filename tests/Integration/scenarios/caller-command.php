<?php

declare(strict_types=1);

/**
 * YQM-27, application path: a command created straight on the connection.
 */
return static function (\yii\web\Application $app): array {
    $value = $app->getDb()->createCommand('SELECT 1 AS qm_caller_command')->queryScalar();

    return ['value' => $value];
};
