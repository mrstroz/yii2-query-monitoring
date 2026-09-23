<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\mongodb\deep;

use mrstroz\querymonitoring\mongodb\Recorder;

/**
 * Recursion in a directory that {@see \mrstroz\querymonitoring\tests\Unit\mongodb\RecorderCallerTest} passes as vendor,
 * so the frames between the recorder and the test are not application frames. The innermost level plays the driver's
 * subscriber.
 */
final class Deep
{
    /**
     * Ends one started command under `$levels` frames of this method: the innermost calls the recorder, the outermost
     * is called by the test.
     */
    public static function succeeded(int $levels, Recorder $recorder): void
    {
        if ($levels <= 1) {
            $recorder->succeeded('1', 1000, static fn(): object => new \stdClass());

            return;
        }
        self::succeeded($levels - 1, $recorder);
    }
}
