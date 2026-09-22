<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Unit\support;

use mrstroz\querymonitoring\support\Guard;
use PHPUnit\Framework\TestCase;
use yii\log\Logger;

/**
 * spec 00 §2, 01 §6: package code never changes the application's result; one Yii::error per process, without query text.
 */
final class GuardTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        if (!class_exists('Yii', false)) {
            require_once __DIR__ . '/../../../vendor/yiisoft/yii2/Yii.php';
        }
        $this->logger = new Logger();
        $this->logger->flushInterval = PHP_INT_MAX;
        \yii\BaseYii::setLogger($this->logger);
    }

    protected function tearDown(): void
    {
        \yii\BaseYii::setLogger(null);
    }

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

    /**
     * @return list<array<int, mixed>>
     */
    private function errors(): array
    {
        return array_values(array_filter($this->logger->messages, static fn(array $m): bool => $m[1] === Logger::LEVEL_ERROR));
    }

    private function throwing(): int
    {
        throw new \RuntimeException('boom');
    }
}
