<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\QueryMonitor;
use mrstroz\querymonitoring\sql\SqlNormalizer;
use yii\base\Application;

/**
 * The component with a failing part, chosen by `QM_FAULT`, for the protection tests (spec 01 §6).
 *
 * The collector and the normaliser are final, so the failing part is an instance created without its
 * constructor: the first read of an uninitialised readonly property throws `\Error`.
 * - `normalizer` — throws in `SqlNormalizer::normalize()`, reading `$maxQueryLength`, for every recorded query;
 * - `normalizer-factory` — {@see self::createNormalizer()} itself throws, so the SQL source cannot install;
 * - `collector` — throws first in `QueryCollector::setRoute()` (via `measureHeader()`, reading `$app`) on
 *   `EVENT_BEFORE_ACTION`, before any query; later `add()` and `close()` throw the same way, and the Guard,
 *   which logs once per process, keeps them silent.
 * If the collector or the normaliser starts reading an initialised property first, these points move.
 * {@see self::$normalizersCreated} counts the calls of createNormalizer() for the scenario `normalizers`.
 */
final class FaultyQueryMonitor extends QueryMonitor
{
    public static int $normalizersCreated = 0;

    protected function createNormalizer(): SqlNormalizer
    {
        self::$normalizersCreated++;
        if (getenv('QM_FAULT') === 'normalizer-factory') {
            throw new \RuntimeException('The normaliser factory fails.');
        }

        return getenv('QM_FAULT') === 'normalizer'
            ? (new \ReflectionClass(SqlNormalizer::class))->newInstanceWithoutConstructor()
            : parent::createNormalizer();
    }

    protected function createCollector(Application $app): QueryCollector
    {
        return getenv('QM_FAULT') === 'collector'
            ? (new \ReflectionClass(QueryCollector::class))->newInstanceWithoutConstructor()
            : parent::createCollector($app);
    }
}
