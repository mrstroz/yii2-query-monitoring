<?php

declare(strict_types=1);

/** YQM-60: the action creates the `user` component without asking it for the identity, then runs one marked query. */
return static function (\yii\web\Application $app): array {
    return ['class' => $app->get('user')::class, 'db' => $app->getDb()->createCommand('SELECT 605 AS qm_user_component')->queryScalar()];
};
