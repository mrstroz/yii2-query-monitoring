<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\batch;

use mrstroz\querymonitoring\batch\JobInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * YQM-49, spec 02 §1: the header field `job` — four keys in order, texts cut to 255 bytes with `…` on a UTF-8
 * boundary, a numeric message id as text, an attempt below 1 as null, an empty name as `unknown`.
 */
final class JobInfoTest extends TestCase
{
    public function testKeysAreAlwaysFourInSpecOrder(): void
    {
        self::assertSame(['name', 'queue', 'message_id', 'attempt'], array_keys(JobInfo::create('app\jobs\A')->toArray()));
        self::assertSame(['name', 'queue', 'message_id', 'attempt'], array_keys(JobInfo::create('app\jobs\A', 'q', 'm', 1)->toArray()));
    }

    public function testNumericMessageIdBecomesText(): void
    {
        self::assertSame('42', JobInfo::create('a', messageId: 42)->toArray()['message_id']);
    }

    /**
     * @return iterable<string, array{?int, ?int}>
     */
    public static function provideAttemptCases(): iterable
    {
        yield 'first' => [1, 1];
        yield 'third' => [3, 3];
        yield 'zero' => [0, null];
        yield 'negative' => [-2, null];
        yield 'unknown' => [null, null];
    }

    #[DataProvider('provideAttemptCases')]
    public function testAttemptBelowOneIsNull(?int $attempt, ?int $expected): void
    {
        self::assertSame($expected, JobInfo::create('a', attempt: $attempt)->toArray()['attempt']);
    }

    public function testEmptyNameIsUnknownAndMatchedAsUnknown(): void
    {
        self::assertSame('unknown', JobInfo::create('')->toArray()['name']);
        self::assertSame('unknown', JobInfo::name(''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLongTextCases(): iterable
    {
        yield 'ascii' => [str_repeat('a', 300)];
        yield 'two-byte characters' => [str_repeat('ż', 200)];
        yield 'three-byte characters' => [str_repeat('€', 120)];
    }

    #[DataProvider('provideLongTextCases')]
    public function testLongTextsAreCutTo255BytesOnACharacterBoundary(string $text): void
    {
        $job = JobInfo::create($text, $text, $text)->toArray();

        foreach (['name', 'queue', 'message_id'] as $key) {
            self::assertIsString($job[$key]);
            self::assertLessThanOrEqual(255, strlen($job[$key]), $key);
            self::assertStringEndsWith('…', $job[$key], $key);
            self::assertTrue(mb_check_encoding($job[$key], 'UTF-8'), "{$key} is valid UTF-8");
            self::assertTrue(str_starts_with($text, substr($job[$key], 0, -strlen('…'))), "{$key} is a prefix of the original");
        }
    }

    public function testTextOfExactly255BytesIsKept(): void
    {
        $text = str_repeat('a', 255);

        self::assertSame($text, JobInfo::create($text)->toArray()['name']);
    }

    public function testNameIsMatchedBeforeTruncation(): void
    {
        $long = str_repeat('L', 300);

        self::assertSame($long, JobInfo::name($long));
    }
}
