<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\mongodb\MongoDbNormalizer;
use mrstroz\querymonitoring\mongodb\Source as MongoDbSource;
use mrstroz\querymonitoring\support\CallerFrames;
use mrstroz\querymonitoring\support\Guard;
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
 * - `mongodb-normalizer` — the MongoDB source gets a normaliser whose `normalize()` throws, so the package's
 *   driver subscriber fails at every command start;
 * - `mongodb-normalizer-factory` — the MongoDB source's normaliser factory throws, so subscribing fails when a
 *   listed MongoDB connection opens;
 * - `mongodb-callers` — the MongoDB source gets a `CallerFrames` whose `frames()` throws, so the package's
 *   driver subscriber fails at every command end;
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

    protected function createSources(QueryCollector $collector, Guard $guard, CallerFrames $callers): array
    {
        $sources = parent::createSources($collector, $guard, $callers);
        $normalizer = static fn(): MongoDbNormalizer => new MongoDbNormalizer(8192);
        switch (getenv('QM_FAULT')) {
            case 'mongodb-normalizer':
                $normalizer = static fn(): MongoDbNormalizer => (new \ReflectionClass(MongoDbNormalizer::class))->newInstanceWithoutConstructor();
                break;
            case 'mongodb-normalizer-factory':
                $normalizer = static fn(): MongoDbNormalizer => throw new \RuntimeException('The MongoDB normaliser factory fails.');
                break;
            case 'mongodb-callers':
                $callers = (new \ReflectionClass(CallerFrames::class))->newInstanceWithoutConstructor();
                break;
            default:
                return $sources;
        }
        $sources = array_values(array_filter($sources, static fn(object $source): bool => !$source instanceof MongoDbSource));

        return [...$sources, new MongoDbSource($collector, $guard, $callers, $normalizer)];
    }

    protected function createCollector(Application $app): QueryCollector
    {
        return getenv('QM_FAULT') === 'collector'
            ? (new \ReflectionClass(QueryCollector::class))->newInstanceWithoutConstructor()
            : parent::createCollector($app);
    }
}
