<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Store;

use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Sync\MonitorPlanner;

final class MonitorMapTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
    }

    public function testUpsertFindStateAndLookups(): void
    {
        $m = new MonitorMap();
        $m->upsert(['kind' => MonitorMap::KIND_HOSTING, 'whmcs_ref_id' => 7, 'monitor_type' => MonitorMap::TYPE_WEBSITE,
            'monitor_public_id' => 'w1', 'monitor_numeric_id' => 42, 'customer_public_id' => 'c1', 'target' => 'example.com', 'state' => MonitorMap::STATE_ACTIVE]);
        $m->upsert(['kind' => MonitorMap::KIND_HOSTING, 'whmcs_ref_id' => 7, 'monitor_type' => MonitorMap::TYPE_CUSTOMER_DOMAIN,
            'monitor_public_id' => 'd1', 'monitor_numeric_id' => null, 'customer_public_id' => 'c1', 'target' => 'example.com', 'state' => MonitorMap::STATE_ACTIVE]);
        self::assertCount(2, $m->forKindRef(MonitorMap::KIND_HOSTING, 7));
        self::assertSame('w1', $m->find(MonitorMap::KIND_HOSTING, 7, MonitorMap::TYPE_WEBSITE)['monitor_public_id']);
        $m->setState(MonitorMap::KIND_HOSTING, 7, MonitorMap::TYPE_WEBSITE, MonitorMap::STATE_ERROR, 'activeMonitorLimitReached');
        self::assertSame('activeMonitorLimitReached', $m->find(MonitorMap::KIND_HOSTING, 7, MonitorMap::TYPE_WEBSITE)['last_error']);
        self::assertSame(['active' => 1, 'error' => 1], $m->countByState());
        self::assertSame(7, $m->byMonitorPublicId('d1')['whmcs_ref_id']);
        self::assertCount(2, $m->forCustomer('c1'));
        $m->remove(MonitorMap::KIND_HOSTING, 7, MonitorMap::TYPE_WEBSITE);
        self::assertNull($m->find(MonitorMap::KIND_HOSTING, 7, MonitorMap::TYPE_WEBSITE));
    }

    public function testUpsertOverwritesSameKeyAndCastsTypes(): void
    {
        $m = new MonitorMap();
        $key = ['kind' => MonitorMap::KIND_DOMAIN, 'whmcs_ref_id' => 3, 'monitor_type' => MonitorMap::TYPE_DNS];
        $m->upsert($key + ['monitor_public_id' => null, 'monitor_numeric_id' => null, 'customer_public_id' => 'c2', 'target' => 'example.org', 'state' => MonitorMap::STATE_ERROR, 'last_error' => 'boom']);
        $m->upsert($key + ['monitor_public_id' => 'dns1', 'monitor_numeric_id' => '15', 'customer_public_id' => 'c2', 'target' => 'example.org', 'state' => MonitorMap::STATE_ACTIVE]);
        self::assertCount(1, $m->all());
        $row = $m->find(MonitorMap::KIND_DOMAIN, 3, MonitorMap::TYPE_DNS);
        self::assertNotNull($row);
        self::assertSame(3, $row['whmcs_ref_id']);
        self::assertSame(15, $row['monitor_numeric_id']);
        self::assertSame('dns1', $row['monitor_public_id']);
        self::assertNull($row['last_error'], 'upsert without last_error clears the old error');
        self::assertSame([], $m->forKindRef(MonitorMap::KIND_HOSTING, 3), 'kind is part of the key');
        self::assertNull($m->byMonitorPublicId('unknown'));
    }

    public function testOriginAndAdminPauseDefaultToAdoptedAndFalseAndSurviveAnUpsertWithoutThem(): void
    {
        $m = new MonitorMap();
        $key = ['kind' => MonitorMap::KIND_HOSTING, 'whmcs_ref_id' => 1, 'monitor_type' => MonitorMap::TYPE_WEBSITE];
        $m->upsert($key + ['monitor_public_id' => 'w1', 'target' => 'a.example', 'state' => MonitorMap::STATE_ACTIVE]);
        $row = $m->find(MonitorMap::KIND_HOSTING, 1, MonitorMap::TYPE_WEBSITE);
        self::assertSame([MonitorMap::ORIGIN_ADOPTED, false], [$row['origin'] ?? null, $row['admin_paused'] ?? null], 'a row without the information is adopted');

        $m->upsert($key + ['monitor_public_id' => 'w1', 'target' => 'a.example', 'state' => MonitorMap::STATE_ACTIVE, 'origin' => MonitorMap::ORIGIN_CREATED, 'admin_paused' => true]);
        $m->upsert($key + ['monitor_public_id' => 'w1', 'target' => 'b.example', 'state' => MonitorMap::STATE_PAUSED]);
        $row = $m->find(MonitorMap::KIND_HOSTING, 1, MonitorMap::TYPE_WEBSITE);
        self::assertSame(['b.example', MonitorMap::ORIGIN_CREATED, true], [$row['target'] ?? null, $row['origin'] ?? null, $row['admin_paused'] ?? null]);
    }

    public function testSetAdminPausedTouchesEveryRowOfThatTypeSharingTheMonitor(): void
    {
        $m = new MonitorMap();
        $m->upsert(['kind' => MonitorMap::KIND_HOSTING, 'whmcs_ref_id' => 1, 'monitor_type' => MonitorMap::TYPE_WEBSITE, 'monitor_public_id' => 'x1', 'target' => 'a.example', 'state' => MonitorMap::STATE_PAUSED]);
        $m->upsert(['kind' => MonitorMap::KIND_HOSTING, 'whmcs_ref_id' => 2, 'monitor_type' => MonitorMap::TYPE_WEBSITE, 'monitor_public_id' => 'x1', 'target' => 'a.example', 'state' => MonitorMap::STATE_PAUSED]);
        $m->upsert(['kind' => MonitorMap::KIND_HOSTING, 'whmcs_ref_id' => 2, 'monitor_type' => MonitorMap::TYPE_CUSTOMER_DOMAIN, 'monitor_public_id' => 'x1', 'target' => 'a.example', 'state' => MonitorMap::STATE_ACTIVE]);

        $m->setAdminPaused(MonitorMap::TYPE_WEBSITE, 'x1', true);

        self::assertSame([true, true, false], array_map(fn (array $r): bool => $r['admin_paused'], [
            $m->find('hosting', 1, 'website') ?? [], $m->find('hosting', 2, 'website') ?? [], $m->find('hosting', 2, 'customer_domain') ?? [],
        ]));
        $m->setAdminPaused(MonitorMap::TYPE_WEBSITE, 'x1', false);
        self::assertFalse(($m->find('hosting', 1, 'website') ?? [])['admin_paused']);
    }

    public function testForMonitorPublicIdReturnsEveryRowSharingTheMonitor(): void
    {
        $m = new MonitorMap();
        $m->upsert(['kind' => MonitorMap::KIND_HOSTING, 'whmcs_ref_id' => 1, 'monitor_type' => MonitorMap::TYPE_CUSTOMER_DOMAIN, 'monitor_public_id' => 'd1', 'target' => 'a.example', 'state' => MonitorMap::STATE_ACTIVE]);
        $m->upsert(['kind' => MonitorMap::KIND_DOMAIN, 'whmcs_ref_id' => 4, 'monitor_type' => MonitorMap::TYPE_CUSTOMER_DOMAIN, 'monitor_public_id' => 'd1', 'target' => 'a.example', 'state' => MonitorMap::STATE_PAUSED]);
        $m->upsert(['kind' => MonitorMap::KIND_HOSTING, 'whmcs_ref_id' => 2, 'monitor_type' => MonitorMap::TYPE_WEBSITE, 'monitor_public_id' => 'w1', 'target' => 'a.example', 'state' => MonitorMap::STATE_ACTIVE]);
        $rows = $m->forMonitorPublicId('d1');
        self::assertSame([['domain', 4], ['hosting', 1]], array_map(fn (array $r): array => [$r['kind'], $r['whmcs_ref_id']], $rows));
        self::assertSame([], $m->forMonitorPublicId('nope'));
    }

    public function testServerIpCompositeRefIdRoundTrips(): void
    {
        // Server-IP rows use refId = serverId * 10_000_000_000 + clientId (MonitorPlanner::serverIpRefId); that
        // exceeds a signed 32-bit INT for every server, so the column is BIGINT.
        $refId = MonitorPlanner::serverIpRefId(30000, 9_999_999_999);
        $m = new MonitorMap();
        $m->upsert(['kind' => MonitorMap::KIND_SERVER_IP, 'whmcs_ref_id' => $refId, 'monitor_type' => MonitorMap::TYPE_CUSTOMER_IP,
            'monitor_public_id' => 'ip1', 'customer_public_id' => 'c3', 'target' => '192.0.2.10', 'state' => MonitorMap::STATE_PAUSED]);
        self::assertSame($refId, $m->forKindRef(MonitorMap::KIND_SERVER_IP, $refId)[0]['whmcs_ref_id']);
        self::assertSame(['paused' => 1], $m->countByState());
    }

    public function testErrorCountsGroupErrorRowsByLastError(): void
    {
        $m = new MonitorMap();
        foreach ([[1, 'error', 'invalidPackageType'], [2, 'error', 'invalidPackageType'], [3, 'error', 'invalidUrl'], [4, 'orphaned', 'remoteMonitorNotFound'], [5, 'active', null]] as [$ref, $state, $error]) {
            $m->upsert(['kind' => MonitorMap::KIND_HOSTING, 'whmcs_ref_id' => $ref, 'monitor_type' => MonitorMap::TYPE_WEBSITE, 'target' => 'h' . $ref . '.example', 'state' => $state, 'last_error' => $error]);
        }
        self::assertSame(['invalidPackageType' => 2, 'invalidUrl' => 1], $m->errorCounts());
    }
}
