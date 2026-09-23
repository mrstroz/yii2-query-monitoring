<?php

declare(strict_types=1);

use mrstroz\querymonitoring\tests\app\sources\FakeSource;

/** The install() calls the fake sources of FakeSourceQueryMonitor received in bootstrap. */
return static fn(\yii\web\Application $app): array => ['calls' => FakeSource::$calls];
