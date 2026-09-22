<?php

declare(strict_types=1);

/*
 * Child of FileAdapterConcurrencyTest, run by ProcessGroup. After the barrier writes QM_BATCHES batches
 * through FileAdapter::write() to QM_PATH (QM_MAX_SIZE, QM_MAX_FILES), with a pause of QM_PAUSE_US
 * microseconds after each, and prints the batch ids by the result of write() as JSON {"true", "false"}.
 * An exception from the adapter ends the process with an error, which the test reports.
 */

use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\tests\app\ProcessGroup;

$worker = (int) getenv('QM_WORKER');
$adapter = new FileAdapter((string) getenv('QM_PATH'), (int) getenv('QM_MAX_SIZE'), (int) getenv('QM_MAX_FILES'));
$entries = [QueryEntry::success('mysql', 'db', 'SELECT', 'SELECT * FROM qm_order WHERE id = ?', 0.25)];
$ids = ['true' => [], 'false' => []];

ProcessGroup::awaitStart();
for ($n = 0; $n < (int) getenv('QM_BATCHES'); $n++) {
    $id = "w{$worker}-{$n}";
    $batch = new QueryBatch('app', BatchType::Http, $id, 1, null, 'site', 'index', new \DateTimeImmutable(), 'host', 0, $entries);
    $ids[$adapter->write($batch) ? 'true' : 'false'][] = $id;
    usleep((int) getenv('QM_PAUSE_US'));
}

echo json_encode($ids);
