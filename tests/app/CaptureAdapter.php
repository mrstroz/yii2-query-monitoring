<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use mrstroz\querymonitoring\adapter\BatchAdapterInterface;
use mrstroz\querymonitoring\batch\QueryBatch;

/**
 * Appends each batch as one JSON line to `QM_CAPTURE_FILE`, for {@see AppRunner} to read back.
 */
final class CaptureAdapter implements BatchAdapterInterface
{
    public function send(QueryBatch $batch): void
    {
        file_put_contents((string) getenv('QM_CAPTURE_FILE'), $batch->toJson() . "\n", FILE_APPEND | LOCK_EX);
    }
}
