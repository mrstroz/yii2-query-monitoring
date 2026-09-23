<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\QueryMonitor;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\app\sources\FakeSource;

/**
 * YQM-33: the component with the sources from `QM_FAKE_SOURCES`, a JSON list of `[name, class taken, incompatible]`,
 * in place of the package's own.
 */
final class FakeSourceQueryMonitor extends QueryMonitor
{
    protected function createSources(QueryCollector $collector, Guard $guard, CallerFrames $callers): array
    {
        $sources = [];
        foreach (json_decode((string) getenv('QM_FAKE_SOURCES'), true, 512, JSON_THROW_ON_ERROR) as [$name, $takes, $incompatible]) {
            $sources[] = new FakeSource($name, $takes, $incompatible);
        }

        return $sources;
    }
}
