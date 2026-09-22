<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\batch;

/**
 * One command actually sent to a database (spec 02 §2).
 *
 * The entry stores values as given by the source. It does not validate `db`, round `timeMs`
 * or normalise `query`; the source does that before creating the entry.
 */
final class QueryEntry
{
    public const RESULT_SUCCESS = 'success';
    public const RESULT_ERROR = 'error';

    private function __construct(
        public readonly string $db,
        public readonly string $conn,
        public readonly string $op,
        public readonly ?string $query,
        public readonly float $timeMs,
        public readonly string $result,
        public readonly ?string $error,
    ) {}

    public static function success(string $db, string $conn, string $op, ?string $query, float $timeMs): self
    {
        return new self($db, $conn, $op, $query, $timeMs, self::RESULT_SUCCESS, null);
    }

    /**
     * @param string $error SQLSTATE for SQL, the driver's numeric code as a string for MongoDB
     */
    public static function error(string $db, string $conn, string $op, ?string $query, float $timeMs, string $error): self
    {
        return new self($db, $conn, $op, $query, $timeMs, self::RESULT_ERROR, $error);
    }

    /**
     * Fields in spec 02 §2 order; `error` only when the result is an error.
     *
     * @return array{db: string, conn: string, op: string, query: ?string, time_ms: float, result: string, error?: string}
     */
    public function toArray(): array
    {
        $data = [
            'db' => $this->db,
            'conn' => $this->conn,
            'op' => $this->op,
            'query' => $this->query,
            'time_ms' => $this->timeMs,
            'result' => $this->result,
        ];
        if ($this->error !== null) {
            $data['error'] = $this->error;
        }

        return $data;
    }
}
