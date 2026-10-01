<?php

declare(strict_types=1);

/** YQM-60: the action sees the logged-in identity, then logs out; the batch is sent after that. */
return static function (\yii\web\Application $app): array {
    $db = $app->getDb()->createCommand('SELECT 603 AS qm_user_logout')->queryScalar();
    $before = $app->getUser()->getId();
    $app->getUser()->logout();

    return ['db' => $db, 'before' => $before, 'after' => $app->getUser()->getId()];
};
