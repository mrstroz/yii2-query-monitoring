<?php

declare(strict_types=1);

/*
 * One user's writes for tests/multiuser/check.sh: `php write.php <path> <count> <maxSize> <maxFiles> <tag>`.
 * Sends <count> one-entry batches through FileAdapter with fileMode 0664 and dirMode 0775, under umask 022 as a
 * web server or a shell usually has. Exit 0 when every send succeeded, 3 with the adapter's message on stderr
 * at the first failure.
 */

use mrstroz\querymonitoring\adapter\FileAdapter;
use mrstroz\querymonitoring\adapter\FileAdapterException;
use mrstroz\querymonitoring\batch\BatchType;
use mrstroz\querymonitoring\batch\QueryBatch;
use mrstroz\querymonitoring\batch\QueryEntry;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $path, $count, $maxSize, $maxFiles, $tag] = $argv;
umask(0o022);
$adapter = new FileAdapter($path, (int) $maxSize, (int) $maxFiles, fileMode: 0o664, dirMode: 0o775);
for ($i = 1; $i <= (int) $count; $i++) {
    $entry = QueryEntry::success('mysql', 'db', 'SELECT', "SELECT ? AS qm_{$tag}_{$i}", 1.0, []);
    $batch = new QueryBatch(app: 'multiuser', type: BatchType::Console, id: $tag, seq: $i, route: 'multi/write', ts: new DateTimeImmutable(), host: 'h', dropped: 0, queries: [$entry]);
    try {
        $adapter->send($batch);
    } catch (FileAdapterException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(3);
    }
}
