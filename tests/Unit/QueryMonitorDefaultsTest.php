<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit;

use mrstroz\querymonitoring\QueryMonitor;
use mrstroz\querymonitoring\sql\SqlNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * YQM-29, spec 01 §1: the component's default `maxQueryLength` is the normaliser's, 8192 bytes.
 */
final class QueryMonitorDefaultsTest extends TestCase
{
    public function testDefaultMaxQueryLengthIsTheNormalisersDefault(): void
    {
        $default = (new \ReflectionProperty(QueryMonitor::class, 'maxQueryLength'))->getDefaultValue();

        self::assertSame(8192, $default);
        self::assertSame(SqlNormalizer::DEFAULT_MAX_QUERY_LENGTH, $default, 'one source of the default value');
    }
}
