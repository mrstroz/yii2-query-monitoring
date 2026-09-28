<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use yii\helpers\FileHelper;

/**
 * Runs the console test application (tests/app/yii.php) in a separate PHP process, like one `php yii <route>`.
 *
 * The child gets the same environment as from {@see AppRunner} (`QM_COMPONENT`, `QM_DB`, `QM_CAPTURE_FILE`,
 * `QM_LOG_FILE`, `QM_RUNTIME` and everything in `$env`), and `$route` with `$args` on its command line.
 * Route {@see self::SCENARIO_ROUTE} loads `tests/Integration/scenarios/<QM_SCENARIO>.php`, which returns
 * `callable(\yii\console\Application): mixed`, and prints the returned value as JSON on stdout.
 */
final class ConsoleRunner
{
    public const SCENARIO_ROUTE = 'scenario/run';
    public const ENTRY_SCRIPT = __DIR__ . '/yii.php';
    public const TIMEOUT_SECONDS = 60;

    /**
     * @param array<string, mixed> $componentConfig
     * @param list<string> $args
     * @param array<string, string> $env
     */
    public static function run(array $componentConfig, string $route, array $args = [], array $env = []): RunResult
    {
        $captureFile = (string) tempnam(sys_get_temp_dir(), 'qm-capture-');
        $logFile = (string) tempnam(sys_get_temp_dir(), 'qm-log-');
        $runtime = sys_get_temp_dir() . '/qm-runtime-' . bin2hex(random_bytes(6));
        mkdir($runtime);
        $childEnv = array_merge(getenv(), [
            'QM_COMPONENT' => (string) json_encode((object) $componentConfig),
            'QM_CAPTURE_FILE' => $captureFile,
            'QM_LOG_FILE' => $logFile,
            'QM_RUNTIME' => $runtime,
        ], $env);

        try {
            [$exitCode, $stdout, $stderr] = self::execute([PHP_BINARY, self::ENTRY_SCRIPT, $route, ...$args], $childEnv);

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

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private static function execute(array $command, array $env): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the console test application.');
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

            throw new \RuntimeException('Console test application timed out after ' . self::TIMEOUT_SECONDS . " s.\nstdout: {$stdout}\nstderr: {$stderr}");
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$status['exitcode'], $stdout, $stderr];
    }

    /**
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
