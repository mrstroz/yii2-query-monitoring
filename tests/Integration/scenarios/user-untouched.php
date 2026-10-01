<?php

declare(strict_types=1);

/** YQM-60: one marked query; the action reads neither the identity nor the session. */
return static function (\yii\web\Application $app): array {
    return ['db' => $app->getDb()->createCommand('SELECT 601 AS qm_user_untouched')->queryScalar()];
};
