<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Hooks;

use WHMCS\Module\Addon\Uptimeify\Sync\JobHandler;

/**
 * Client hooks (spec §7, §8.5). WHMCS passes the client id as `userid`; ClientAdd also as `client_id`. ClientAdd's
 * `user_id` is the owning user account, not the client, and is never read.
 */
final class ClientHooks
{
    /** @param array<string,mixed> $vars */
    public static function onClientAdd(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_CLIENT_UPSERT, Enqueue::id($vars, ['client_id'], ['userid']));
    }

    /** @param array<string,mixed> $vars */
    public static function onClientEdit(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_CLIENT_UPSERT, self::clientId($vars));
    }

    /** @param array<string,mixed> $vars */
    public static function onClientClose(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_CLIENT_PAUSE_ALL, self::clientId($vars));
    }

    /**
     * Spec §8.5 "Client gelöscht": all monitors of the client end like Terminated.
     *
     * @param array<string,mixed> $vars
     */
    public static function onClientDelete(array $vars): void
    {
        Enqueue::job(JobHandler::JOB_CLIENT_REMOVE, self::clientId($vars));
    }

    /** @param array<string,mixed> $vars */
    private static function clientId(array $vars): int
    {
        return Enqueue::id($vars, ['userid'], ['client_id']);
    }
}
