<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\support;

use mrstroz\querymonitoring\support\CallerFrames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * YQM-28, spec 02 §2: `caller` holds at most three application frames, nearest first, as `path:line`
 * relative to the project root. An application frame has a file under the project root, outside vendor,
 * outside the package's own directory, and is not the entry script.
 */
final class CallerFramesTest extends TestCase
{
    private const ROOT = '/srv/shop';
    private const VENDOR = '/srv/shop/vendor';
    private const PACKAGE = '/srv/shop/vendor/mrstroz/yii2-query-monitoring/src';
    private const ENTRY = '/srv/shop/web/index.php';

    public function testKeepsApplicationFramesNearestFirstAsRelativePathAndLine(): void
    {
        $trace = [
            self::frame(self::PACKAGE . '/support/Guard.php', 40),
            self::frame(self::PACKAGE . '/sql/Recorder.php', 35),
            self::frame(self::VENDOR . '/yiisoft/yii2/db/Command.php', 1180),
            self::frame(self::ROOT . '/models/OrderSearch.php', 57),
            self::frame(self::VENDOR . '/yiisoft/yii2/data/ActiveDataProvider.php', 160),
            self::frame(self::ROOT . '/controllers/OrderController.php', 31),
            self::frame(self::ENTRY, 12),
        ];

        self::assertSame(
            ['models/OrderSearch.php:57', 'controllers/OrderController.php:31'],
            $this->frames()->frames($trace),
        );
    }

    public function testKeepsAtMostThreeFrames(): void
    {
        $trace = [
            self::frame(self::ROOT . '/a.php', 1),
            self::frame(self::ROOT . '/b.php', 2),
            self::frame(self::ROOT . '/c.php', 3),
            self::frame(self::ROOT . '/d.php', 4),
        ];

        self::assertSame(['a.php:1', 'b.php:2', 'c.php:3'], $this->frames()->frames($trace));
    }

    public function testFramesWithoutFileAreSkippedAndDoNotCountTowardsThree(): void
    {
        $trace = [
            ['function' => '{closure}'],
            self::frame(self::ROOT . '/a.php', 1),
            ['function' => 'array_map'],
            self::frame(self::ROOT . '/b.php', 2),
            ['function' => 'call_user_func'],
            self::frame(self::ROOT . '/c.php', 3),
        ];

        self::assertSame(['a.php:1', 'b.php:2', 'c.php:3'], $this->frames()->frames($trace));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonApplicationFileCases(): iterable
    {
        yield 'vendor' => [self::VENDOR . '/yiisoft/yii2/db/Command.php'];
        yield 'package source' => [self::PACKAGE . '/sql/Command.php'];
        yield 'entry script' => [self::ENTRY];
        yield 'outside the project root' => ['/usr/share/php/Other.php'];
        yield 'sibling of the project root with the same prefix' => ['/srv/shop-legacy/models/Order.php'];
        yield 'eval outside the project root' => ['/usr/share/php/x.php(3) : eval()\'d code'];
    }

    #[DataProvider('provideNonApplicationFileCases')]
    public function testFileThatIsNotApplicationCodeIsSkipped(string $file): void
    {
        $trace = [self::frame($file, 10), self::frame(self::ROOT . '/models/Order.php', 20)];

        self::assertSame(['models/Order.php:20'], $this->frames()->frames($trace));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSiblingOfExcludedDirectoryCases(): iterable
    {
        yield 'sibling of vendor' => [self::ROOT . '/vendor-local/Tool.php', 'vendor-local/Tool.php:10'];
        yield 'file named like the entry script elsewhere' => [self::ROOT . '/web/index.php.bak', 'web/index.php.bak:10'];
    }

    /**
     * A directory is a prefix only up to a path separator; a sibling that shares the prefix is application code.
     */
    #[DataProvider('provideSiblingOfExcludedDirectoryCases')]
    public function testSiblingThatSharesPrefixOfExcludedPathIsApplicationCode(string $file, string $expected): void
    {
        self::assertSame([$expected], $this->frames()->frames([self::frame($file, 10)]));
    }

    /**
     * The package's own test suite: the package sits in `src/` of the project root, not under vendor.
     */
    public function testPackageDirectoryOutsideVendorIsSkippedButItsSiblingIsNot(): void
    {
        $frames = new CallerFrames(self::ROOT, self::VENDOR, self::ROOT . '/src', null);
        $trace = [
            self::frame(self::ROOT . '/src/sql/Recorder.php', 35),
            self::frame(self::ROOT . '/src2/Helper.php', 7),
            self::frame(self::ROOT . '/tests/app/Scenario.php', 9),
        ];

        self::assertSame(['src2/Helper.php:7', 'tests/app/Scenario.php:9'], $frames->frames($trace));
    }

    public function testWithoutEntryScriptItIsAnOrdinaryApplicationFrame(): void
    {
        $frames = new CallerFrames(self::ROOT, self::VENDOR, self::PACKAGE, null);

        self::assertSame(['web/index.php:12'], $frames->frames([self::frame(self::ENTRY, 12)]));
    }

    public function testTraceWithoutApplicationFrameGivesEmptyList(): void
    {
        $trace = [
            self::frame(self::PACKAGE . '/sql/Recorder.php', 35),
            self::frame(self::VENDOR . '/yiisoft/yii2/web/UrlManager.php', 200),
            self::frame(self::ENTRY, 12),
        ];

        self::assertSame([], $this->frames()->frames($trace));
        self::assertSame([], $this->frames()->frames([]));
    }

    public function testNoValueIsAnAbsolutePath(): void
    {
        $trace = [
            self::frame(self::ROOT . '/models/Order.php', 1),
            self::frame('/tmp/generated.php', 2),
            self::frame(self::ROOT . '/controllers/SiteController.php', 3),
        ];

        foreach ($this->frames()->frames($trace) as $value) {
            self::assertStringStartsNotWith('/', $value, 'caller never carries the server directory layout');
            self::assertStringNotContainsString(self::ROOT, $value);
        }
    }

    private function frames(): CallerFrames
    {
        return new CallerFrames(self::ROOT, self::VENDOR, self::PACKAGE, self::ENTRY);
    }

    /**
     * @return array{file: string, line: int, function: string}
     */
    private static function frame(string $file, int $line): array
    {
        return ['file' => $file, 'line' => $line, 'function' => 'call'];
    }
}
