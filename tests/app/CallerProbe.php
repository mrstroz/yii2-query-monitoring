<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use mrstroz\querymonitoring\sql\Command;

/**
 * YQM-27: where the application frames sit in the stack of each query, and what a trace costs there.
 *
 * Called from {@see TestPdoStatement::execute()} when `QM_CALLER_PROBE` names a file; writes one JSON line
 * per executed statement. Positions are indexes in the `debug_backtrace()` that YQM-28 takes inside the
 * guard's closure in `Recorder::record()`: `[0] {closure}`, `[1] Guard::run`, `[2] Recorder::record`,
 * `[3] Command::recordAttempt`, `[4] Command::measuredExecute`. The probe finds the `measuredExecute`
 * frame and shifts by {@see self::CAPTURE_OFFSET}; a trace helper between the closure and
 * `debug_backtrace()` would add one more frame. A limit `N` covers position `p` when `N >= p + 1`.
 *
 * An application frame has a `file` outside `@vendor` and outside the package's `src/`. `@vendor` has to be
 * the real vendor directory (`vendorPath` in tests/app/config.php): if `realpath()` fails, the prefix becomes
 * `/`, every frame looks like vendor and no application frame is found.
 * With `QM_CALLER_TIMING` (comma-separated limits, `0` = whole stack) the first query that does not
 * load schema also times `debug_backtrace()` at that depth: 5 rounds of 200 calls per limit.
 */
final class CallerProbe
{
    public const CAPTURE_OFFSET = 4;
    private const ROUNDS = 5;
    private const CALLS = 200;

    private static bool $timed = false;

    public static function enabled(): bool
    {
        return (string) getenv('QM_CALLER_PROBE') !== '';
    }

    public static function record(string $sql): void
    {
        $file = (string) getenv('QM_CALLER_PROBE');
        if ($file === '') {
            return;
        }
        $line = self::describe($sql, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        if (($line['kind'] ?? null) === 'app' && !self::$timed && (string) getenv('QM_CALLER_TIMING') !== '') {
            self::$timed = true;
            $line['timing'] = self::measure();
        }
        file_put_contents($file, json_encode($line, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param list<array<string, mixed>> $trace
     *
     * @return array<string, mixed>
     */
    private static function describe(string $sql, array $trace): array
    {
        $line = [
            'op' => strtolower((string) strtok(ltrim($sql), " \n\t(")),
            'sql' => substr(preg_replace('/\s+/', ' ', $sql) ?? $sql, 0, 60),
        ];
        $anchor = null;
        foreach ($trace as $index => $frame) {
            if (($frame['class'] ?? null) === Command::class && $frame['function'] === 'measuredExecute') {
                $anchor = $index;
                break;
            }
        }
        if ($anchor === null) {
            return $line + ['error' => 'no Command::measuredExecute frame in the stack'];
        }

        $root = dirname(__DIR__, 2);
        $vendor = (string) realpath(\Yii::getAlias('@vendor'));
        $src = $root . '/src';
        $entryScript = $root . '/tests/app/web/index.php';
        $app = [];
        $entryPosition = null;
        $schema = false;
        for ($index = $anchor; $index < count($trace); $index++) {
            $frame = $trace[$index];
            $class = $frame['class'] ?? null;
            if (is_string($class) && is_a($class, \yii\db\Schema::class, true)) {
                $schema = true;
            }
            $path = $frame['file'] ?? null;
            if (!is_string($path) || str_starts_with($path, $vendor . '/') || str_starts_with($path, $src . '/')) {
                continue;
            }
            $position = $index - $anchor + self::CAPTURE_OFFSET;
            $app[] = ['position' => $position, 'at' => substr($path, strlen($root) + 1) . ':' . ($frame['line'] ?? 0)];
            if ($path === $entryScript) {
                $entryPosition = $position;
            }
        }

        return $line + [
            'kind' => $schema ? 'schema' : 'app',
            'beforeController' => \Yii::$app !== null && \Yii::$app->controller === null,
            'depth' => count($trace) - $anchor + self::CAPTURE_OFFSET,
            'app' => $app,
            'entryScript' => $entryPosition,
        ];
    }

    /**
     * Called from {@see self::record()}, so inside the timed loop `measuredExecute` sits at index
     * {@see self::CAPTURE_OFFSET}, as in `Recorder::record()`: timeAt, measure, record, execute.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function measure(): array
    {
        $out = [];
        foreach (explode(',', (string) getenv('QM_CALLER_TIMING')) as $limit) {
            $out[$limit] = self::timeAt((int) $limit);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function timeAt(int $limit): array
    {
        $rounds = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $start = hrtime(true);
            for ($call = 0; $call < self::CALLS; $call++) {
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
            }
            $rounds[] = (hrtime(true) - $start) / 1e3;
        }
        $sorted = $rounds;
        sort($sorted);
        $median = $sorted[intdiv(self::ROUNDS, 2)];

        // Upper bound for memory: all 200 traces kept alive at once.
        $peakBefore = memory_get_peak_usage(true);
        $usageBefore = memory_get_usage();
        $kept = [];
        for ($call = 0; $call < self::CALLS; $call++) {
            $kept[] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
        }
        $usageDelta = memory_get_usage() - $usageBefore;
        $peakDelta = memory_get_peak_usage(true) - $peakBefore;
        unset($kept);

        return [
            'frames' => count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit)),
            'rounds_us' => array_map(static fn(float $us): float => round($us, 1), $rounds),
            'median_200_us' => round($median, 1),
            'per_call_us' => round($median / self::CALLS, 3),
            'memory_usage_delta' => $usageDelta,
            'memory_peak_real_delta' => $peakDelta,
        ];
    }
}
