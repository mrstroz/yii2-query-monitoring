<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\app;

use yii\db\Connection;

/**
 * Creates the tables of the test application. Idempotent; run from the test process on a plain connection.
 */
final class Schema
{
    public static function connection(string $db): Connection
    {
        $prefix = 'QM_' . strtoupper($db) . '_';

        return new Connection([
            'dsn' => (string) getenv($prefix . 'DSN'),
            'username' => (string) getenv($prefix . 'USER'),
            'password' => (string) getenv($prefix . 'PASSWORD'),
        ]);
    }

    public static function create(Connection $connection): void
    {
        $id = $connection->getDriverName() === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $connection->createCommand(
            "CREATE TABLE IF NOT EXISTS qm_order (id {$id}, customer VARCHAR(100) NOT NULL, total DECIMAL(10,2) NOT NULL)",
        )->execute();
        $blob = $connection->getDriverName() === 'pgsql' ? 'BYTEA' : 'LONGBLOB';
        $connection->createCommand(
            "CREATE TABLE IF NOT EXISTS qm_cache (id CHAR(128) NOT NULL PRIMARY KEY, expire INT, data {$blob})",
        )->execute();
    }
}
