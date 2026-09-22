<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\sql;

use mrstroz\querymonitoring\batch\QueryEntry;
use mrstroz\querymonitoring\collector\QueryCollector;
use mrstroz\querymonitoring\support\Guard;

/**
 * Turns one measured SQL call into an entry for one monitored connection.
 *
 * One recorder per monitored connection. It knows the connection id (`conn`) and the driver
 * name (`db`), derives `op` and `query` with the normaliser and adds the entry to the collector,
 * all inside the guard, so a failure here never reaches the application.
 */
final class Recorder
{
    public function __construct(
        private readonly string $connectionId,
        private readonly string $driverName,
        private readonly SqlNormalizer $normalizer,
        private readonly QueryCollector $collector,
        private readonly Guard $guard,
    ) {}

    /**
     * @param string $sql SQL text as sent to PDO, with parameter placeholders, never with bound values
     * @param float $timeMs time of `PDO::prepare()` and `PDOStatement::execute()` for this attempt
     * @param string|null $sqlState SQLSTATE of the driver exception, null on success
     */
    public function record(string $sql, float $timeMs, ?string $sqlState): void
    {
        $this->guard->run(function () use ($sql, $timeMs, $sqlState): void {
            // After finalisation and while the adapter sends, a query is not an entry: skip the normaliser too.
            if (!$this->collector->isAccepting()) {
                return;
            }
            $op = $this->normalizer->operation($sql, $this->driverName);
            $query = $this->normalizer->normalize($sql, $this->driverName);
            $timeMs = round($timeMs, 3);
            $this->collector->add($sqlState === null
                ? QueryEntry::success($this->driverName, $this->connectionId, $op, $query, $timeMs)
                : QueryEntry::error($this->driverName, $this->connectionId, $op, $query, $timeMs, $sqlState));
        }, 'record');
    }
}
