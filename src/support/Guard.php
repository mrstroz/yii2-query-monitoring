<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\support;

use mrstroz\querymonitoring\adapter\FileAdapterException;
use mrstroz\querymonitoring\context\ContextLimitException;

/**
 * Runs package code so that it can never change the application's result (spec 00 §2, 01 §6).
 *
 * An exception is caught, not rethrown, and logged with `Yii::error` only the first time in the
 * process, without query text. One instance is created by the component and injected wherever
 * package code runs on the application's path.
 */
final class Guard
{
    public const LOG_CATEGORY = 'mrstroz\querymonitoring';

    /**
     * Exceptions whose message is logged by default: configuration errors, the file adapter's, whose message names the
     * operation and the path, and the context limit, which names the limit.
     */
    public const DEFAULT_TRUSTED = [
        \yii\base\InvalidConfigException::class,
        FileAdapterException::class,
        ContextLimitException::class,
    ];

    private bool $logged = false;

    /**
     * @param list<class-string<\Throwable>> $trusted exceptions whose message is logged; any other is logged by class
     */
    public function __construct(private readonly array $trusted = self::DEFAULT_TRUSTED) {}

    /**
     * @template T
     *
     * @param callable(): T $fn
     * @param string $context short name of the operation for the log message, e.g. `bootstrap`, `send`
     *
     * @return T|null null when `$fn` threw
     */
    public function run(callable $fn, string $context): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->log($e, $context);

            return null;
        }
    }

    /**
     * Only an exception of a trusted class carries its message. Any other exception is logged by class, because its
     * message may quote SQL or data.
     */
    private function log(\Throwable $e, string $context): void
    {
        if ($this->logged) {
            return;
        }
        $this->logged = true;
        $trusted = false;
        foreach ($this->trusted as $class) {
            $trusted = $trusted || $e instanceof $class;
        }
        $detail = $trusted ? ': ' . $e->getMessage() : '';

        try {
            \Yii::error(sprintf('Query monitoring failed in %s with %s%s', $context, $e::class, $detail), self::LOG_CATEGORY);
        } catch (\Throwable) {
            // Logging must not become the failure it reports.
        }
    }
}
