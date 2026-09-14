<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Store;

use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Store\DetachedMonitors;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;

final class DetachedMonitorsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
    }

    public function testRememberFindForgetPerTypeAndMonitor(): void
    {
        $d = new DetachedMonitors();
        self::assertNull($d->find(MonitorMap::TYPE_DNS, 'n1'));

        $d->remember(MonitorMap::TYPE_DNS, 'n1', MonitorMap::ORIGIN_CREATED, false);
        $d->remember(MonitorMap::TYPE_WEBSITE, 'n1', MonitorMap::ORIGIN_ADOPTED, true);

        self::assertSame(['origin' => 'created', 'admin_paused' => false], $d->find(MonitorMap::TYPE_DNS, 'n1'));
        self::assertSame(['origin' => 'adopted', 'admin_paused' => true], $d->find(MonitorMap::TYPE_WEBSITE, 'n1'));

        $d->forget(MonitorMap::TYPE_DNS, 'n1');
        $d->forget(MonitorMap::TYPE_DNS, 'unknown');
        self::assertNull($d->find(MonitorMap::TYPE_DNS, 'n1'));
        self::assertNotNull($d->find(MonitorMap::TYPE_WEBSITE, 'n1'));
    }

    public function testRememberNeverDowngradesCreatedOrAnAdminPause(): void
    {
        $d = new DetachedMonitors();
        $d->remember(MonitorMap::TYPE_CUSTOMER_DOMAIN, 'd1', MonitorMap::ORIGIN_CREATED, true);
        $d->remember(MonitorMap::TYPE_CUSTOMER_DOMAIN, 'd1', MonitorMap::ORIGIN_ADOPTED, false);

        self::assertSame(['origin' => 'created', 'admin_paused' => true], $d->find(MonitorMap::TYPE_CUSTOMER_DOMAIN, 'd1'), 'a second row detached from the same monitor keeps what the first knew');
    }

    public function testOneSelectPerLoadAndNoDeleteForAMonitorWithoutRecord(): void
    {
        (new DetachedMonitors())->remember(MonitorMap::TYPE_WEBSITE, 'w1', MonitorMap::ORIGIN_CREATED, false);
        $d = new DetachedMonitors();
        $connection = \WHMCS\Database\Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        foreach (['w2', 'w3', 'w4'] as $publicId) {
            self::assertNull($d->find(MonitorMap::TYPE_WEBSITE, $publicId));
            $d->forget(MonitorMap::TYPE_WEBSITE, $publicId);
        }
        self::assertNotNull($d->find(MonitorMap::TYPE_WEBSITE, 'w1'));
        $queries = array_column($connection->getQueryLog(), 'query');
        $connection->disableQueryLog();
        self::assertCount(1, $queries, 'the records are loaded once, forgetting a monitor without record touches nothing: ' . implode(' | ', $queries));
        self::assertStringStartsWith('select', strtolower($queries[0]));
    }

    public function testReloadSeesRecordsOfAnotherInstanceAndForgetDeletesAKnownRecord(): void
    {
        $d = new DetachedMonitors();
        self::assertNull($d->find(MonitorMap::TYPE_WEBSITE, 'w1'));
        (new DetachedMonitors())->remember(MonitorMap::TYPE_WEBSITE, 'w1', MonitorMap::ORIGIN_ADOPTED, true);
        $d->reload();
        self::assertSame(['origin' => 'adopted', 'admin_paused' => true], $d->find(MonitorMap::TYPE_WEBSITE, 'w1'));
        $d->forget(MonitorMap::TYPE_WEBSITE, 'w1');
        self::assertNull((new DetachedMonitors())->find(MonitorMap::TYPE_WEBSITE, 'w1'));
    }

    public function testClearAdminPauseKeepsTheOrigin(): void
    {
        $d = new DetachedMonitors();
        $d->remember(MonitorMap::TYPE_CUSTOMER_IP, 'ip1', MonitorMap::ORIGIN_CREATED, true);
        $d->clearAdminPause(MonitorMap::TYPE_CUSTOMER_IP, 'ip1');
        $d->clearAdminPause(MonitorMap::TYPE_CUSTOMER_IP, 'unknown');
        self::assertSame(['origin' => 'created', 'admin_paused' => false], (new DetachedMonitors())->find(MonitorMap::TYPE_CUSTOMER_IP, 'ip1'));
        self::assertNull((new DetachedMonitors())->find(MonitorMap::TYPE_CUSTOMER_IP, 'unknown'));
    }
}
