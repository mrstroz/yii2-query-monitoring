<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

/**
 * PDO used by every connection of the test application, so that tests can tell what is timed.
 *
 * Switches, read from the environment of the child process:
 * - `QM_SLOW_CONNECT_MS` — sleep in the constructor (Connection::open()),
 * - `QM_SLOW_PREPARE_MS` — sleep in prepare(),
 * - `QM_SLOW_FETCH_MS` — sleep in fetch()/fetchAll() of the returned statements,
 * - `QM_FAIL_PREPARE` — SQLSTATE; prepare() throws a PDOException with it instead of preparing.
 * Without switches it behaves like PDO.
 */
class TestPdo extends \PDO
{
    /**
     * @param array<int, mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        self::sleepFor('QM_SLOW_CONNECT_MS');
        parent::__construct($dsn, $username, $password, $options);
        if (self::milliseconds('QM_SLOW_FETCH_MS') > 0) {
            $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [TestPdoStatement::class, []]);
        }
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        self::sleepFor('QM_SLOW_PREPARE_MS');
        $sqlState = getenv('QM_FAIL_PREPARE');
        if (is_string($sqlState) && $sqlState !== '') {
            $exception = new \PDOException("SQLSTATE[{$sqlState}]: forced by TestPdo");
            $exception->errorInfo = [$sqlState, 0, 'forced by TestPdo'];
            (new \ReflectionProperty(\Exception::class, 'code'))->setValue($exception, $sqlState);

            throw $exception;
        }

        return parent::prepare($query, $options);
    }

    public static function sleepFor(string $variable): void
    {
        $ms = self::milliseconds($variable);
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    private static function milliseconds(string $variable): int
    {
        return (int) getenv($variable);
    }
}
