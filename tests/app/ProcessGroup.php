<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

/**
 * Runs one PHP script in N processes at once, released together by a common barrier (YQM-16).
 *
 * Each child is `php -d auto_prepend_file=<package autoload> <script>` with `QM_WORKER` set to its index
 * (0 … N-1). The script calls {@see self::awaitStart()} before its work: the child prints the ready line
 * and blocks on its stdin until the runner has seen every child ready (or already exited) and releases
 * all of them at once. A common deadline covers both the wait for readiness and the work; on it every
 * child still running is killed. {@see AppRunner} stays the runner for one request of the test application.
 */
final class ProcessGroup
{
    public const READY_LINE = "ready\n";

    /**
     * @param array<string, string> $env added to the environment of every child
     *
     * @return list<ProcessResult> in the order of `QM_WORKER`
     */
    public static function run(string $script, int $count, float $timeoutSeconds, array $env = []): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $deadline = microtime(true) + $timeoutSeconds;
        $processes = [];
        $pipes = [];
        $stdout = array_fill(0, $count, '');
        $stderr = array_fill(0, $count, '');
        $exitCodes = array_fill(0, $count, null);
        for ($i = 0; $i < $count; $i++) {
            $process = proc_open(
                [PHP_BINARY, '-d', 'auto_prepend_file=' . $autoload, $script],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $childPipes,
                null,
                array_merge(getenv(), $env, ['QM_WORKER' => (string) $i]),
            );
            if (!is_resource($process)) {
                self::kill($processes);

                throw new \RuntimeException("Cannot start process {$i} of {$script}.");
            }
            stream_set_blocking($childPipes[1], false);
            stream_set_blocking($childPipes[2], false);
            $processes[$i] = $process;
            $pipes[$i] = $childPipes;
        }

        $released = false;
        do {
            $read = [];
            foreach ($pipes as $i => $childPipes) {
                if ($exitCodes[$i] === null) {
                    $read[] = $childPipes[1];
                    $read[] = $childPipes[2];
                }
            }
            $write = null;
            $except = null;
            if ($read !== []) {
                stream_select($read, $write, $except, 0, 20_000);
            }
            foreach ($pipes as $i => $childPipes) {
                $stdout[$i] .= (string) stream_get_contents($childPipes[1]);
                $stderr[$i] .= (string) stream_get_contents($childPipes[2]);
                if ($exitCodes[$i] === null) {
                    $status = proc_get_status($processes[$i]);
                    if (!$status['running']) {
                        $exitCodes[$i] = $status['exitcode'];
                    }
                }
            }
            if (!$released && self::allReadyOrExited($stdout, $exitCodes)) {
                // One newline to every child in a row, so the barrier opens for all of them at once.
                foreach ($pipes as $childPipes) {
                    @fwrite($childPipes[0], "\n");
                }
                $released = true;
            }
        } while (in_array(null, $exitCodes, true) && microtime(true) < $deadline);

        $results = [];
        foreach ($processes as $i => $process) {
            $timedOut = $exitCodes[$i] === null;
            if ($timedOut) {
                proc_terminate($process, 9);
            }
            $stdout[$i] .= (string) stream_get_contents($pipes[$i][1]);
            $stderr[$i] .= (string) stream_get_contents($pipes[$i][2]);
            foreach ($pipes[$i] as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            $output = str_starts_with($stdout[$i], self::READY_LINE) ? substr($stdout[$i], strlen(self::READY_LINE)) : $stdout[$i];
            $results[] = new ProcessResult($i, $exitCodes[$i] ?? -1, $output, $stderr[$i], $timedOut);
        }

        return $results;
    }

    /**
     * Called by the child script before any output of its own: reports readiness and waits until the
     * runner releases every process. Output before it hides the ready line, and the group then waits
     * for the deadline.
     */
    public static function awaitStart(): void
    {
        fwrite(STDOUT, self::READY_LINE);
        fflush(STDOUT);
        fgets(STDIN);
    }

    /**
     * @param array<int, string> $stdout
     * @param array<int, int|null> $exitCodes
     */
    private static function allReadyOrExited(array $stdout, array $exitCodes): bool
    {
        foreach ($stdout as $i => $output) {
            if ($exitCodes[$i] === null && !str_starts_with($output, self::READY_LINE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, resource> $processes
     */
    private static function kill(array $processes): void
    {
        foreach ($processes as $process) {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }
}
