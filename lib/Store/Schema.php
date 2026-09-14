<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Store;

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

/**
 * The addon's own tables (spec §6). Every later schema change is a numbered, idempotent step in upgrade()
 * keyed by the module version that introduces it, and bumps VERSION (spec §3).
 *
 * All timestamps are UTC strings 'Y-m-d H:i:s' written by PHP, never SQL NOW(), so they do not depend on the
 * database server's time zone.
 */
final class Schema
{
    public const VERSION = 1;

    public const TABLES = ['mod_uptimeify_customer_map', 'mod_uptimeify_monitor_map', 'mod_uptimeify_queue', 'mod_uptimeify_status_cache', 'mod_uptimeify_detached'];

    public static function install(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('mod_uptimeify_customer_map')) {
            $schema->create('mod_uptimeify_customer_map', function (Blueprint $t): void {
                $t->integer('whmcs_client_id')->primary();
                $t->string('customer_public_id', 64);
                $t->string('package_type', 128)->nullable();
                $t->string('source', 32);
                $t->dateTime('updated_at');
                $t->index('customer_public_id');
            });
        }
        if (!$schema->hasTable('mod_uptimeify_monitor_map')) {
            $schema->create('mod_uptimeify_monitor_map', function (Blueprint $t): void {
                $t->string('kind', 16);
                // BIGINT: server-IP rows use the composite serverId * 10_000_000_000 + clientId (MonitorPlanner::serverIpRefId).
                $t->bigInteger('whmcs_ref_id');
                $t->string('monitor_type', 24);
                $t->string('monitor_public_id', 64)->nullable();
                $t->bigInteger('monitor_numeric_id')->nullable();
                $t->string('customer_public_id', 64)->nullable();
                $t->string('target', 255);
                $t->string('state', 16);
                $t->text('last_error')->nullable();
                // `created` when the addon POSTed the monitor, `adopted` when it took over an existing one (round 2, D1).
                $t->string('origin', 16)->default(MonitorMap::ORIGIN_ADOPTED);
                // An admin's "Pausieren" that reconcile and jobs keep until "Aktivieren" (round 2, D7).
                $t->boolean('admin_paused')->default(false);
                $t->dateTime('updated_at');
                $t->primary(['kind', 'whmcs_ref_id', 'monitor_type']);
                $t->index('monitor_public_id');
                $t->index('customer_public_id');
            });
        }
        if (!$schema->hasTable('mod_uptimeify_queue')) {
            $schema->create('mod_uptimeify_queue', function (Blueprint $t): void {
                $t->increments('id');
                $t->string('job', 32);
                $t->bigInteger('ref_id');
                $t->integer('attempts')->default(0);
                $t->string('status', 16)->default(Queue::STATUS_PENDING);
                $t->dateTime('next_run_at');
                $t->text('last_error')->nullable();
                $t->dateTime('created_at');
                $t->dateTime('finished_at')->nullable();
                $t->index(['status', 'next_run_at']);
            });
        }
        if (!$schema->hasTable('mod_uptimeify_status_cache')) {
            $schema->create('mod_uptimeify_status_cache', function (Blueprint $t): void {
                $t->string('key', 64)->primary();
                // MEDIUMTEXT: a client or org snapshot with many monitors and incidents outgrows TEXT (64 KB).
                $t->mediumText('value');
                $t->dateTime('refreshed_at');
            });
        }
        if (!$schema->hasTable('mod_uptimeify_detached')) {
            // Origin and admin pause of monitors whose map row was detached (round 2, D1/D7; DetachedMonitors).
            $schema->create('mod_uptimeify_detached', function (Blueprint $t): void {
                $t->string('monitor_type', 24);
                $t->string('monitor_public_id', 64);
                $t->string('origin', 16);
                $t->boolean('admin_paused')->default(false);
                $t->dateTime('updated_at');
                $t->primary(['monitor_type', 'monitor_public_id']);
            });
        }
    }

    /**
     * Runs every step whose introducing version is greater than $fromVersion (the previously installed module
     * version WHMCS passes to _upgrade). Each step must be idempotent, so running upgrade twice is safe.
     */
    public static function upgrade(string $fromVersion): void
    {
        self::install();
        // Future steps, each guarded so a second run is a no-op, e.g.:
        // if (version_compare($fromVersion, '1.1.0', '<') && !Capsule::schema()->hasColumn('mod_uptimeify_queue', 'x')) {
        //     Capsule::schema()->table('mod_uptimeify_queue', fn (Blueprint $t) => $t->string('x')->nullable());
        // }
    }

    /**
     * Deletes every row of the module tables and keeps the tables ("Organisation wechseln", round 2 D9): mappings, monitor
     * rows, queue, status cache and detached records all belong to the previous organization. One transaction: a failure
     * leaves every table as it was. Settings are not touched.
     */
    public static function clearData(): void
    {
        Capsule::connection()->transaction(static function (): void {
            foreach (self::TABLES as $table) {
                Capsule::table($table)->delete();
            }
        });
    }

    public static function drop(): void
    {
        foreach (self::TABLES as $table) {
            Capsule::schema()->dropIfExists($table);
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
