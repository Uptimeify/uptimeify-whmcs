<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Store;

use Illuminate\Database\Connection;
use WHMCS\Database\Capsule;

/**
 * One named lock shared by the full reconcile and the queue worker, so they never run at the same time
 * (spec §8.3.1, §8.4).
 *
 * - mysql / mariadb (every WHMCS installation): `GET_LOCK(name, 0)` / `RELEASE_LOCK(name)`. The lock belongs to
 *   the database session, so MySQL frees it when a crashed PHP process drops its connection.
 * - sqlite (unit tests only; WHMCS never runs on SQLite): an exclusive in-process lock scoped to the PDO
 *   connection, i.e. to the test database. It is not a stub that always succeeds: a second acquire fails until
 *   release, so tests can model "another run holds the lock".
 * - any other driver: RuntimeException, so production never silently runs without a lock.
 */
final class SyncLock
{
    public const NAME = 'uptimeify_sync';

    /** @var \WeakMap<\PDO,array<string,true>>|null */
    private static ?\WeakMap $local = null;

    public function acquire(string $name = self::NAME): bool
    {
        $connection = Capsule::connection();
        if ($this->isMysql($connection->getDriverName())) {
            $rows = $connection->select('SELECT GET_LOCK(?, 0) AS l', [$name]);
            $result = isset($rows[0]) ? ((array) $rows[0])['l'] ?? null : null;
            return $result !== null && (int) $result === 1;
        }
        $pdo = $this->localPdo($connection);
        $held = self::local()[$pdo] ?? [];
        if (isset($held[$name])) {
            return false;
        }
        $held[$name] = true;
        self::local()[$pdo] = $held;
        return true;
    }

    /**
     * Whether nobody holds the lock, without taking it (`IS_FREE_LOCK`; NULL on error counts as held). The client area
     * uses it to leave a running sync alone instead of taking the lock and making the next cron run skip.
     */
    public function isFree(string $name = self::NAME): bool
    {
        $connection = Capsule::connection();
        if ($this->isMysql($connection->getDriverName())) {
            $rows = $connection->select('SELECT IS_FREE_LOCK(?) AS l', [$name]);
            $result = isset($rows[0]) ? ((array) $rows[0])['l'] ?? null : null;
            return $result !== null && (int) $result === 1;
        }
        return !isset(self::local()[$this->localPdo($connection)][$name]);
    }

    public function release(string $name = self::NAME): void
    {
        $connection = Capsule::connection();
        if ($this->isMysql($connection->getDriverName())) {
            $connection->select('SELECT RELEASE_LOCK(?)', [$name]);
            return;
        }
        $pdo = $this->localPdo($connection);
        $held = self::local()[$pdo] ?? [];
        unset($held[$name]);
        self::local()[$pdo] = $held;
    }

    private function isMysql(string $driver): bool
    {
        return $driver === 'mysql' || $driver === 'mariadb';
    }

    private function localPdo(Connection $connection): \PDO
    {
        $driver = $connection->getDriverName();
        if ($driver !== 'sqlite') {
            throw new \RuntimeException(sprintf('SyncLock: unsupported database driver "%s" (WHMCS requires MySQL or MariaDB)', $driver));
        }
        return $connection->getPdo();
    }

    /** @return \WeakMap<\PDO,array<string,true>> */
    private static function local(): \WeakMap
    {
        return self::$local ??= new \WeakMap();
    }
}
