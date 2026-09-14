<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Sync\RemoteIndex;
use WHMCS\Module\Addon\Uptimeify\Sync\SnapshotBuilder;

final class SnapshotBuilderTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
    }

    public function testMonitorStatusRules(): void
    {
        self::assertSame('paused', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_WEBSITE, ['status' => 'inactive', 'isDown' => true]));
        self::assertSame('paused', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_WEBSITE, ['status' => 'disabled']));
        self::assertSame('down', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_WEBSITE, ['status' => 'active', 'isDown' => true]));
        self::assertSame('up', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_WEBSITE, ['status' => 'active', 'isDown' => false]));
        self::assertSame('up', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_WEBSITE, ['status' => 'maintenance']));
        self::assertSame('down', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_CUSTOMER_DOMAIN, ['status' => 'active', 'expiry' => ['isExpired' => true]]));
        self::assertSame('up', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_CUSTOMER_DOMAIN, ['status' => 'active', 'expiry' => ['isExpired' => false]]));
        self::assertSame('up', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_CUSTOMER_DOMAIN, ['status' => 'active', 'expiry' => null]));
        self::assertSame('paused', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_CUSTOMER_DOMAIN, ['status' => 'disabled']));
        self::assertSame('down', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_CUSTOMER_IP, ['status' => 'active', 'dnsbl' => ['isListed' => true]]));
        self::assertSame('up', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_CUSTOMER_IP, ['status' => 'active', 'dnsbl' => null]));
        self::assertSame('paused', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_CUSTOMER_IP, ['status' => 'disabled', 'dnsbl' => ['isListed' => true]]));
        self::assertSame('up', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_DNS, ['status' => 'active', 'isDown' => false]));
        self::assertSame('down', SnapshotBuilder::monitorStatus(MonitorMap::TYPE_DNS, ['status' => 'active', 'isDown' => true]));
    }

    public function testClientSnapshotShowsOnlyTheClientsOwnMonitorsAndOpenIncidents(): void
    {
        $customers = new CustomerMap();
        $customers->set(1, 'c1', null, 'manual');
        $customers->set(2, 'c2', null, 'manual');
        $customers->set(3, 'c3', null, 'manual');
        $map = new MonitorMap();
        $base = ['monitor_numeric_id' => null, 'last_error' => null];
        $map->upsert($base + ['kind' => 'hosting', 'whmcs_ref_id' => 1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'a.example', 'state' => 'active']);
        $map->upsert($base + ['kind' => 'hosting', 'whmcs_ref_id' => 2, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'a.example', 'state' => 'active']);
        $map->upsert($base + ['kind' => 'hosting', 'whmcs_ref_id' => 3, 'monitor_type' => 'website', 'monitor_public_id' => 'w2', 'customer_public_id' => 'c1', 'target' => 'b.example', 'state' => 'paused']);
        $map->upsert($base + ['kind' => 'hosting', 'whmcs_ref_id' => 4, 'monitor_type' => 'website', 'monitor_public_id' => 'w-foreign', 'customer_public_id' => 'c1', 'target' => 'x.example', 'state' => 'active']);
        $map->upsert($base + ['kind' => 'hosting', 'whmcs_ref_id' => 5, 'monitor_type' => 'website', 'monitor_public_id' => 'w-gone', 'customer_public_id' => 'c1', 'target' => 'gone.example', 'state' => 'orphaned']);
        $map->upsert($base + ['kind' => 'hosting', 'whmcs_ref_id' => 6, 'monitor_type' => 'website', 'monitor_public_id' => null, 'customer_public_id' => 'c1', 'target' => 'localhost', 'state' => 'error']);
        $map->upsert($base + ['kind' => 'domain', 'whmcs_ref_id' => 1, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'customer_public_id' => 'c1', 'target' => 'a.example', 'state' => 'active']);
        $map->upsert($base + ['kind' => 'server_ip', 'whmcs_ref_id' => 20000000002, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'customer_public_id' => 'c2', 'target' => '192.0.2.1', 'state' => 'active']);

        $index = new RemoteIndex();
        $index->addMonitor(MonitorMap::TYPE_WEBSITE, ['publicId' => 'w1', 'customerPublicId' => 'c1', 'name' => 'A site', 'url' => 'https://a.example', 'status' => 'active', 'isDown' => true, 'uptimeDay' => '99.00', 'uptimeMonth' => null, 'uptimeYear' => 'n/a', 'avgResponseDay' => 120]);
        $index->addMonitor(MonitorMap::TYPE_WEBSITE, ['publicId' => 'w2', 'customerPublicId' => 'c1', 'name' => 'b.example', 'url' => 'https://b.example', 'status' => 'inactive']);
        $index->addMonitor(MonitorMap::TYPE_WEBSITE, ['publicId' => 'w-foreign', 'customerPublicId' => 'c9', 'name' => 'secret', 'url' => 'https://x.example', 'status' => 'active']);
        $index->addMonitor(MonitorMap::TYPE_CUSTOMER_DOMAIN, ['publicId' => 'd1', 'customerPublicId' => 'c1', 'label' => 'a.example', 'domainName' => 'a.example', 'status' => 'active', 'expiry' => ['domainExpiresAt' => '2027-02-01T00:00:00.000Z', 'domainRegistrar' => 'Reg', 'isExpired' => true]]);
        $index->addMonitor(MonitorMap::TYPE_CUSTOMER_IP, ['publicId' => 'i1', 'customer' => ['publicId' => 'c2'], 'label' => 'web1', 'ipAddress' => '192.0.2.1', 'status' => 'active', 'dnsbl' => ['isListed' => false]]);
        $incidents = [
            ['id' => 1, 'publicId' => 'i-open', 'status' => 'open', 'title' => 'Down', 'started_at' => '2026-09-13T10:00:00Z', 'resolved_at' => null, 'website' => ['publicId' => 'w1', 'name' => 'A site'], 'monitor' => null, 'customer' => ['publicId' => 'c1']],
            ['id' => 2, 'publicId' => 'i-ack', 'status' => 'acknowledged', 'title' => 'Slow', 'started_at' => '2026-09-13T09:00:00Z', 'resolved_at' => null, 'website' => ['publicId' => 'w1', 'name' => 'A site'], 'monitor' => null, 'customer' => ['publicId' => 'c1']],
            ['id' => 3, 'publicId' => 'i-done', 'status' => 'resolved', 'title' => 'Old', 'started_at' => '2026-09-01T09:00:00Z', 'resolved_at' => '2026-09-01T10:00:00Z', 'website' => ['publicId' => 'w1'], 'monitor' => null, 'customer' => ['publicId' => 'c1']],
            ['id' => 4, 'publicId' => 'i-other', 'status' => 'open', 'title' => 'Other customer', 'started_at' => '2026-09-13T08:00:00Z', 'resolved_at' => null, 'website' => ['publicId' => 'w-foreign'], 'monitor' => null, 'customer' => ['publicId' => 'c9']],
            ['id' => 5, 'publicId' => 'i-dns', 'status' => 'open', 'title' => 'DNS', 'started_at' => '2026-09-13T07:00:00Z', 'resolved_at' => null, 'website' => null, 'monitor' => ['publicId' => 'n1', 'name' => 'dns'], 'customer' => ['publicId' => 'c2']],
        ];

        $snapshot = SnapshotBuilder::build($index, $incidents, $map, $customers);

        $c1 = $snapshot['clients'][1];
        self::assertSame('c1', $c1['customerPublicId']);
        self::assertSame(['d1', 'w1', 'w2'], array_column($c1['monitors'], 'publicId'), 'sorted by target and type; shared monitor once; foreign, orphaned and never-created rows are left out');
        self::assertSame(['total' => 3, 'up' => 0, 'down' => 2, 'paused' => 1], $c1['totals']);
        $w1 = $c1['monitors'][1];
        self::assertSame(['website', 'A site', 'a.example', 'down', 99.0, null, null, 120.0, null, null, 2], [
            $w1['type'], $w1['name'], $w1['target'], $w1['status'], $w1['uptimeDay'], $w1['uptimeMonth'], $w1['uptimeYear'], $w1['avgResponseDay'], $w1['expiresAt'], $w1['registrar'], $w1['openIncidents'],
        ]);
        $d1 = $c1['monitors'][0];
        self::assertSame(['a.example', 'down', '2027-02-01T00:00:00.000Z', 'Reg', 0], [$d1['name'], $d1['status'], $d1['expiresAt'], $d1['registrar'], $d1['openIncidents']]);
        self::assertSame(['i-open', 'i-ack'], array_column($c1['incidents'], 'publicId'));
        self::assertSame(['Down', '2026-09-13T10:00:00Z', 'w1', 'A site', 'c1'], [$c1['incidents'][0]['title'], $c1['incidents'][0]['startedAt'], $c1['incidents'][0]['monitorPublicId'], $c1['incidents'][0]['monitorName'], $c1['incidents'][0]['customerPublicId']]);

        self::assertSame(['i1'], array_column($snapshot['clients'][2]['monitors'], 'publicId'));
        self::assertSame(['i-dns'], array_column($snapshot['clients'][2]['incidents'], 'publicId'));
        self::assertSame(['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0], $snapshot['clients'][3]['totals'], 'a mapped client without monitors gets an empty snapshot');

        $org = $snapshot['org'];
        self::assertSame([4, 1, 2, 1, 3, true], [$org['total'], $org['up'], $org['down'], $org['paused'], $org['incidents'], $org['incidentsAvailable']], 'org counts the mirrored customers only');
        self::assertSame($org['refreshedAt'], $c1['refreshedAt']);
    }

    public function testUnknownStatusWhenMappedMonitorIsNotInTheIndexAndIncidentsUnavailableFlag(): void
    {
        $customers = new CustomerMap();
        $customers->set(1, 'c1', null, 'manual');
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'a.example', 'state' => 'active']);

        $snapshot = SnapshotBuilder::build(new RemoteIndex(), [], new MonitorMap(), $customers, false);

        self::assertSame('unknown', $snapshot['clients'][1]['monitors'][0]['status']);
        self::assertSame('a.example', $snapshot['clients'][1]['monitors'][0]['name']);
        self::assertSame(['total' => 1, 'up' => 0, 'down' => 0, 'paused' => 0], $snapshot['clients'][1]['totals']);
        self::assertFalse($snapshot['org']['incidentsAvailable']);
        self::assertFalse($snapshot['clients'][1]['incidentsAvailable']);
    }

    public function testOnlyClientIdBuildsTheSnapshotOfThatClientOnly(): void
    {
        $customers = new CustomerMap();
        $customers->set(1, 'c1', null, 'manual');
        $customers->set(2, 'c2', null, 'manual');
        $map = new MonitorMap();
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'a.example', 'state' => 'active']);
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 2, 'monitor_type' => 'website', 'monitor_public_id' => 'w2', 'customer_public_id' => 'c2', 'target' => 'b.example', 'state' => 'active']);

        $snapshot = SnapshotBuilder::build(new RemoteIndex(), [], $map, $customers, true, 2);

        self::assertSame([2], array_keys($snapshot['clients']));
        self::assertSame('c2', $snapshot['clients'][2]['customerPublicId']);
        self::assertSame([], SnapshotBuilder::build(new RemoteIndex(), [], $map, $customers, true, 99)['clients'], 'an unmapped client gets nothing');
    }
}
