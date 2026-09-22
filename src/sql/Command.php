<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\sql;

use yii\db\Exception;

/**
 * `yii\db\Command` that measures `PDO::prepare()` and `PDOStatement::execute()` (spec 01 §2, ADR-0001).
 *
 * Installed through `Connection::$commandMap[<driverName>] = ['class' => Command::class, 'recorder' => …]`.
 * Each execution attempt gives one call to {@see Recorder::record()}; `Connection::open()`, the query
 * cache and fetching rows are not measured. Exceptions reach the application exactly as from
 * `yii\db\Command`.
 *
 * `prepare()` and `internalExecute()` repeat the parent's code with a timer around the two PDO calls,
 * because the parent opens the connection and prepares in one method. The retry handler and the
 * isolation level are private in the parent, so they are read from its scope rather than mirrored:
 * a handler set through any path, reflection included, is the one used.
 */
class Command extends \yii\db\Command
{
    /** Set by the component through `commandMap`; without it the command behaves like its parent. */
    public ?Recorder $recorder = null;

    /** Time of the last `PDO::prepare()`, added to the next execution attempt only. */
    private float $pendingPrepareMs = 0.0;


    public function prepare($forRead = null): void
    {
        if ($this->recorder === null || $this->pdoStatement) {
            parent::prepare($forRead);

            return;
        }

        $sql = $this->getSql();
        if ($sql === '') {
            return;
        }

        if ($this->db->getTransaction()) {
            // master is in a transaction. use the same connection.
            $forRead = false;
        }
        if ($forRead || $forRead === null && $this->db->getSchema()->isReadQuery($sql)) {
            $pdo = $this->db->getSlavePdo(true);
        } else {
            $pdo = $this->db->getMasterPdo();
        }

        $start = hrtime(true);
        try {
            try {
                $this->pdoStatement = $pdo->prepare($sql); // @phpstan-ignore method.nonObject (as in the parent)
            } finally {
                $this->pendingPrepareMs = (hrtime(true) - $start) / 1e6;
            }
            $this->bindPendingParams();
        } catch (\Exception $e) {
            $this->recordAttempt(0.0, $e);
            $message = $e->getMessage() . "\nFailed to prepare SQL: $sql";
            $errorInfo = $e instanceof \PDOException ? $e->errorInfo : null;
            throw new Exception($message, $errorInfo, $e->getCode(), $e); // @phpstan-ignore argument.type (as in the parent)
        } catch (\Throwable $e) {
            $this->recordAttempt(0.0, $e);
            $message = $e->getMessage() . "\nFailed to prepare SQL: $sql";
            throw new Exception($message, null, $e->getCode(), $e); // @phpstan-ignore argument.type, argument.type (as in the parent)
        }
    }

    protected function internalExecute($rawSql): void
    {
        if ($this->recorder === null) {
            parent::internalExecute($rawSql);

            return;
        }

        [$isolationLevel, $retryHandler] = $this->retryState();
        $attempt = 0;
        while (true) {
            try {
                if (
                    ++$attempt === 1
                    && $isolationLevel !== false
                    && $this->db->getTransaction() === null
                ) {
                    $this->db->transaction(function () use ($rawSql) {
                        $this->internalExecute($rawSql);
                    }, $isolationLevel);
                } else {
                    $this->measuredExecute();
                }
                break;
            } catch (\Exception $e) {
                $rawSql = $rawSql ?: $this->getRawSql();
                $e = $this->db->getSchema()->convertException($e, $rawSql);
                if ($retryHandler === null || !call_user_func($retryHandler, $e, $attempt)) {
                    throw $e;
                }
            }
        }
    }

    protected function reset(): void
    {
        $this->pendingPrepareMs = 0.0;
        parent::reset();
    }

    /**
     * Whether the installed `yii\db\Command` still has the private fields {@see self::retryState()} reads.
     * The component checks it once and stays off otherwise, so a Yii update cannot break every query.
     */
    public static function supportsInstalledYii(): bool
    {
        return property_exists(\yii\db\Command::class, '_isolationLevel')
            && property_exists(\yii\db\Command::class, '_retryHandler');
    }

    /**
     * The parent's private `_isolationLevel` and `_retryHandler`.
     *
     * @return array{string|false|null, callable|null}
     */
    private function retryState(): array
    {
        /** @var array{string|false|null, callable|null} */
        return \Closure::bind(fn(): array => [$this->_isolationLevel, $this->_retryHandler], $this, \yii\db\Command::class)();
    }

    private function measuredExecute(): void
    {
        $start = hrtime(true);
        try {
            $this->pdoStatement->execute(); // @phpstan-ignore method.nonObject (prepared before)
        } catch (\Throwable $e) {
            $this->recordAttempt((hrtime(true) - $start) / 1e6, $e);

            throw $e;
        }
        $this->recordAttempt((hrtime(true) - $start) / 1e6, null);
    }

    /**
     * One entry per attempt; the prepare time goes to the first attempt after it.
     */
    private function recordAttempt(float $executeMs, ?\Throwable $error): void
    {
        $timeMs = $executeMs + $this->pendingPrepareMs;
        $this->pendingPrepareMs = 0.0;
        $this->recorder?->record($this->getSql(), $timeMs, $error === null ? null : self::sqlState($error));
    }

    private static function sqlState(\Throwable $error): string
    {
        $errorInfo = $error instanceof \PDOException || $error instanceof Exception ? $error->errorInfo : null;
        if (is_array($errorInfo) && isset($errorInfo[0]) && is_string($errorInfo[0]) && $errorInfo[0] !== '') {
            return $errorInfo[0];
        }

        return (string) $error->getCode();
    }
}
