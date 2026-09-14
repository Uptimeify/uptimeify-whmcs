<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Store;

use WHMCS\Database\Capsule;

/** WHMCS client id → Uptimeify customer public id (spec §6, §8.2). */
final class CustomerMap
{
    private const TABLE = 'mod_uptimeify_customer_map';

    /** @return array{whmcs_client_id:int,customer_public_id:string,package_type:?string,source:string,updated_at:string}|null */
    public function get(int $clientId): ?array
    {
        $row = Capsule::table(self::TABLE)->where('whmcs_client_id', $clientId)->first();
        return $row === null ? null : $this->cast((array) $row);
    }

    public function set(int $clientId, string $customerPublicId, ?string $packageType, string $source): void
    {
        $data = ['customer_public_id' => $customerPublicId, 'package_type' => $packageType, 'source' => $source, 'updated_at' => Schema::now()];
        if (Capsule::table(self::TABLE)->where('whmcs_client_id', $clientId)->exists()) {
            Capsule::table(self::TABLE)->where('whmcs_client_id', $clientId)->update($data);
        } else {
            Capsule::table(self::TABLE)->insert($data + ['whmcs_client_id' => $clientId]);
        }
    }

    public function remove(int $clientId): void
    {
        Capsule::table(self::TABLE)->where('whmcs_client_id', $clientId)->delete();
    }

    /** @return array<int,array{whmcs_client_id:int,customer_public_id:string,package_type:?string,source:string,updated_at:string}> */
    public function all(): array
    {
        $out = [];
        foreach (Capsule::table(self::TABLE)->orderBy('whmcs_client_id')->get() as $row) {
            $r = $this->cast((array) $row);
            $out[$r['whmcs_client_id']] = $r;
        }
        return $out;
    }

    /** Lowest client id if several clients were mapped to the same customer. */
    public function clientIdFor(string $customerPublicId): ?int
    {
        $v = Capsule::table(self::TABLE)->where('customer_public_id', $customerPublicId)->orderBy('whmcs_client_id')->value('whmcs_client_id');
        return $v === null ? null : (int) $v;
    }

    /** @return list<int> every client id mapped to the customer, ascending */
    public function clientIdsFor(string $customerPublicId): array
    {
        $ids = Capsule::table(self::TABLE)->where('customer_public_id', $customerPublicId)->orderBy('whmcs_client_id')->pluck('whmcs_client_id')->all();
        return array_values(array_map(fn (mixed $id): int => (int) $id, $ids));
    }

    /**
     * @param array<string,mixed> $row
     * @return array{whmcs_client_id:int,customer_public_id:string,package_type:?string,source:string,updated_at:string}
     */
    private function cast(array $row): array
    {
        return [
            'whmcs_client_id' => (int) $row['whmcs_client_id'],
            'customer_public_id' => (string) $row['customer_public_id'],
            'package_type' => $row['package_type'] === null ? null : (string) $row['package_type'],
            'source' => (string) $row['source'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
