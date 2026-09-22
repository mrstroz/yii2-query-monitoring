<?php

declare(strict_types=1);

/** Connection by id as in `connections`, `module/id` for a module; shared helper, not a scenario. */
return static function (\yii\web\Application $app, string $id): \yii\db\Connection {
    $owner = $app;
    if (str_contains($id, '/')) {
        [$module, $id] = explode('/', $id, 2);
        $owner = $app->getModule($module);
        if ($owner === null) {
            throw new \RuntimeException("No module {$module}.");
        }
    }
    $connection = $owner->get($id);
    if (!$connection instanceof \yii\db\Connection) {
        throw new \RuntimeException("{$id} is not a connection.");
    }

    return $connection;
};
