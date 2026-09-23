<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\FaultyQueryMonitor;

/** How many times FaultyQueryMonitor::createNormalizer() ran in bootstrap. */
return static fn(\yii\web\Application $app): array => ['created' => FaultyQueryMonitor::$normalizersCreated];
