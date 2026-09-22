<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\adapter;

use mrstroz\querymonitoring\batch\QueryBatch;

/**
 * Receives one finished batch. Called once per batch, never per query.
 *
 * An exception thrown here loses the batch; the package logs it once per process and does not retry.
 * The call may happen during PHP shutdown, when some Yii components are already closed.
 */
interface BatchAdapterInterface
{
    public function send(QueryBatch $batch): void;
}
