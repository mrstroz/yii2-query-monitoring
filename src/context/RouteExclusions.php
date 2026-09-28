<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\context;

use mrstroz\querymonitoring\batch\BatchType;
use yii\base\InvalidConfigException;

/**
 * The patterns of `excludedRoutes`, one list per context type (spec 01 §5.4, ADR-0013).
 *
 * A pattern matches its value exactly, or as a prefix when it ends with `*`; `*` alone matches everything. `http`
 * and `console` patterns are compared with `route`, `job` patterns with the job name before truncation.
 *
 * @internal
 */
final class RouteExclusions
{
    /**
     * @param array<string, list<string>> $patterns by {@see BatchType} value
     */
    private function __construct(private readonly array $patterns) {}

    /**
     * @param array<mixed> $config the `excludedRoutes` setting
     *
     * @throws InvalidConfigException on a key other than `http`, `console`, `job`, a value that is not a list, or a
     *                                pattern that is empty, not text, starts with `/` or has `*` before its end
     */
    public static function fromConfig(array $config): self
    {
        $patterns = [];
        foreach ($config as $key => $list) {
            $type = is_string($key) ? BatchType::tryFrom($key) : null;
            if ($type === null) {
                throw new InvalidConfigException('QueryMonitor::$excludedRoutes has an unknown key: ' . $key . '.');
            }
            if (!is_array($list) || !array_is_list($list)) {
                throw new InvalidConfigException("QueryMonitor::\$excludedRoutes['{$key}'] must be a list of patterns.");
            }
            foreach ($list as $pattern) {
                if (!is_string($pattern) || $pattern === '' || $pattern[0] === '/' || !in_array(strpos($pattern, '*'), [false, strlen($pattern) - 1], true)) {
                    throw new InvalidConfigException("QueryMonitor::\$excludedRoutes['{$key}'] has a pattern that is empty, not text, starts with / or has * before its end.");
                }
            }
            $patterns[$type->value] = $list;
        }

        return new self($patterns);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Whether `$value` of a context of `$type` is excluded. Null, a route never decided, matches nothing.
     */
    public function matches(BatchType $type, ?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        foreach ($this->patterns[$type->value] ?? [] as $pattern) {
            if (str_ends_with($pattern, '*') ? str_starts_with($value, substr($pattern, 0, -1)) : $value === $pattern) {
                return true;
            }
        }

        return false;
    }
}
