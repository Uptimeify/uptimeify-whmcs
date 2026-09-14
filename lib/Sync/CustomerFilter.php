<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Settings;

/**
 * Applies filter_mode, customer_filter and ignored_targets before the target set is built (spec §8.1).
 *
 * An explicit per-client 'sync' or 'skip' always wins; otherwise blacklist mode allows and whitelist mode denies.
 */
final class CustomerFilter
{
    /** @var array<int,string> */
    private array $filter;

    private string $mode;

    /** @var array<string,true> normalized host => true */
    private array $ignored = [];

    public function __construct(Settings $settings)
    {
        $this->filter = $settings->customerFilter();
        $this->mode = $settings->filterMode();
        foreach ($settings->ignoredTargets() as $target) {
            $host = TargetNormalizer::host($target);
            if ($host !== '') {
                $this->ignored[$host] = true;
            }
        }
    }

    public function allows(int $clientId): bool
    {
        $explicit = $this->filter[$clientId] ?? null;
        if ($explicit === 'skip') {
            return false;
        }
        if ($explicit === 'sync') {
            return true;
        }
        return $this->mode === 'blacklist';
    }

    public function isIgnoredTarget(string $target): bool
    {
        $host = TargetNormalizer::host($target);
        return $host !== '' && isset($this->ignored[$host]);
    }
}
