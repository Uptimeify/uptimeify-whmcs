<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Store;

use WHMCS\Database\Capsule;

/** JSON snapshots keyed by name (`org`, `client:<id>`, `last_reconcile`, ...), spec §6. */
final class StatusCache
{
    private const TABLE = 'mod_uptimeify_status_cache';

    /** @return array{value:array<mixed>,refreshed_at:string}|null */
    public function get(string $key): ?array
    {
        $row = Capsule::table(self::TABLE)->where('key', $key)->first();
        if ($row === null) {
            return null;
        }
        $decoded = json_decode((string) $row->value, true);
        return ['value' => is_array($decoded) ? $decoded : [], 'refreshed_at' => (string) $row->refreshed_at];
    }

    /** @return array<mixed>|null */
    public function value(string $key): ?array
    {
        return $this->get($key)['value'] ?? null;
    }

    /** @param array<mixed> $value */
    public function set(string $key, array $value): void
    {
        $data = ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'refreshed_at' => Schema::now()];
        if (Capsule::table(self::TABLE)->where('key', $key)->exists()) {
            Capsule::table(self::TABLE)->where('key', $key)->update($data);
        } else {
            Capsule::table(self::TABLE)->insert($data + ['key' => $key]);
        }
    }

    public function delete(string $key): void
    {
        Capsule::table(self::TABLE)->where('key', $key)->delete();
    }

    /**
     * A marker that opens a window of `$seconds`: true (and the window starts now) when no window is open for `$key`,
     * false while one is. Used to throttle client-area retries and log lines.
     */
    public function claim(string $key, int $seconds): bool
    {
        if ($this->isFresh($key, $seconds)) {
            return false;
        }
        $this->set($key, []);
        return true;
    }

    public function isFresh(string $key, int $ttlSeconds, ?int $now = null): bool
    {
        $entry = $this->get($key);
        if ($entry === null) {
            return false;
        }
        $refreshed = strtotime($entry['refreshed_at'] . ' UTC');
        return $refreshed !== false && ($now ?? time()) - $refreshed < $ttlSeconds;
    }
}
