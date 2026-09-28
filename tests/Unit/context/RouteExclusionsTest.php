<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\context;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\context\RouteExclusions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;

/**
 * YQM-52, spec 01 §5.4: the matching rules of `excludedRoutes`, per context type.
 */
final class RouteExclusionsTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, ?string, bool}>
     */
    public static function provideMatchCases(): iterable
    {
        yield 'exact' => [['queue/listen'], 'queue/listen', true];
        yield 'exact is not a prefix' => [['queue'], 'queue/listen', false];
        yield 'exact is not a suffix' => [['listen'], 'queue/listen', false];
        yield 'prefix with slash' => [['queue/*'], 'queue/listen', true];
        yield 'prefix does not match the bare controller' => [['queue/*'], 'queue', false];
        yield 'prefix without slash' => [['que*'], 'queue/listen', true];
        yield 'star alone' => [['*'], 'anything/at-all', true];
        yield 'case matters' => [['Queue/listen'], 'queue/listen', false];
        yield 'null route matches nothing' => [['*'], null, false];
        yield 'second pattern' => [['health/index', 'queue/*'], 'queue/run', true];
        yield 'backslash in a job name' => [['app\jobs\*'], 'app\jobs\SendInvoice', true];
    }

    /**
     * @param list<string> $patterns
     */
    #[DataProvider('provideMatchCases')]
    public function testMatching(array $patterns, ?string $value, bool $expected): void
    {
        self::assertSame($expected, RouteExclusions::fromConfig(['console' => $patterns])->matches(BatchType::Console, $value));
    }

    public function testListsAreSeparatePerType(): void
    {
        $exclusions = RouteExclusions::fromConfig(['http' => ['site/*'], 'job' => ['app\jobs\Noisy']]);

        self::assertTrue($exclusions->matches(BatchType::Http, 'site/index'));
        self::assertFalse($exclusions->matches(BatchType::Console, 'site/index'), 'an http pattern does not exclude a command');
        self::assertTrue($exclusions->matches(BatchType::Job, 'app\jobs\Noisy'));
        self::assertFalse($exclusions->matches(BatchType::Http, 'app\jobs\Noisy'));
    }

    public function testNoneExcludesNothing(): void
    {
        self::assertFalse(RouteExclusions::none()->matches(BatchType::Http, 'site/index'));
        self::assertFalse(RouteExclusions::fromConfig([])->matches(BatchType::Console, 'queue/listen'));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function provideWrongConfigurationCases(): iterable
    {
        yield 'unknown key' => [['cli' => ['x']]];
        yield 'numeric key' => [[0 => ['x']]];
        yield 'star inside' => [['console' => ['queue/*/run']]];
        yield 'two stars' => [['console' => ['**']]];
        yield 'empty pattern' => [['console' => ['']]];
        yield 'not a string' => [['console' => [1]]];
        yield 'null pattern' => [['console' => [null]]];
        yield 'not a list' => [['console' => 'queue/*']];
        yield 'list with keys' => [['console' => ['a' => 'queue/*']]];
        yield 'leading slash' => [['console' => ['/queue/listen']]];
    }

    /**
     * @param array<mixed> $config
     */
    #[DataProvider('provideWrongConfigurationCases')]
    public function testWrongConfigurationIsRejected(array $config): void
    {
        $this->expectException(InvalidConfigException::class);

        RouteExclusions::fromConfig($config);
    }
}
