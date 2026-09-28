<?php

declare(strict_types=1);

/** One query in a console command; the harness check for ConsoleRunner. */
return static function (\yii\console\Application $app): array {
    $db = $app->getDb();

    return ['value' => (int) $db->createCommand('SELECT 901 AS qm_console_probe')->queryScalar()];
};
