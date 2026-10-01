<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\context;

use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\context\UserSource;
use mrstroz\querymonitoring\context\UserValueException;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\base\InvalidConfigException;

/**
 * YQM-60, spec 01 §5.7: which `user` settings are accepted, and what a source's value becomes in the header. The
 * default source (`true`) is read through a real `yii\web\User` in Integration\Yii\UserComponentTest.
 */
final class UserSourceTest extends LoggedTestCase
{
    public function testNullIsOff(): void
    {
        self::assertNull(UserSource::fromConfig(null));
    }

    public function testTrueWithoutAnApplicationGivesNull(): void
    {
        $app = \Yii::$app;
        \Yii::$app = null;
        try {
            $source = UserSource::fromConfig(true);
            self::assertNotNull($source);
            self::assertNull($source->resolve($this->batch()));
        } finally {
            \Yii::$app = $app;
        }
        self::assertSame([], $this->errors());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideCallableCases(): iterable
    {
        yield 'a closure' => [static fn(QueryBatch $batch): string => 'u-7'];
        yield 'a static method as an array' => [[self::class, 'staticSource']];
        yield 'a static method as Class::method' => [self::class . '::staticSource'];
        yield 'an invokable object' => [new class {
            public function __invoke(QueryBatch $batch): string
            {
                return 'u-7';
            }
        }];
        yield 'a method of an object' => [[new UserSourceTestObject(), 'source']];
    }

    #[DataProvider('provideCallableCases')]
    public function testCallableIsAcceptedAndCalledWithTheBatch(mixed $config): void
    {
        $source = UserSource::fromConfig($config);

        self::assertNotNull($source);
        self::assertSame('u-7', $source->resolve($this->batch()));
        self::assertSame([], $this->errors());
    }

    public function testFunctionNameIsAcceptedAsACallable(): void
    {
        $source = UserSource::fromConfig('get_debug_type');

        self::assertNotNull($source);
        self::assertSame(QueryBatch::class, $source->resolve($this->batch()));
    }

    public function testCallableGetsTheBatchItself(): void
    {
        $seen = [];
        $batch = $this->batch();
        $source = UserSource::fromConfig(static function (QueryBatch $given) use (&$seen): ?string {
            $seen[] = $given;

            return null;
        });
        self::assertNotNull($source);

        self::assertNull($source->resolve($batch));
        self::assertSame([$batch], $seen);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideWrongSettingCases(): iterable
    {
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'one' => [1];
        yield 'a float' => [0.5];
        yield 'a text that names no function' => ['qm_no_such_function'];
        yield 'an empty text' => [''];
        yield 'an empty array' => [[]];
        yield 'a non-static method' => [[UserSourceTestObject::class, 'source']];
        yield 'a method of an unknown class' => [['qm\NoSuchClass', 'of']];
        yield 'an unknown method' => [[self::class, 'noSuchMethod']];
        yield 'a private static method' => [[UserSourceTestObject::class, 'hidden']];
        yield 'an object without __invoke' => [new \stdClass()];
        yield 'a list of three' => [['a', 'b', 'c']];
    }

    #[DataProvider('provideWrongSettingCases')]
    public function testWrongSettingIsAConfigurationError(mixed $config): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('QueryMonitor::$user must be null, true or a callable.');

        UserSource::fromConfig($config);
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function provideAcceptedValueCases(): iterable
    {
        yield 'null' => [null, null];
        yield 'an empty text' => ['', null];
        yield 'an int' => [42, '42'];
        yield 'zero' => [0, '0'];
        yield 'a negative int' => [-5, '-5'];
        yield 'the largest int' => [PHP_INT_MAX, '9223372036854775807'];
        yield 'zero as text' => ['0', '0'];
        yield 'a text with spaces' => [' ', ' '];
        yield '64 ASCII characters' => [str_repeat('a', 64), str_repeat('a', 64)];
        yield '63 characters and a slash, not escaped' => [str_repeat('a', 63) . '/', str_repeat('a', 63) . '/'];
        yield '32 two-byte letters, 64 bytes' => [str_repeat('ż', 32), str_repeat('ż', 32)];
        yield 'a hex sha256' => [hash('sha256', '42'), hash('sha256', '42')];
        yield 'a Stringable object' => [new UserSourceTestObject(), 'u-7'];
        yield 'a Stringable object giving an empty text' => [new UserSourceTestObject(''), null];
        yield 'a MongoDB ObjectId' => [new \MongoDB\BSON\ObjectId('65f000000000000000000001'), '65f000000000000000000001'];
    }

    #[DataProvider('provideAcceptedValueCases')]
    public function testAcceptedValue(mixed $value, ?string $expected): void
    {
        $source = UserSource::fromConfig(static fn(): mixed => $value);
        self::assertNotNull($source);

        self::assertSame($expected, $source->resolve($this->batch()));
        self::assertSame([], $this->errors());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideRejectedValueCases(): iterable
    {
        $longer = 'got a string longer than 64 bytes.';
        $json = 'got a string longer than 66 bytes as JSON.';
        $utf8 = 'got an invalid UTF-8 string.';
        yield '65 ASCII characters' => [str_repeat('a', 65), $longer];
        yield '33 two-byte letters, 66 bytes' => [str_repeat('ż', 33), $longer];
        yield '100 invalid bytes, checked for length first' => [str_repeat("\xC3", 100), $longer];
        yield 'a Stringable object giving 65 characters' => [new UserSourceTestObject(str_repeat('a', 65)), $longer];
        yield '63 characters and a quote' => [str_repeat('a', 63) . '"', $json];
        yield '63 characters and a backslash' => [str_repeat('a', 63) . '\\', $json];
        yield '63 characters and a control character' => [str_repeat('a', 63) . "\x01", $json];
        yield 'invalid UTF-8' => ["qm-\xC3", $utf8];
        yield 'a lone continuation byte' => ["\x80", $utf8];
        yield 'a Stringable object giving invalid UTF-8' => [new UserSourceTestObject("\x80"), $utf8];
        yield 'a float' => [4.2, 'got float.'];
        yield 'a whole float' => [42.0, 'got float.'];
        yield 'true' => [true, 'got bool.'];
        yield 'false' => [false, 'got bool.'];
        yield 'an array' => [['id' => 42], 'got array.'];
        yield 'an object without __toString' => [new \stdClass(), 'got stdClass.'];
    }

    #[DataProvider('provideRejectedValueCases')]
    public function testRejectedValueGivesNullAndOneErrorNamingOnlyTheReason(mixed $value, string $reason): void
    {
        $source = UserSource::fromConfig(static fn(): mixed => $value);
        self::assertNotNull($source);

        self::assertNull($source->resolve($this->batch()));
        self::assertNull($source->resolve($this->batch()), 'every later batch too');
        $errors = $this->errors();
        self::assertCount(1, $errors, 'one error per process');
        self::assertSame(Guard::LOG_CATEGORY, $errors[0][2]);
        self::assertSame(
            'Query monitoring failed in user with ' . UserValueException::class . ': QueryMonitor::$user must give an int, '
            . 'null, a Stringable or a valid UTF-8 string of at most 66 bytes as JSON; ' . $reason,
            $errors[0][0],
        );
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function provideSourceExceptionCases(): iterable
    {
        yield 'a runtime exception' => [static fn(): mixed => throw new \RuntimeException('qm-user-marker 1187'), \RuntimeException::class];
        yield 'a configuration error, trusted elsewhere' => [
            static fn(): mixed => throw new InvalidConfigException('qm-user-marker 1187'),
            InvalidConfigException::class,
        ];
        yield 'a rejected-value exception thrown by the source itself' => [
            static fn(): mixed => throw new \UnexpectedValueException('qm-user-marker 1187'),
            \UnexpectedValueException::class,
        ];
        yield 'a Stringable whose __toString throws' => [static fn(): object => new UserSourceTestObject(null), \RuntimeException::class];
    }

    /**
     * Review finding 3: the message of an exception the source throws may carry the id, so it is never logged,
     * whatever its class.
     *
     * @param \Closure(): mixed $read
     */
    #[DataProvider('provideSourceExceptionCases')]
    public function testExceptionOfTheSourceGivesNullAndIsLoggedByClassOnly(\Closure $read, string $class): void
    {
        $source = UserSource::fromConfig($read);
        self::assertNotNull($source);

        self::assertNull($source->resolve($this->batch()));
        self::assertSame(['Query monitoring failed in user with ' . $class], array_column($this->errors(), 0));
    }

    public function testSourceErrorsUseOnlyTheGuardTheyAreGiven(): void
    {
        $source = UserSource::fromConfig(static fn(): array => []);
        self::assertNotNull($source);
        $other = new Guard();

        $source->resolve($this->batch());
        $other->run(static function (): never {
            throw new \RuntimeException('adapter');
        }, 'send');

        self::assertSame(
            ['in user with ' . UserValueException::class, 'in send with RuntimeException'],
            array_map(static fn(array $m): string => (string) preg_replace('/^Query monitoring failed (in \\S+ with [^:]+).*$/', '$1', $m[0]), $this->errors()),
        );
    }

    public function testWidestIsTheLongestAcceptedValue(): void
    {
        $source = UserSource::fromConfig(static fn(): string => UserSource::widest());
        self::assertNotNull($source);

        self::assertSame(UserSource::widest(), $source->resolve($this->batch()));
        self::assertSame(UserSource::MAX_JSON_BYTES, strlen(json_encode(UserSource::widest(), QueryBatch::JSON_FLAGS)));
        self::assertSame(66, UserSource::MAX_JSON_BYTES, 'spec 02 §1');
    }

    public static function staticSource(QueryBatch $batch): string
    {
        return 'u-7';
    }

    private function batch(): QueryBatch
    {
        return new QueryBatch('app', BatchType::Http, 'id', 1, null, new \DateTimeImmutable('2026-09-22T09:41:05.312Z'), 'host', 0, []);
    }
}

/**
 * A source given as an object method, and values that are not callables of the right kind.
 */
final class UserSourceTestObject implements \Stringable
{
    /**
     * @param string|null $text what __toString() gives; null makes it throw
     */
    public function __construct(private readonly ?string $text = 'u-7') {}

    public function source(QueryBatch $batch): string
    {
        return 'u-7';
    }

    public function __toString(): string
    {
        return $this->text ?? throw new \RuntimeException('qm-user-marker 1187');
    }

    /** @phpstan-ignore method.unused (named by the test as a callable that is not visible to the package) */
    private static function hidden(QueryBatch $batch): string
    {
        return 'never';
    }
}
