<?php

declare(strict_types=1);

/*
 * Child of ProcessGroupTest, run by ProcessGroup. What it does depends on QM_PROBE:
 * - `interval`: after the barrier, prints JSON {"start", "end"} of 300 ms of work;
 * - `sleep`: after the barrier, sleeps QM_SLEEP seconds, then creates QM_MARKER.<worker>;
 * - `not-ready` for the worker in QM_NOT_READY: sleeps QM_SLEEP seconds without reporting ready, the
 *   others wait at the barrier and print `passed` after it;
 * - `exit`: after the barrier, prints `out <worker>`, writes `err <worker>` to stderr and exits with 3;
 * - `crash` for the worker in QM_CRASH: exits with 5 before reporting ready, the others print `passed`.
 */

use mrstroz\querymonitoring\tests\app\ProcessGroup;

$worker = (int) getenv('QM_WORKER');

switch (getenv('QM_PROBE')) {
    case 'interval':
        ProcessGroup::awaitStart();
        $start = microtime(true);
        usleep(300_000);
        echo json_encode(['start' => $start, 'end' => microtime(true)]);
        break;
    case 'sleep':
        ProcessGroup::awaitStart();
        usleep((int) ((float) getenv('QM_SLEEP') * 1_000_000));
        touch(getenv('QM_MARKER') . '.' . $worker);
        break;
    case 'not-ready':
        if ($worker === (int) getenv('QM_NOT_READY')) {
            usleep((int) ((float) getenv('QM_SLEEP') * 1_000_000));
            break;
        }
        ProcessGroup::awaitStart();
        echo 'passed';
        break;
    case 'crash':
        if ($worker === (int) getenv('QM_CRASH')) {
            fwrite(STDERR, 'crashed');
            exit(5);
        }
        ProcessGroup::awaitStart();
        echo 'passed';
        break;
    case 'exit':
        ProcessGroup::awaitStart();
        echo "out {$worker}";
        fwrite(STDERR, "err {$worker}");
        exit(3);
}
