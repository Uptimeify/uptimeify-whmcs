<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Store;

use WHMCS\Database\Capsule;

/**
 * What the addon knew about a remote monitor whose map row it detached (round 2, D1/D7): whether it created the monitor
 * and whether the admin paused it. Keyed by (monitor_type, monitor_public_id). Adopting the monitor again moves both
 * values back into the new map row and forgets the record; deleting the monitor forgets it too. Without it a
 * configuration toggle would turn a created monitor into an adopted one and lose an admin pause.
 */
final class DetachedMonitors
{
    private const TABLE = 'mod_uptimeify_detached';

    /**
     * Every record, loaded once per instance on first use ("type|publicId" => record): a reconcile asks for each aligned
     * row, and the table is written only through this class under the sync lock. reload() starts over (each run, each job).
     *
     * @var array<string,array{origin:string,admin_paused:bool}>|null
     */
    private ?array $records = null;

    public function reload(): void
    {
        $this->records = null;
    }

    /** Merges into an existing record: `created` and an admin pause are never downgraded. */
    public function remember(string $monitorType, string $publicId, string $origin, bool $adminPaused): void
    {
        $known = $this->find($monitorType, $publicId);
        $record = [
            'origin' => $origin === MonitorMap::ORIGIN_CREATED || ($known['origin'] ?? null) === MonitorMap::ORIGIN_CREATED ? MonitorMap::ORIGIN_CREATED : MonitorMap::ORIGIN_ADOPTED,
            'admin_paused' => $adminPaused || ($known['admin_paused'] ?? false),
        ];
        $data = $record + ['updated_at' => Schema::now()];
        if ($known === null) {
            Capsule::table(self::TABLE)->insert($data + ['monitor_type' => $monitorType, 'monitor_public_id' => $publicId]);
        } else {
            $this->key($monitorType, $publicId)->update($data);
        }
        $this->records[self::id($monitorType, $publicId)] = $record;
    }

    /** @return array{origin:string,admin_paused:bool}|null */
    public function find(string $monitorType, string $publicId): ?array
    {
        return $this->records()[self::id($monitorType, $publicId)] ?? null;
    }

    /** Deletes the record; nothing happens (no query) for a monitor without one. */
    public function forget(string $monitorType, string $publicId): void
    {
        if ($this->find($monitorType, $publicId) === null) {
            return;
        }
        $this->key($monitorType, $publicId)->delete();
        unset($this->records[self::id($monitorType, $publicId)]);
    }

    /** "Aktivieren" (round 2, D7): the admin pause a detached record keeps for the monitor goes too; the origin stays. */
    public function clearAdminPause(string $monitorType, string $publicId): void
    {
        $known = $this->find($monitorType, $publicId);
        if ($known === null || !$known['admin_paused']) {
            return;
        }
        $this->key($monitorType, $publicId)->update(['admin_paused' => false, 'updated_at' => Schema::now()]);
        $this->records[self::id($monitorType, $publicId)] = ['origin' => $known['origin'], 'admin_paused' => false];
    }

    /** @return array<string,array{origin:string,admin_paused:bool}> */
    private function records(): array
    {
        if ($this->records === null) {
            $this->records = [];
            foreach (Capsule::table(self::TABLE)->get() as $row) {
                $r = (array) $row;
                $this->records[self::id((string) $r['monitor_type'], (string) $r['monitor_public_id'])] = [
                    'origin' => $r['origin'] === MonitorMap::ORIGIN_CREATED ? MonitorMap::ORIGIN_CREATED : MonitorMap::ORIGIN_ADOPTED,
                    'admin_paused' => (bool) $r['admin_paused'],
                ];
            }
        }
        return $this->records;
    }

    private static function id(string $monitorType, string $publicId): string
    {
        return $monitorType . '|' . $publicId;
    }

    private function key(string $monitorType, string $publicId): \Illuminate\Database\Query\Builder
    {
        return Capsule::table(self::TABLE)->where('monitor_type', $monitorType)->where('monitor_public_id', $publicId);
    }
}
