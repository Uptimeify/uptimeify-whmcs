<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Hooks;

use WHMCS\Module\Addon\Uptimeify\Sync\JobHandler;

/**
 * Domain hooks (spec §7). AfterRegistrar* pass the registrar module parameters as `params` (`params.domainid` =
 * tbldomains.id), DomainTransferCompleted passes `domainId` (camel case), DomainEdit and DomainDelete `domainid`. Each
 * also accepts the other spellings.
 */
final class DomainHooks
{
    /** @param array<string,mixed> $vars */
    public static function onAfterRegistrarRegistration(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_DOMAIN_UPSERT, self::registrarDomainId($vars));
    }

    /** @param array<string,mixed> $vars */
    public static function onAfterRegistrarTransfer(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_DOMAIN_UPSERT, self::registrarDomainId($vars));
    }

    /** @param array<string,mixed> $vars */
    public static function onDomainTransferCompleted(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_DOMAIN_UPSERT, Enqueue::id($vars, ['domainId'], ['domainid'], ['params', 'domainid']));
    }

    /**
     * DomainEdit ("when the domain is being edited via the Client Profile Summary and Domains tabs in the Admin Area";
     * `userid`, `domainid`, developers.whmcs.com hooks-reference/domain). `domain.upsert` reconciles the domain against its
     * live state, so a status set by hand ends or restarts its monitors as well.
     *
     * @param array<string,mixed> $vars
     */
    public static function onDomainEdit(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_DOMAIN_UPSERT, Enqueue::id($vars, ['domainid'], ['domainId'], ['params', 'domainid']));
    }

    /** @param array<string,mixed> $vars */
    public static function onDomainDelete(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_DOMAIN_REMOVE, Enqueue::id($vars, ['domainid'], ['domainId'], ['params', 'domainid']));
    }

    /** @param array<string,mixed> $vars */
    private static function registrarDomainId(array $vars): int
    {
        return Enqueue::id($vars, ['params', 'domainid'], ['domainid'], ['domainId']);
    }
}
