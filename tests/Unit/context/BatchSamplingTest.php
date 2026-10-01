<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\context;

use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\Sample;
use mrstroz\querymonitoring\batch\SampleReason;
use mrstroz\querymonitoring\context\BatchSampling;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;

/**
 * YQM-58, spec 01 §1 and §5.6: the `sampling` setting is read into one decision object or rejected as a whole, and
 * the default draw depends only on the batch `id` and `seq`.
 */
final class BatchSamplingTest extends TestCase
{
    public function testMissingSettingMeansNoSampling(): void
    {
        self::assertNull(BatchSampling::fromConfig(null));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideNonArraySettingCases(): iterable
    {
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'a rate given alone' => [0.1];
        yield 'an integer' => [1];
        yield 'a string' => ['x'];
        yield 'an object' => [new \stdClass()];
    }

    /**
     * Review finding 1: a non-array `sampling` is a configuration error of the package, not a TypeError while Yii
     * configures the component (spec 00 §2).
     */
    #[DataProvider('provideNonArraySettingCases')]
    public function testNonArraySettingIsRejected(mixed $config): void
    {
        $this->expectException(InvalidConfigException::class);

        BatchSampling::fromConfig($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideValidConfigCases(): iterable
    {
        yield 'rate only' => [['rate' => 0.1]];
        yield 'integer rate one' => [['rate' => 1]];
        yield 'rate zero with one criterion' => [['rate' => 0, 'keepErrors' => true]];
        yield 'rate zero with a threshold' => [['rate' => 0.0, 'minQueries' => 1]];
        yield 'every criterion' => [['rate' => 0.1, 'keepErrors' => true, 'slowQueryMs' => 200, 'slowBatchMs' => 1000.5, 'minQueries' => 100]];
        yield 'criteria explicitly off' => [['rate' => 0.25, 'keepErrors' => false, 'slowQueryMs' => null, 'slowBatchMs' => null, 'minQueries' => null]];
        yield 'smallest thresholds' => [['rate' => 0.5, 'slowQueryMs' => 0.001, 'slowBatchMs' => 0.001, 'minQueries' => 1]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('provideValidConfigCases')]
    public function testValidSettingIsAccepted(array $config): void
    {
        self::assertInstanceOf(BatchSampling::class, BatchSampling::fromConfig($config));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInvalidConfigCases(): iterable
    {
        yield 'empty' => [[]];
        yield 'no rate' => [['keepErrors' => true]];
        yield 'rate below zero' => [['rate' => -0.01]];
        yield 'rate above one' => [['rate' => 1.01]];
        yield 'rate as numeric string' => [['rate' => '0.1']];
        yield 'rate as bool' => [['rate' => true]];
        yield 'rate NaN' => [['rate' => NAN]];
        yield 'rate infinite' => [['rate' => INF]];
        yield 'rate zero without a criterion' => [['rate' => 0]];
        yield 'rate zero with every criterion off' => [['rate' => 0.0, 'keepErrors' => false, 'slowQueryMs' => null]];
        yield 'keepErrors not a bool' => [['rate' => 0.1, 'keepErrors' => 1]];
        yield 'keepErrors null' => [['rate' => 0.1, 'keepErrors' => null]];
        yield 'slowQueryMs zero' => [['rate' => 0.1, 'slowQueryMs' => 0]];
        yield 'slowQueryMs negative' => [['rate' => 0.1, 'slowQueryMs' => -5]];
        yield 'slowQueryMs infinite' => [['rate' => 0.1, 'slowQueryMs' => INF]];
        yield 'slowQueryMs string' => [['rate' => 0.1, 'slowQueryMs' => '200']];
        yield 'slowBatchMs zero' => [['rate' => 0.1, 'slowBatchMs' => 0.0]];
        yield 'slowBatchMs NaN' => [['rate' => 0.1, 'slowBatchMs' => NAN]];
        yield 'minQueries zero' => [['rate' => 0.1, 'minQueries' => 0]];
        yield 'minQueries float' => [['rate' => 0.1, 'minQueries' => 2.5]];
        yield 'unknown key' => [['rate' => 0.1, 'keepError' => true]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('provideInvalidConfigCases')]
    public function testInvalidSettingIsRejected(array $config): void
    {
        $this->expectException(InvalidConfigException::class);

        BatchSampling::fromConfig($config);
    }

    /**
     * Values of `xxh3("{id}:{seq}")`, first 52 bits over 2^52 (spec 01 §5.6), computed once in the PHP 8.1 image.
     *
     * @return iterable<string, array{string, int, float}>
     */
    public static function provideDrawCases(): iterable
    {
        yield 'zero id, seq 1' => ['0000000000000000', 1, 0.88754092459113054];
        yield 'zero id, seq 2' => ['0000000000000000', 2, 0.73663294483956498];
        yield 'all-f id, seq 1' => ['ffffffffffffffff', 1, 0.83490176646420755];
        yield 'mixed id, seq 7' => ['0123456789abcdef', 7, 0.52236898557284439];
    }

    #[DataProvider('provideDrawCases')]
    public function testDrawIsFixedByIdAndSeq(string $id, int $seq, float $expected): void
    {
        self::assertSame($expected, BatchSampling::draw($id, $seq));
        self::assertSame(BatchSampling::draw($id, $seq), BatchSampling::draw($id, $seq), 'the same batch handled again gets the same draw');
    }

    public function testDrawStaysBelowOne(): void
    {
        for ($seq = 1; $seq <= 1000; $seq++) {
            $draw = BatchSampling::draw('ffffffffffffffff', $seq);
            self::assertGreaterThanOrEqual(0.0, $draw);
            self::assertLessThan(1.0, $draw);
        }
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideWidestCases(): iterable
    {
        yield 'short rate' => [0.1];
        yield 'rate one' => [1.0];
        yield 'rate zero' => [0.0];
        yield 'rate with every digit' => [0.12345678901234568];
        yield 'tiny rate in exponent notation' => [1.0E-7];
    }

    /**
     * spec 02 §5: the reservation covers every `sample` the batch can get, so no real one is longer in JSON.
     */
    #[DataProvider('provideWidestCases')]
    public function testWidestSampleIsAtLeastAsLongAsEverySampleTheBatchCanGet(float $rate): void
    {
        $sampling = new BatchSampling($rate, keepErrors: true, slowQueryMs: 1.0, slowBatchMs: 1.0, minQueries: 1);

        $widest = self::json($sampling->widest());

        $possible = [new Sample($rate, SampleReason::Sample)];
        foreach (SampleReason::cases() as $reason) {
            $possible[] = new Sample(1.0, $reason);
        }
        foreach ($possible as $sample) {
            self::assertGreaterThanOrEqual(strlen(self::json($sample)), strlen($widest), self::json($sample));
        }
    }

    private static function json(Sample $sample): string
    {
        return json_encode($sample->toArray(), QueryBatch::JSON_FLAGS);
    }
}
