<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\DataProvider;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;

/**
 * GET_LOCK does not exist in SQLite. The MySQL/MariaDB path is exercised by pointing a connection whose driver
 * is mysql/mariadb at an SQLite PDO that defines GET_LOCK/RELEASE_LOCK as user functions, so the exact SQL and
 * the result handling run. The SQLite path is an explicit connection-scoped lock (the test database), not an
 * always-true stub, and any other driver is rejected.
 */
final class SyncLockTest extends DbTestCase
{
    /** @var list<array{string,list<mixed>}> */
    private array $calls = [];

    public function testSqliteLockIsExclusiveUntilReleased(): void
    {
        $a = new SyncLock();
        $b = new SyncLock();
        self::assertTrue($a->acquire());
        self::assertFalse($b->acquire(), 'a second run in the same database must not get the lock');
        self::assertTrue($b->acquire('other_lock'), 'names are independent');
        $a->release();
        self::assertTrue($b->acquire());
        $b->release();
        $b->release('other_lock');
    }

    public function testSqliteLockIsScopedToTheConnection(): void
    {
        self::assertTrue((new SyncLock())->acquire());
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        self::assertTrue((new SyncLock())->acquire(), 'a fresh database has no lock held');
    }

    public function testSqliteIsFreeReportsAHeldLockWithoutTakingIt(): void
    {
        $lock = new SyncLock();
        self::assertTrue($lock->isFree());
        self::assertTrue($lock->isFree(), 'checking does not acquire');
        self::assertTrue($lock->acquire());
        self::assertFalse((new SyncLock())->isFree());
        self::assertTrue((new SyncLock())->isFree('other_lock'));
        $lock->release();
        self::assertTrue((new SyncLock())->isFree());
    }

    #[DataProvider('mysqlDrivers')]
    public function testMysqlIsFreeUsesIsFreeLock(string $driver): void
    {
        $this->fakeMysql($driver, 1, 1);
        self::assertTrue((new SyncLock())->isFree());
        $this->fakeMysql($driver, 1, 0);
        self::assertFalse((new SyncLock())->isFree(), 'held by another session');
        $this->fakeMysql($driver, 1, null);
        self::assertFalse((new SyncLock())->isFree(), 'IS_FREE_LOCK returned NULL (error)');
        self::assertSame([['IS_FREE_LOCK', ['uptimeify_sync']], ['IS_FREE_LOCK', ['uptimeify_sync']], ['IS_FREE_LOCK', ['uptimeify_sync']]], $this->calls);
    }

    public function testReleaseWithoutAcquireIsHarmless(): void
    {
        $lock = new SyncLock();
        $lock->release();
        self::assertTrue($lock->acquire());
    }

    #[DataProvider('mysqlDrivers')]
    public function testMysqlPathUsesGetLockWithZeroTimeout(string $driver): void
    {
        $this->fakeMysql($driver, 1);
        $lock = new SyncLock();
        self::assertTrue($lock->acquire());
        $lock->release();
        self::assertSame([['GET_LOCK', ['uptimeify_sync', 0]], ['RELEASE_LOCK', ['uptimeify_sync']]], $this->calls);
    }

    #[DataProvider('mysqlDrivers')]
    public function testMysqlPathReportsBusyAndErrorAsNotAcquired(string $driver): void
    {
        $this->fakeMysql($driver, 0);
        self::assertFalse((new SyncLock())->acquire(), 'held by another session');
        $this->fakeMysql($driver, null);
        self::assertFalse((new SyncLock())->acquire(), 'GET_LOCK returned NULL (error)');
    }

    public function testUnsupportedDriverIsRejected(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'x', 'username' => 'x', 'password' => '', 'prefix' => '']);
        $capsule->setAsGlobal();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pgsql');
        (new SyncLock())->acquire();
    }

    /** @return array<string,array{string}> */
    public static function mysqlDrivers(): array
    {
        return ['mysql' => ['mysql'], 'mariadb' => ['mariadb']];
    }

    private function fakeMysql(string $driver, ?int $getLockResult, ?int $isFreeResult = 1): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => $driver, 'host' => '127.0.0.1', 'database' => 'whmcs', 'username' => 'whmcs', 'password' => '', 'prefix' => '']);
        $capsule->setAsGlobal();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('GET_LOCK', function (string $name, int $timeout) use ($getLockResult): ?int {
            $this->calls[] = ['GET_LOCK', [$name, $timeout]];
            return $getLockResult;
        }, 2);
        $pdo->sqliteCreateFunction('RELEASE_LOCK', function (string $name): int {
            $this->calls[] = ['RELEASE_LOCK', [$name]];
            return 1;
        }, 1);
        $pdo->sqliteCreateFunction('IS_FREE_LOCK', function (string $name) use ($isFreeResult): ?int {
            $this->calls[] = ['IS_FREE_LOCK', [$name]];
            return $isFreeResult;
        }, 1);
        Capsule::connection()->setPdo($pdo);
        self::assertSame($driver, Capsule::connection()->getDriverName());
    }
}
