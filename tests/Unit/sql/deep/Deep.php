<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\sql\deep;

use mrstroz\querymonitoring\sql\Recorder;

/**
 * Recursion in a directory that {@see \mrstroz\querymonitoring\tests\Unit\sql\RecorderCallerTest} passes as vendor,
 * so the frames between the recorder and the test are not application frames.
 */
final class Deep
{
    /**
     * Records one query under `$levels` frames of this method: the innermost calls the recorder,
     * the outermost is called by the test.
     */
    public static function record(int $levels, Recorder $recorder): void
    {
        if ($levels <= 1) {
            $recorder->record('SELECT 1', 1.0, null);

            return;
        }
        self::record($levels - 1, $recorder);
    }
}
