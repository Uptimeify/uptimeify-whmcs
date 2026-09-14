<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Admin;

use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;

/**
 * Customers tab (spec §9.1): WHMCS client → Uptimeify customer with its `source`, Always/Never/Default per client, the
 * blacklist/whitelist mode and "Zuordnung neu setzen" (the action itself is DashboardController::setCustomer).
 * Remote customer names come from the `org_customers` status cache the reconcile writes; the page calls no API.
 */
final class CustomersController
{
    public const MODES = ['default', 'sync', 'skip'];

    /** Rows rendered at most; the search narrows the rest. */
    private const MAX_ROWS = 500;

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $get
     */
    public function show(array $ctx, array $get): string
    {
        $settings = Container::settings();
        $map = Container::customerMap()->all();
        $filter = $settings->customerFilter();
        $options = self::customerOptions();
        $names = array_column($options, 'name', 'publicId');
        $q = mb_strtolower(is_scalar($get['q'] ?? null) ? trim((string) $get['q']) : '', 'UTF-8');
        $rows = [];
        foreach ((new ClientRepository())->all() as $client) {
            if ($q !== '' && !str_contains(mb_strtolower($client->displayName . ' ' . $client->email . ' #' . $client->id, 'UTF-8'), $q)) {
                continue;
            }
            $mapping = $map[$client->id] ?? null;
            $rows[] = [
                'client' => $client,
                'customerPublicId' => $mapping['customer_public_id'] ?? null,
                'customerName' => $mapping === null ? null : ($names[$mapping['customer_public_id']] ?? null),
                'source' => $mapping['source'] ?? null,
                'mode' => $filter[$client->id] ?? 'default',
            ];
        }
        $vars = $ctx + [
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
            'totalRows' => count($rows),
            'q' => $q,
            'filterMode' => $settings->filterMode(),
            'customerOptions' => $options,
        ];
        return View::render('layout', $vars + ['content' => View::render('customers', $vars)]);
    }

    /** @param array<string,mixed> $post */
    public function saveFilter(array $post): void
    {
        $mode = $post['filter_mode'] ?? null;
        if (!in_array($mode, ['blacklist', 'whitelist'], true)) {
            throw new \InvalidArgumentException(View::t('error.unknown_mode'));
        }
        Container::settings()->set('filter_mode', $mode);
        Flash::set('success', View::t('customers.filter_saved'));
    }

    public function setClientMode(int $clientId, string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(View::t('error.unknown_mode'));
        }
        if ($clientId <= 0 || (new ClientRepository())->find($clientId) === null) {
            throw new \InvalidArgumentException(View::t('error.unknown_client', ['id' => $clientId]));
        }
        $settings = Container::settings();
        $filter = $settings->customerFilter();
        if ($mode === 'default') {
            unset($filter[$clientId]);
        } else {
            $filter[$clientId] = $mode;
        }
        $settings->setCustomerFilter($filter);
        Flash::set('success', View::t('customers.mode_saved', ['id' => $clientId]));
    }

    /**
     * The remote customers as the last reconcile or worker run cached them, for the customer pickers.
     *
     * @return list<array{publicId:string,name:string,email:string}>
     */
    public static function customerOptions(): array
    {
        $out = [];
        foreach (Container::statusCache()->value('org_customers') ?? [] as $customer) {
            if (is_array($customer) && is_string($customer['publicId'] ?? null) && $customer['publicId'] !== '') {
                $out[] = [
                    'publicId' => $customer['publicId'],
                    'name' => is_string($customer['name'] ?? null) ? $customer['name'] : '',
                    'email' => is_string($customer['email'] ?? null) ? $customer['email'] : '',
                ];
            }
        }
        return $out;
    }
}
