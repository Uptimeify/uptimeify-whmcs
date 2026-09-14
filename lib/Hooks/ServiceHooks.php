<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Hooks;

use WHMCS\Module\Addon\Uptimeify\Sync\JobHandler;

/**
 * Service hooks (spec §7). The AfterModule* hooks pass the provisioning module parameters as `params`
 * (`params.serviceid` = tblhosting.id); ServiceEdit and ServiceDelete pass `serviceid` at the top level. Each also
 * accepts the other shape.
 */
final class ServiceHooks
{
    /** @param array<string,mixed> $vars */
    public static function onAfterModuleCreate(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_HOSTING_ACTIVATE, self::moduleServiceId($vars));
    }

    /** @param array<string,mixed> $vars */
    public static function onAfterModuleUnsuspend(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_HOSTING_ACTIVATE, self::moduleServiceId($vars));
    }

    /** @param array<string,mixed> $vars */
    public static function onAfterModuleSuspend(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_HOSTING_PAUSE, self::moduleServiceId($vars));
    }

    /** @param array<string,mixed> $vars */
    public static function onAfterModuleTerminate(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_HOSTING_REMOVE, self::moduleServiceId($vars));
    }

    /**
     * ServiceEdit ("after the changes have been made"; `userid`, `serviceid`, developers.whmcs.com hooks-reference/service):
     * any admin edit of a service, e.g. its domain, status or server. `hosting.activate` reconciles the hosting against
     * its live state, so the same job fits a changed domain (update in place), a status set by hand (pause, resume, end)
     * and a server move. PreServiceEdit is not used: it runs before the change is saved.
     *
     * @param array<string,mixed> $vars
     */
    public static function onServiceEdit(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_HOSTING_ACTIVATE, Enqueue::id($vars, ['serviceid'], ['params', 'serviceid']));
    }

    /** @param array<string,mixed> $vars */
    public static function onServiceDelete(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_HOSTING_REMOVE, Enqueue::id($vars, ['serviceid'], ['params', 'serviceid']));
    }

    /** @param array<string,mixed> $vars */
    private static function moduleServiceId(array $vars): int
    {
        return Enqueue::id($vars, ['params', 'serviceid'], ['serviceid']);
    }
}
