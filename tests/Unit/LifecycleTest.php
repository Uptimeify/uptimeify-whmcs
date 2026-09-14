<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit;

use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;

final class LifecycleTest extends DbTestCase
{
    protected function tearDown(): void
    {
        Container::reset();
        $_SESSION = [];
        parent::tearDown();
    }

    public function testActivateCreatesTablesAndDeactivateKeepsThemByDefault(): void
    {
        $r = uptimeify_activate();
        self::assertSame('success', $r['status']);
        self::assertNotSame('', $r['description']);
        foreach (Schema::TABLES as $table) {
            self::assertTrue(Capsule::schema()->hasTable($table), $table);
        }
        (new Settings())->setApiToken('wsm_test-token');
        Capsule::table('mod_uptimeify_queue')->insert(['job' => 'client.upsert', 'ref_id' => 1, 'next_run_at' => '2026-09-14 00:00:00', 'created_at' => '2026-09-14 00:00:00']);

        $r = uptimeify_deactivate();
        self::assertSame('success', $r['status']);
        self::assertTrue(Capsule::schema()->hasTable('mod_uptimeify_queue'));
        self::assertSame(1, Capsule::table('mod_uptimeify_queue')->count(), 'data is kept');
        self::assertTrue((new Settings())->hasApiToken(), 'settings are kept');
    }

    public function testActivateTwiceIsHarmless(): void
    {
        self::assertSame('success', uptimeify_activate()['status']);
        self::assertSame('success', uptimeify_activate()['status']);
    }

    public function testDeactivateDropsWhenConfigured(): void
    {
        uptimeify_activate();
        $this->insert('tbladdonmodules', ['module' => 'other', 'setting' => 'x', 'value' => '1']);
        (new Settings())->setApiToken('wsm_test-token');
        (new Settings())->setBool('drop_data_on_deactivate', true);

        $r = uptimeify_deactivate();

        self::assertSame('success', $r['status']);
        foreach (Schema::TABLES as $table) {
            self::assertFalse(Capsule::schema()->hasTable($table), $table);
        }
        self::assertSame(0, Capsule::table('tbladdonmodules')->where('module', 'uptimeify')->count(), 'settings are wiped too');
        self::assertSame(1, Capsule::table('tbladdonmodules')->where('module', 'other')->count(), 'other modules untouched');
        self::assertFalse(Container::settings()->dropDataOnDeactivate(), 'no stale shared settings after the wipe');
    }

    public function testDeactivateReportsErrorsInWhmcsShape(): void
    {
        Capsule::schema()->drop('tbladdonmodules');
        $r = uptimeify_deactivate();
        self::assertSame('error', $r['status']);
        self::assertNotSame('', $r['description']);
    }

    public function testActivateReportsErrorsInWhmcsShape(): void
    {
        Capsule::connection()->getPdo()->exec('CREATE VIEW mod_uptimeify_customer_map AS SELECT 1 AS x');
        $r = uptimeify_activate();
        self::assertSame('error', $r['status']);
        self::assertNotSame('', $r['description']);
    }

    public function testMessagesFollowTheAdminLanguage(): void
    {
        $_SESSION['adminlang'] = 'german';
        self::assertStringContainsString('aktiviert', uptimeify_activate()['description']);
        self::assertStringContainsString('deaktiviert', uptimeify_deactivate()['description']);
    }

    public function testUpgradeIsIdempotent(): void
    {
        uptimeify_upgrade(['version' => '0.0.1']);
        uptimeify_upgrade(['version' => '0.0.1']);
        foreach (Schema::TABLES as $table) {
            self::assertTrue(Capsule::schema()->hasTable($table), $table);
        }
    }

    public function testUpgradeKeepsDataAndToleratesMissingVersion(): void
    {
        uptimeify_activate();
        Capsule::table('mod_uptimeify_status_cache')->insert(['key' => 'org', 'value' => '{}', 'refreshed_at' => '2026-09-14 00:00:00']);
        uptimeify_upgrade([]);
        uptimeify_upgrade(['version' => '0.1.0']);
        self::assertSame(1, Capsule::table('mod_uptimeify_status_cache')->count());
    }
}
