<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use yii\helpers\FileHelper;

/**
 * Runs the test application (tests/app/web/index.php) in a separate PHP process, like one PHP-FPM request.
 *
 * The child process gets, through environment variables:
 * - `QM_COMPONENT` — JSON of `$componentConfig`, merged into the `queryMonitor` component
 *   (the `adapter` defaults to the capturing adapter),
 * - `QM_ROUTE` — the route to run,
 * - `QM_DB` — `mysql` or `pgsql`, the database behind `db`, `dbOther` and `admin/db`
 *   (DSNs from `QM_MYSQL_DSN`/`QM_PGSQL_DSN` and credentials set in docker-compose.yml),
 * - `QM_CAPTURE_FILE`, `QM_LOG_FILE` — per-run files created and removed by the runner
 *   (with `QM_CAPTURE_FILE.calls`, the capturing adapter's call log),
 * - `QM_RUNTIME` — a per-run `@runtime` directory, read into {@see RunResult::$runtimeFiles} and removed,
 * - everything in `$env`, e.g. `QM_SCENARIO` or the {@see TestPdo} switches.
 *
 * Route {@see self::SCENARIO_ROUTE} loads `tests/Integration/scenarios/<QM_SCENARIO>.php`, which returns
 * `callable(\yii\web\Application): mixed`, and prints the returned value as JSON on stdout.
 */
final class AppRunner
{
    public const SCENARIO_ROUTE = 'scenario/run';
    public const TIMEOUT_SECONDS = 30;

    /**
     * @param array<string, mixed> $componentConfig
     * @param array<string, string> $env
     */
    public static function run(array $componentConfig, string $route, array $env = []): RunResult
    {
        $captureFile = (string) tempnam(sys_get_temp_dir(), 'qm-capture-');
        $logFile = (string) tempnam(sys_get_temp_dir(), 'qm-log-');
        $runtime = sys_get_temp_dir() . '/qm-runtime-' . bin2hex(random_bytes(6));
        mkdir($runtime);
        $childEnv = array_merge(getenv(), [
            'QM_COMPONENT' => (string) json_encode((object) $componentConfig),
            'QM_ROUTE' => $route,
            'QM_CAPTURE_FILE' => $captureFile,
            'QM_LOG_FILE' => $logFile,
            'QM_RUNTIME' => $runtime,
        ], $env);

        try {
            [$exitCode, $stdout, $stderr] = self::execute($childEnv);

            $logs = array_map(static fn(array $log): array => [
                'level' => (string) ($log['level'] ?? ''),
                'category' => (string) ($log['category'] ?? ''),
                'message' => (string) ($log['message'] ?? ''),
            ], self::readLines($logFile));

            return new RunResult($exitCode, $stdout, $stderr, self::readLines($captureFile), $logs, self::readLines($captureFile . '.calls'), self::readFiles($runtime));
        } finally {
            @unlink($captureFile);
            @unlink($captureFile . '.calls');
            @unlink($logFile);
            FileHelper::removeDirectory($runtime);
        }
    }

    /** Whether the DSN for `$db` is configured; integration tests skip when it is not. */
    public static function hasDatabase(string $db): bool
    {
        return (string) getenv('QM_' . strtoupper($db) . '_DSN') !== '';
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private static function execute(array $env): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/web/index.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the test application.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        do {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 0, 100_000);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
        } while ($status['running'] && microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($process, 9);
            proc_close($process);

            throw new \RuntimeException("Test application timed out after " . self::TIMEOUT_SECONDS . " s.\nstdout: {$stdout}\nstderr: {$stderr}");
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$status['exitcode'], $stdout, $stderr];
    }

    /**
     * Every file under `$directory`, by its path relative to it, sorted.
     *
     * @return array<string, string>
     */
    private static function readFiles(string $directory): array
    {
        $files = [];
        foreach (FileHelper::findFiles($directory) as $file) {
            $files[substr($file, strlen($directory) + 1)] = (string) file_get_contents($file);
        }
        ksort($files);

        return $files;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function readLines(string $file): array
    {
        $lines = [];
        if (!is_file($file)) {
            return $lines;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }
}
