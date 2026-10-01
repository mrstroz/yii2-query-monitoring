<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\TestIdentity;

/** YQM-60: a guest request whose action logs in as `u-7`; the batch is sent after that. */
return static function (\yii\web\Application $app): array {
    $db = $app->getDb()->createCommand('SELECT 604 AS qm_user_login')->queryScalar();
    $identity = TestIdentity::findIdentity('u-7');
    if ($identity === null) {
        throw new \RuntimeException('No test identity u-7.');
    }

    return ['db' => $db, 'login' => $app->getUser()->login($identity), 'id' => $app->getUser()->getId()];
};
