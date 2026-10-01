<?php

declare(strict_types=1);

/** YQM-60: one marked query, then the action asks `isGuest`, which loads the identity as the application would. */
return static function (\yii\web\Application $app): array {
    $db = $app->getDb()->createCommand('SELECT 602 AS qm_user_touched')->queryScalar();

    return ['db' => $db, 'isGuest' => $app->getUser()->getIsGuest(), 'id' => $app->getUser()->getId()];
};
