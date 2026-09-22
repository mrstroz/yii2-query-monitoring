<?php

declare(strict_types=1);

/** Identity of the process that ran the request. */
return static fn(\yii\web\Application $app): array => ['pid' => getmypid(), 'sapi' => PHP_SAPI];
