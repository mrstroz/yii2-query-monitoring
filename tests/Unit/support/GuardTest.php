<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\support;

use mrstroz\querymonitoring\adapter\FileAdapterException;
use mrstroz\querymonitoring\context\UserValueException;
use mrstroz\querymonitoring\support\Guard;
use mrstroz\querymonitoring\tests\Unit\LoggedTestCase;

/**
 * spec 00 §2, 01 §6: package code never changes the application's result; one Yii::error per process, without query text.
 */
final class GuardTest extends LoggedTestCase
{
    public function testReturnsValueOfCallback(): void
    {
        self::assertSame(42, (new Guard())->run(static fn(): int => 42, 'test'));
        self::assertSame([], $this->errors());
    }

    public function testExceptionIsSwallowedAndReturnsNull(): void
    {
        $result = (new Guard())->run($this->throwing(...), 'test');

        self::assertNull($result);
        self::assertCount(1, $this->errors());
    }

    public function testErrorIsSwallowedToo(): void
    {
        $result = (new Guard())->run(static fn(): int => intdiv(1, 0), 'test');

        self::assertNull($result);
        self::assertCount(1, $this->errors());
    }

    public function testOnlyFirstFailureIsLogged(): void
    {
        $guard = new Guard();
        for ($i = 0; $i < 5; $i++) {
            $guard->run(static function () use ($i): never {
                throw new \RuntimeException("boom {$i}");
            }, 'test');
        }

        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame(Guard::LOG_CATEGORY, $errors[0][2]);
    }

    public function testLogDoesNotContainQueryText(): void
    {
        (new Guard())->run(static function (): never {
            throw new \RuntimeException("SELECT * FROM users WHERE email = 'alice@example.com'");
        }, 'record');

        $errors = $this->errors();
        self::assertCount(1, $errors);
        $logged = is_string($errors[0][0]) ? $errors[0][0] : var_export($errors[0][0], true);
        self::assertStringNotContainsString('alice@example.com', $logged);
        self::assertStringNotContainsString('SELECT', $logged);
    }

    public function testFileAdapterExceptionIsLoggedWithItsWholeMessage(): void
    {
        $message = 'File adapter could not open the lock file /var/log/app/q.jsonl.lock: fopen(/var/log/app/q.jsonl.lock): Failed to open stream: Permission denied';
        (new Guard())->run(static function () use ($message): never {
            throw new FileAdapterException($message);
        }, 'send');

        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame('Query monitoring failed in send with ' . FileAdapterException::class . ': ' . $message, $errors[0][0]);
    }

    public function testOtherRuntimeExceptionIsLoggedByClassOnly(): void
    {
        (new Guard())->run(static function (): never {
            throw new \UnexpectedValueException('File adapter could not open /var/log/app/q.jsonl');
        }, 'send');

        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame('Query monitoring failed in send with UnexpectedValueException', $errors[0][0]);
    }

    private function throwing(): int
    {
        throw new \RuntimeException('boom');
    }

    public function testDefaultTrustedClassesAreThePackageOnes(): void
    {
        self::assertSame(
            [\yii\base\InvalidConfigException::class, FileAdapterException::class, \mrstroz\querymonitoring\context\ContextLimitException::class],
            Guard::DEFAULT_TRUSTED,
        );
        (new Guard())->run(static fn(): mixed => throw new \yii\base\InvalidConfigException('QueryMonitor::$app is required.'), 'bootstrap');

        self::assertSame(
            ['Query monitoring failed in bootstrap with yii\base\InvalidConfigException: QueryMonitor::$app is required.'],
            array_column($this->errors(), 0),
        );
    }

    /**
     * Review finding 3: the `user` source's guard trusts only the rejected-value exception, so a configuration error
     * the source itself throws is logged by its class.
     */
    public function testGivenTrustedClassesReplaceTheDefaultOnes(): void
    {
        $guard = new Guard([UserValueException::class]);
        $guard->run(static fn(): mixed => throw new \yii\base\InvalidConfigException('qm-user-marker 1187'), 'user');
        $other = new Guard([UserValueException::class]);
        $other->run(static fn(): mixed => throw new UserValueException('got array'), 'user');

        self::assertSame(
            ['Query monitoring failed in user with yii\base\InvalidConfigException', 'Query monitoring failed in user with ' . UserValueException::class . ': got array'],
            array_column($this->errors(), 0),
        );
    }

    public function testRejectedUserValueIsNotTrustedByTheDefaultGuard(): void
    {
        (new Guard())->run(static fn(): mixed => throw new UserValueException('got array'), 'send');

        self::assertSame(['Query monitoring failed in send with ' . UserValueException::class], array_column($this->errors(), 0));
    }

    public function testSubclassOfATrustedClassIsTrusted(): void
    {
        (new Guard([\RuntimeException::class]))->run(static fn(): mixed => throw new \UnexpectedValueException('names the reason'), 'test');

        self::assertSame(['Query monitoring failed in test with UnexpectedValueException: names the reason'], array_column($this->errors(), 0));
    }
}
