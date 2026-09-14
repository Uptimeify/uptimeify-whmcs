<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Store;

use Illuminate\Database\Query\Builder;
use WHMCS\Database\Capsule;

/**
 * One row per (kind, whmcs_ref_id, monitor_type): which Uptimeify monitor belongs to which WHMCS object (spec §6).
 * Row shape: kind, whmcs_ref_id:int, monitor_type, monitor_public_id:?string, monitor_numeric_id:?int,
 * customer_public_id:?string, target, state, last_error:?string, origin, admin_paused:bool, updated_at.
 *
 * `origin` says whether the addon created the remote monitor (`created`) or took over an existing one (`adopted`, also
 * the default for a row without that information): WHMCS end events only delete `created` monitors. `admin_paused` is
 * the admin's persistent "Pausieren": reconcile and jobs never resume such a monitor.
 */
final class MonitorMap
{
    public const KIND_HOSTING = 'hosting';
    public const KIND_DOMAIN = 'domain';
    public const KIND_SERVER_IP = 'server_ip';

    public const TYPE_WEBSITE = 'website';
    public const TYPE_CUSTOMER_DOMAIN = 'customer_domain';
    public const TYPE_DNS = 'dns';
    public const TYPE_CUSTOMER_IP = 'customer_ip';

    public const STATE_ACTIVE = 'active';
    public const STATE_PAUSED = 'paused';
    public const STATE_ERROR = 'error';
    public const STATE_ORPHANED = 'orphaned';

    public const ORIGIN_CREATED = 'created';
    public const ORIGIN_ADOPTED = 'adopted';

    private const TABLE = 'mod_uptimeify_monitor_map';

    /** @return array<string,mixed>|null */
    public function find(string $kind, int $refId, string $monitorType): ?array
    {
        $row = $this->key($kind, $refId, $monitorType)->first();
        return $row === null ? null : $this->cast((array) $row);
    }

    /**
     * Inserts or replaces the row for (kind, whmcs_ref_id, monitor_type); omitted optional keys become null. `origin` and
     * `admin_paused` are the exception: omitted, an existing row keeps them and a new row gets `adopted` / false.
     *
     * @param array<string,mixed> $row
     */
    public function upsert(array $row): void
    {
        $data = [
            'monitor_public_id' => $row['monitor_public_id'] ?? null,
            'monitor_numeric_id' => isset($row['monitor_numeric_id']) ? (int) $row['monitor_numeric_id'] : null,
            'customer_public_id' => $row['customer_public_id'] ?? null,
            'target' => (string) $row['target'],
            'state' => (string) $row['state'],
            'last_error' => $row['last_error'] ?? null,
            'updated_at' => Schema::now(),
        ];
        if (isset($row['origin'])) {
            $data['origin'] = $row['origin'] === self::ORIGIN_CREATED ? self::ORIGIN_CREATED : self::ORIGIN_ADOPTED;
        }
        if (isset($row['admin_paused'])) {
            $data['admin_paused'] = (bool) $row['admin_paused'];
        }
        $kind = (string) $row['kind'];
        $refId = (int) $row['whmcs_ref_id'];
        $type = (string) $row['monitor_type'];
        if ($this->key($kind, $refId, $type)->exists()) {
            $this->key($kind, $refId, $type)->update($data);
        } else {
            Capsule::table(self::TABLE)->insert($data + ['kind' => $kind, 'whmcs_ref_id' => $refId, 'monitor_type' => $type, 'origin' => self::ORIGIN_ADOPTED, 'admin_paused' => false]);
        }
    }

    /** Sets or clears the admin's persistent pause on every row of that monitor type sharing the remote monitor. */
    public function setAdminPaused(string $monitorType, string $publicId, bool $paused): void
    {
        Capsule::table(self::TABLE)->where('monitor_type', $monitorType)->where('monitor_public_id', $publicId)
            ->update(['admin_paused' => $paused, 'updated_at' => Schema::now()]);
    }

    public function setState(string $kind, int $refId, string $monitorType, string $state, ?string $error = null): void
    {
        $this->key($kind, $refId, $monitorType)->update(['state' => $state, 'last_error' => $error, 'updated_at' => Schema::now()]);
    }

    public function remove(string $kind, int $refId, string $monitorType): void
    {
        $this->key($kind, $refId, $monitorType)->delete();
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->rows(Capsule::table(self::TABLE)->orderBy('target')->orderBy('kind')->orderBy('whmcs_ref_id')->orderBy('monitor_type')->get()->all());
    }

    /** @return list<array<string,mixed>> */
    public function forKindRef(string $kind, int $refId): array
    {
        return $this->rows(Capsule::table(self::TABLE)->where('kind', $kind)->where('whmcs_ref_id', $refId)->orderBy('monitor_type')->get()->all());
    }

    /** @return list<array<string,mixed>> */
    public function forCustomer(string $customerPublicId): array
    {
        return $this->rows(Capsule::table(self::TABLE)->where('customer_public_id', $customerPublicId)->orderBy('target')->get()->all());
    }

    /** @return array<string,mixed>|null */
    public function byMonitorPublicId(string $publicId): ?array
    {
        $row = Capsule::table(self::TABLE)->where('monitor_public_id', $publicId)->first();
        return $row === null ? null : $this->cast((array) $row);
    }

    /**
     * Every row that references the remote monitor. Several WHMCS objects can share one monitor (two hostings on
     * the same host, a hosting and a registrar domain with the same name), so a monitor may only be paused or
     * deleted when no other row still needs it.
     *
     * @return list<array<string,mixed>> ordered by kind, ref id, type
     */
    public function forMonitorPublicId(string $publicId): array
    {
        return $this->rows(Capsule::table(self::TABLE)->where('monitor_public_id', $publicId)->orderBy('kind')->orderBy('whmcs_ref_id')->orderBy('monitor_type')->get()->all());
    }

    /** @return array<string,int> state => row count, sorted by state */
    public function countByState(): array
    {
        $out = [];
        $rows = Capsule::table(self::TABLE)->select('state')->selectRaw('COUNT(*) AS n')->groupBy('state')->get();
        foreach ($rows as $row) {
            $r = (array) $row;
            $out[(string) $r['state']] = (int) $r['n'];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,int> last_error => count of rows in state `error`, sorted by code */
    public function errorCounts(): array
    {
        $out = [];
        $rows = Capsule::table(self::TABLE)->select('last_error')->selectRaw('COUNT(*) AS n')->where('state', self::STATE_ERROR)
            ->whereNotNull('last_error')->groupBy('last_error')->get();
        foreach ($rows as $row) {
            $r = (array) $row;
            $out[(string) $r['last_error']] = (int) $r['n'];
        }
        ksort($out);
        return $out;
    }

    private function key(string $kind, int $refId, string $monitorType): Builder
    {
        return Capsule::table(self::TABLE)->where('kind', $kind)->where('whmcs_ref_id', $refId)->where('monitor_type', $monitorType);
    }

    /**
     * @param array<int,object> $rows
     * @return list<array<string,mixed>>
     */
    private function rows(array $rows): array
    {
        return array_values(array_map(fn (object $r): array => $this->cast((array) $r), $rows));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function cast(array $row): array
    {
        $row['whmcs_ref_id'] = (int) $row['whmcs_ref_id'];
        $row['monitor_numeric_id'] = $row['monitor_numeric_id'] === null ? null : (int) $row['monitor_numeric_id'];
        $row['origin'] = ($row['origin'] ?? null) === self::ORIGIN_CREATED ? self::ORIGIN_CREATED : self::ORIGIN_ADOPTED;
        $row['admin_paused'] = (bool) ($row['admin_paused'] ?? false);
        return $row;
    }
}
