<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\adapter;

use mrstroz\querymonitoring\batch\QueryBatch;

/**
 * Adapts a `callable(QueryBatch): mixed` from the `adapter` setting to {@see BatchAdapterInterface} (spec 03 §1).
 */
final class CallableAdapter implements BatchAdapterInterface
{
    /** @var callable(QueryBatch): mixed */
    private $callback;

    /**
     * @param callable(QueryBatch): mixed $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function send(QueryBatch $batch): void
    {
        ($this->callback)($batch);
    }
}
