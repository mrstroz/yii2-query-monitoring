<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app\console;

use mrstroz\querymonitoring\QueryMonitor;

/**
 * The component with a clock read from `QM_CLOCK_FILE` (seconds as a float) on every call, so a scenario
 * moves time forward by writing the file instead of sleeping. A missing or empty file reads as 0.
 */
final class ClockQueryMonitor extends QueryMonitor
{
    protected function createClock(): \Closure
    {
        $file = (string) getenv('QM_CLOCK_FILE');

        return static function () use ($file): float {
            clearstatcache(true, $file);

            return (float) @file_get_contents($file);
        };
    }
}
