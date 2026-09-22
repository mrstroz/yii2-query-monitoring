<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

/**
 * Statement of {@see TestPdo} that sleeps for `QM_SLOW_FETCH_MS` before fetching rows.
 */
class TestPdoStatement extends \PDOStatement
{
    protected function __construct() {}

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        TestPdo::sleepFor('QM_SLOW_FETCH_MS');

        return parent::fetch($mode, $cursorOrientation, $cursorOffset);
    }

    /**
     * @return array<mixed>
     */
    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        TestPdo::sleepFor('QM_SLOW_FETCH_MS');

        return parent::fetchAll($mode, ...$args);
    }
}
