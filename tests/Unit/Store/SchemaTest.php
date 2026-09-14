<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Store;

use Illuminate\Database\Capsule\Manager as Capsule;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;

final class SchemaTest extends DbTestCase
{
    public function testInstallIsIdempotentAndUpgradeFromNothingWorks(): void
    {
        Schema::install();
        Schema::install();
        foreach (Schema::TABLES as $table) {
            self::assertTrue(Capsule::schema()->hasTable($table), $table);
        }
        Schema::upgrade('0.0.0');
        Schema::upgrade('0.0.0');
        self::assertTrue(Capsule::schema()->hasTable('mod_uptimeify_queue'));
        Schema::drop();
        self::assertFalse(Capsule::schema()->hasTable('mod_uptimeify_queue'));
    }

    public function testUpgradeTwiceOnInstalledSchemaKeepsData(): void
    {
        Schema::install();
        Capsule::table('mod_uptimeify_status_cache')->insert(['key' => 'org', 'value' => '{}', 'refreshed_at' => '2026-09-13 12:00:00']);
        Schema::upgrade('1.0.0');
        Schema::upgrade('1.0.0');
        self::assertSame(1, Capsule::table('mod_uptimeify_status_cache')->count());
    }

    public function testClearDataEmptiesEveryModuleTableButKeepsTablesAndSettings(): void
    {
        Schema::install();
        Capsule::table('mod_uptimeify_status_cache')->insert(['key' => 'org', 'value' => '{}', 'refreshed_at' => '2026-09-13 12:00:00']);
        Capsule::table('mod_uptimeify_customer_map')->insert(['whmcs_client_id' => 1, 'customer_public_id' => 'c1', 'source' => 'manual', 'updated_at' => '2026-09-13 12:00:00']);
        Capsule::table('mod_uptimeify_detached')->insert(['monitor_type' => 'website', 'monitor_public_id' => 'w1', 'origin' => 'created', 'admin_paused' => false, 'updated_at' => '2026-09-13 12:00:00']);
        Capsule::table('tbladdonmodules')->insert(['module' => 'uptimeify', 'setting' => 'brand_name', 'value' => 'Acme']);

        Schema::clearData();

        foreach (Schema::TABLES as $table) {
            self::assertTrue(Capsule::schema()->hasTable($table), $table);
            self::assertSame(0, Capsule::table($table)->count(), $table);
        }
        self::assertSame(1, Capsule::table('tbladdonmodules')->count(), 'settings are not module data rows');
    }

    public function testClearDataIsAllOrNothing(): void
    {
        Schema::install();
        Capsule::table('mod_uptimeify_status_cache')->insert(['key' => 'org', 'value' => '{}', 'refreshed_at' => '2026-09-13 12:00:00']);
        Capsule::table('mod_uptimeify_customer_map')->insert(['whmcs_client_id' => 1, 'customer_public_id' => 'c1', 'source' => 'manual', 'updated_at' => '2026-09-13 12:00:00']);
        Capsule::schema()->drop('mod_uptimeify_detached'); // the last table fails

        try {
            Schema::clearData();
            self::fail('expected the failing table to stop clearData');
        } catch (\Illuminate\Database\QueryException) {
        }

        self::assertSame(1, Capsule::table('mod_uptimeify_customer_map')->count(), 'rolled back');
        self::assertSame(1, Capsule::table('mod_uptimeify_status_cache')->count(), 'rolled back');
    }

    public function testTablesUseModulePrefixAndDropIsSafeWhenNothingExists(): void
    {
        self::assertCount(5, Schema::TABLES);
        foreach (Schema::TABLES as $table) {
            self::assertStringStartsWith('mod_uptimeify_', $table);
        }
        Schema::drop();
        self::assertFalse(Capsule::schema()->hasTable('mod_uptimeify_customer_map'));
    }

    public function testColumnsMatchSpec(): void
    {
        Schema::install();
        $expected = [
            'mod_uptimeify_customer_map' => ['whmcs_client_id', 'customer_public_id', 'package_type', 'source', 'updated_at'],
            'mod_uptimeify_monitor_map' => ['kind', 'whmcs_ref_id', 'monitor_type', 'monitor_public_id', 'monitor_numeric_id', 'customer_public_id', 'target', 'state', 'last_error', 'origin', 'admin_paused', 'updated_at'],
            'mod_uptimeify_queue' => ['id', 'job', 'ref_id', 'attempts', 'status', 'next_run_at', 'last_error', 'created_at', 'finished_at'],
            'mod_uptimeify_status_cache' => ['key', 'value', 'refreshed_at'],
            'mod_uptimeify_detached' => ['monitor_type', 'monitor_public_id', 'origin', 'admin_paused', 'updated_at'],
        ];
        foreach ($expected as $table => $columns) {
            self::assertEqualsCanonicalizing($columns, Capsule::schema()->getColumnListing($table), $table);
        }
    }
}
