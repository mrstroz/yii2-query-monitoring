<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\support;

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

    private bool $logged = false;

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
     * Only the package's own configuration errors carry their message; any other exception is
     * logged by class, because its message may quote SQL.
     */
    private function log(\Throwable $e, string $context): void
    {
        if ($this->logged) {
            return;
        }
        $this->logged = true;
        $detail = $e instanceof \yii\base\InvalidConfigException ? ': ' . $e->getMessage() : '';

        try {
            \Yii::error(sprintf('Query monitoring failed in %s with %s%s', $context, $e::class, $detail), self::LOG_CATEGORY);
        } catch (\Throwable) {
            // Logging must not become the failure it reports.
        }
    }
}
