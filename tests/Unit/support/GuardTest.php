<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\support;

use mrstroz\querymonitoring\adapter\FileAdapterException;
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
}
