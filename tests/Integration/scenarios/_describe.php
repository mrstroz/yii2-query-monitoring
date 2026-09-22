<?php

declare(strict_types=1);

/** Everything the application can see of an exception; shared helper, not a scenario. */
return static function (\Throwable $e): array {
    return [
        'class' => get_class($e),
        'code' => $e->getCode(),
        'message' => $e->getMessage(),
        'errorInfo' => $e instanceof \yii\db\Exception ? $e->errorInfo : null,
        'previous' => $e->getPrevious() === null ? null : get_class($e->getPrevious()),
    ];
};
